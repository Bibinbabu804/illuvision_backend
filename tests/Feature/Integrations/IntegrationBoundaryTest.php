<?php

namespace Tests\Feature\Integrations;

use App\Helpers\MediaHelper;
use App\Infrastructure\Media\Contracts\ImageProcessingContractInterface;
use App\Infrastructure\Storage\Contracts\StorageContractInterface;
use App\Infrastructure\Media\GdImageProcessor;
use App\Infrastructure\Storage\LaravelStorageAdapter;
use App\Infrastructure\Notifications\Contracts\MailContractInterface;
use App\Infrastructure\Notifications\LaravelMailAdapter;
use App\Mail\PasswordResetMail;
use App\Models\NotificationLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\MediaService;
use App\Services\NotificationService;
use App\Services\ProfileService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Phase 0.12 — external integration boundary regression coverage.
 *
 * These tests assert two things:
 *  1. The extracted ports are bound and are actually used by the services that
 *     previously reached for the Mail / Storage / GD facades directly.
 *  2. Notification recipient resolution, dedup, queue-vs-send selection and audit
 *     logging still behave exactly as before the extraction.
 */
class IntegrationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Container bindings
    |--------------------------------------------------------------------------
    */

    public function test_the_notification_and_media_ports_are_bound(): void
    {
        $this->assertInstanceOf(LaravelMailAdapter::class, app(MailContractInterface::class));
        $this->assertInstanceOf(LaravelStorageAdapter::class, app(StorageContractInterface::class));
        $this->assertInstanceOf(GdImageProcessor::class, app(ImageProcessingContractInterface::class));
    }

    /*
    |--------------------------------------------------------------------------
    | NotificationService now depends on the mail port
    |--------------------------------------------------------------------------
    */

    public function test_notification_service_dispatches_through_the_mail_port(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        config()->set('mail.default', 'smtp');

        Mail::shouldReceive('to')->never();

        $this->mock(MailContractInterface::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('send')
                ->once()
                ->withArgs(function (string $email, ?string $name, $mailable) use ($user) {
                    return $email === $user->email
                        && $name === $user->name
                        && $mailable instanceof ImmediateTestMail;
                });
            $mock->shouldReceive('queue')->never();
        });

        app(NotificationService::class)->send(
            'booking_created',
            ['user' => $user, 'admin' => false, 'partner' => null],
            ImmediateTestMail::class,
            [$user],
        );

        $this->assertDatabaseHas('notification_logs', [
            'event' => 'booking_created',
            'recipient_email' => $user->email,
            'channel' => 'email',
            'status' => 'sent',
        ]);
    }

    public function test_notification_service_queues_mailables_that_implement_should_queue(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        config()->set('mail.default', 'smtp');

        $queued = null;
        $sent = 0;

        $this->mock(MailContractInterface::class, function (MockInterface $mock) use (&$queued, &$sent) {
            $mock->shouldReceive('queue')
                ->once()
                ->andReturnUsing(function (string $email, ?string $name, $mailable) use (&$queued) {
                    $queued = $mailable;
                });
            $mock->shouldReceive('send')
                ->zeroOrMoreTimes()
                ->andReturnUsing(function () use (&$sent) {
                    $sent++;
                });
        });

        app(NotificationService::class)->send(
            'booking_created',
            ['user' => $user, 'admin' => false, 'partner' => null],
            QueuedTestMail::class,
            [$user],
        );

        $this->assertInstanceOf(QueuedTestMail::class, $queued);
        $this->assertSame(0, $sent);
    }

    public function test_notification_service_deduplicates_recipients_by_email(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'email' => 'dupe@example.com',
        ]);

        config()->set('mail.default', 'smtp');

        // The same address is both a configured admin contact and the booking
        // user, so exactly one send must happen. Admin addresses come from the
        // settings table (not config()), so the row has to be seeded and the
        // settings cache dropped.
        $this->seedSetting('notifications', 'admin_emails', json_encode(['dupe@example.com']));
        $this->seedSetting('notifications', 'email_notifications', '1');

        $calls = [];

        $this->mock(MailContractInterface::class, function (MockInterface $mock) use (&$calls) {
            $mock->shouldReceive('send')->andReturnUsing(function ($email) use (&$calls) {
                $calls[] = $email;
            });
            $mock->shouldReceive('queue')->andReturnUsing(function ($email) use (&$calls) {
                $calls[] = $email;
            });
        });

        app(NotificationService::class)->send(
            'booking_created',
            ['user' => $user, 'admin' => true, 'partner' => null],
            ImmediateTestMail::class,
            [$user],
        );

        $this->assertSame(['dupe@example.com'], array_values($calls));
    }

    public function test_notification_service_sends_one_message_per_distinct_recipient(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'email' => 'buyer@example.com',
        ]);

        config()->set('mail.default', 'smtp');

        $this->seedSetting('notifications', 'admin_emails', json_encode(['ops@example.com', 'ops@example.com']));
        $this->seedSetting('notifications', 'email_notifications', '1');

        $calls = [];

        $this->mock(MailContractInterface::class, function (MockInterface $mock) use (&$calls) {
            $mock->shouldReceive('send')->andReturnUsing(function ($email) use (&$calls) {
                $calls[] = $email;
            });
            $mock->shouldReceive('queue')->andReturnUsing(function ($email) use (&$calls) {
                $calls[] = $email;
            });
        });

        app(NotificationService::class)->send(
            'booking_created',
            ['user' => $user, 'admin' => true, 'partner' => null],
            ImmediateTestMail::class,
            [$user],
        );

        // The repeated admin address collapses to one; the buyer is separate.
        sort($calls);
        $this->assertSame(['buyer@example.com', 'ops@example.com'], $calls);
    }

    /**
     * Seed a settings row and drop the settings cache so it takes effect.
     */
    protected function seedSetting(string $group, string $key, string $value): void
    {
        Setting::updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value],
        );

        app(SettingsService::class)->clearCache();
    }

    public function test_notification_service_skips_entirely_when_the_driver_cannot_deliver(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        // The `log` and `array` drivers do not really send, so the service skips
        // the email channel entirely. This must not call the port at all.
        config()->set('mail.default', 'log');

        $this->mock(MailContractInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('send')->never();
            $mock->shouldReceive('queue')->never();
        });

        app(NotificationService::class)->send(
            'booking_created',
            ['user' => $user, 'admin' => false, 'partner' => null],
            ImmediateTestMail::class,
            [$user],
        );

        $this->assertSame(0, NotificationLog::query()->count());
    }

    public function test_notification_service_records_a_failure_without_rethrowing(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        config()->set('mail.default', 'smtp');

        $this->mock(MailContractInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));
        });

        app(NotificationService::class)->send(
            'booking_created',
            ['user' => $user, 'admin' => false, 'partner' => null],
            ImmediateTestMail::class,
            [$user],
        );

        $this->assertDatabaseHas('notification_logs', [
            'recipient_email' => $user->email,
            'status' => 'failed',
            'failed_reason' => 'smtp down',
        ]);
    }

    public function test_notification_service_rejects_an_unknown_mailable_class(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        config()->set('mail.default', 'smtp');

        $this->mock(MailContractInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('send')->never();
            $mock->shouldReceive('queue')->never();
        });

        app(NotificationService::class)->send(
            'booking_created',
            ['user' => $user, 'admin' => false, 'partner' => null],
            'App\\Mail\\DoesNotExist',
            [],
        );

        $this->assertDatabaseHas('notification_logs', [
            'recipient_email' => $user->email,
            'status' => 'failed',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ForgotPasswordController
    |--------------------------------------------------------------------------
    */

    public function test_forgot_password_dispatches_through_the_mail_port(): void
    {
        $user = User::factory()->create([
            'status' => 'active',
            'email' => 'reset@example.com',
        ]);

        config()->set('mail.default', 'smtp');

        $this->mock(MailContractInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('queue')
                ->once()
                ->withArgs(fn (string $email, ?string $name, $mailable) => $email === 'reset@example.com'
                    && $name === null
                    && $mailable instanceof PasswordResetMail);
            $mock->shouldReceive('send')->never();
        });

        $this->postJson('/api/forgot-password', ['email' => 'reset@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'If the account exists, a password reset link has been sent.');
    }

    public function test_forgot_password_does_not_dispatch_for_an_unknown_email(): void
    {
        config()->set('mail.default', 'smtp');

        $this->mock(MailContractInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('queue')->never();
            $mock->shouldReceive('send')->never();
        });

        $this->postJson('/api/forgot-password', ['email' => 'nobody@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('message', 'If the account exists, a password reset link has been sent.');
    }

    /*
    |--------------------------------------------------------------------------
    | MediaService / ProfileService use the storage port
    |--------------------------------------------------------------------------
    */

    public function test_media_service_uploads_through_the_storage_port(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('poster.jpg', 12, 'image/jpeg');

        $this->mock(StorageContractInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('storeAs')
                ->once()
                ->andReturnUsing(fn (UploadedFile $f, string $dir, string $name) => $dir.'/'.$name);
            $mock->shouldReceive('url')
                ->once()
                ->andReturn('https://cdn.example.test/storage/bookings/media/poster.jpg');
        });

        $result = app(MediaService::class)->upload($file);

        $this->assertStringStartsWith('bookings/media/', $result['file_path']);
        $this->assertSame('image', $result['file_type']);
        $this->assertSame('https://cdn.example.test/storage/bookings/media/poster.jpg', $result['url']);
        $this->assertSame('poster.jpg', $result['original_name']);
    }

    public function test_media_service_classifies_video_uploads(): void
    {
        Storage::fake('public');

        $file = UploadedFile::fake()->create('spot.mp4', 40, 'video/mp4');

        $this->mock(StorageContractInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('storeAs')->andReturnUsing(fn ($f, $dir, $name) => $dir.'/'.$name);
            $mock->shouldReceive('url')->andReturn('https://cdn.example.test/x.mp4');
        });

        $this->assertSame('video', app(MediaService::class)->upload($file)['file_type']);
    }

    public function test_media_service_delete_checks_existence_before_deleting(): void
    {
        $this->mock(StorageContractInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('exists')->once()->with('a/b.jpg')->andReturnFalse();
            $mock->shouldReceive('delete')->never();
        });

        $this->assertFalse(app(MediaService::class)->delete('a/b.jpg'));
    }

    public function test_media_service_delete_removes_an_existing_file(): void
    {
        $this->mock(StorageContractInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('exists')->once()->with('a/b.jpg')->andReturnTrue();
            $mock->shouldReceive('delete')->once()->with('a/b.jpg')->andReturnTrue();
        });

        $this->assertTrue(app(MediaService::class)->delete('a/b.jpg'));
    }

    public function test_profile_service_replaces_an_existing_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['status' => 'active']);
        $user->forceFill(['avatar' => 'avatars/'.$user->id.'/old.png'])->save();

        $deleted = [];
        $stored = null;

        $this->mock(StorageContractInterface::class, function (MockInterface $mock) use (&$deleted, &$stored, $user) {
            $mock->shouldReceive('delete')
                ->once()
                ->andReturnUsing(function (string $path) use (&$deleted) {
                    $deleted[] = $path;

                    return true;
                });
            $mock->shouldReceive('store')
                ->once()
                ->andReturnUsing(function (UploadedFile $f, string $dir) use (&$stored) {
                    $stored = $dir.'/new.png';

                    return $stored;
                });
            $mock->shouldReceive('url')
                ->once()
                ->andReturn('https://cdn.example.test/avatars/'.$user->id.'/new.png');
        });

        $url = app(ProfileService::class)->uploadAvatar(
            $user,
            UploadedFile::fake()->image('new.png')->size(10),
        );

        $this->assertSame(['avatars/'.$user->id.'/old.png'], $deleted);
        $this->assertSame('avatars/'.$user->id.'/new.png', $stored);
        $this->assertSame('https://cdn.example.test/avatars/'.$user->id.'/new.png', $url);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => 'avatars/'.$user->id.'/new.png']);
    }

    public function test_profile_service_does_not_delete_when_there_is_no_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['status' => 'active', 'avatar' => null]);

        $this->mock(StorageContractInterface::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('delete')->never();
            $mock->shouldReceive('store')
                ->once()
                ->andReturnUsing(fn (UploadedFile $f, string $dir) => $dir.'/fresh.png');
            $mock->shouldReceive('url')
                ->once()
                ->andReturn('https://cdn.example.test/'.$user->id.'/fresh.png');
        });

        app(ProfileService::class)->uploadAvatar($user, UploadedFile::fake()->image('fresh.png')->size(10));

        $this->assertDatabaseHas('users', ['id' => $user->id, 'avatar' => 'avatars/'.$user->id.'/fresh.png']);
    }

    /*
    |--------------------------------------------------------------------------
    | Image processing port
    |--------------------------------------------------------------------------
    */

    public function test_the_gd_processor_reports_availability(): void
    {
        $this->assertSame(
            extension_loaded('gd'),
            app(ImageProcessingContractInterface::class)->isAvailable(),
        );
    }

    public function test_the_gd_processor_encodes_a_real_image_as_webp(): void
    {
        $processor = app(ImageProcessingContractInterface::class);

        if (! $processor->isAvailable()) {
            $this->markTestSkipped('GD is not loaded in this runtime.');
        }

        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        imagepng($image);
        $binary = ob_get_clean();
        imagedestroy($image);

        $encoded = $processor->encodeWebp($binary, 80);

        $this->assertNotNull($encoded);
        $this->assertStringStartsWith('RIFF', $encoded);
        $this->assertStringContainsString('WEBP', $encoded);
    }

    public function test_the_gd_processor_returns_null_for_undecodable_bytes(): void
    {
        $processor = app(ImageProcessingContractInterface::class);

        if (! $processor->isAvailable()) {
            $this->markTestSkipped('GD is not loaded in this runtime.');
        }

        $this->assertNull($processor->encodeWebp('this is definitely not an image', 80));
        $this->assertNull($processor->encodeWebp('', 80));
    }

    public function test_media_helper_uses_the_injected_processor(): void
    {
        $tempPath = $this->makeRealImageFile();

        $file = new UploadedFile($tempPath, 'sample.jpg', 'image/jpeg', null, true);

        // Always returns 100KB, which is over the 70KB budget, so the quality
        // ladder must step all the way down to its floor.
        $processor = $this->fakeProcessor(str_repeat('A', 100 * 1024));

        $result = MediaHelper::compressUploadedImage($file, 'phase012/fake', $processor);

        // Starts at 80 and steps down while over budget and above the floor.
        $this->assertSame([80, 70, 60, 50, 40, 30], $processor->calls);
        $this->assertTrue($result['is_compressed']);
        $this->assertSame(100 * 1024, $result['file_size']);
        $this->assertStringEndsWith('.webp', $result['file_path']);

        $this->cleanMediaArtefacts('phase012/fake');
        @unlink($tempPath);
    }

    public function test_media_helper_does_not_step_down_when_the_first_attempt_fits(): void
    {
        $tempPath = $this->makeRealImageFile();

        $file = new UploadedFile($tempPath, 'sample.jpg', 'image/jpeg', null, true);

        // 10KB is comfortably under the 70KB budget, so quality 80 is accepted.
        $processor = $this->fakeProcessor(str_repeat('A', 10 * 1024));

        $result = MediaHelper::compressUploadedImage($file, 'phase012/fits', $processor);

        $this->assertSame([80], $processor->calls);
        $this->assertFalse($result['is_compressed']);

        $this->cleanMediaArtefacts('phase012/fits');
        @unlink($tempPath);
    }

    public function test_media_helper_copies_untouched_when_the_encoder_is_unavailable(): void
    {
        $tempPath = $this->makeRealImageFile();

        $file = new UploadedFile($tempPath, 'doc.jpg', 'image/jpeg', null, true);

        $processor = $this->fakeProcessor(null, available: false);

        $result = MediaHelper::compressUploadedImage($file, 'phase012/fallback', $processor);

        $this->assertSame([], $processor->calls);
        $this->assertFalse($result['is_compressed']);
        $this->assertStringEndsWith('.jpg', $result['file_path']);
        $this->assertSame(filesize($tempPath), $result['file_size']);

        $this->cleanMediaArtefacts('phase012/fallback');
        @unlink($tempPath);
    }

    public function test_media_helper_copies_untouched_when_the_encoder_produces_nothing(): void
    {
        $tempPath = $this->makeRealImageFile();

        $file = new UploadedFile($tempPath, 'broken.jpg', 'image/jpeg', null, true);

        // The encoder is available but cannot make sense of the bytes: the
        // helper must fall back to copying rather than failing, as it always has.
        $processor = $this->fakeProcessor(null);

        $result = MediaHelper::compressUploadedImage($file, 'phase012/corrupt', $processor);

        $this->assertSame([80], $processor->calls);
        $this->assertFalse($result['is_compressed']);
        $this->assertStringEndsWith('.jpg', $result['file_path']);
        $this->assertSame(filesize($tempPath), $result['file_size']);

        $this->cleanMediaArtefacts('phase012/corrupt');
        @unlink($tempPath);
    }

    /**
     * Write a real PNG to a temp file and return its path.
     *
     * MediaHelper branches on UploadedFile::getMimeType(), which sniffs the file
     * *content* rather than trusting the client-supplied mime type. An empty
     * tempnam() file therefore never reaches the encoder at all.
     */
    protected function makeRealImageFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'phase012-');

        $image = imagecreatetruecolor(40, 30);
        imagefill($image, 0, 0, imagecolorallocate($image, 10, 120, 200));
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    /**
     * A recording ImageProcessingContractInterface double.
     *
     * Exposes the quality values it was asked for via $calls. A plain object
     * property is used rather than a by-reference constructor argument because
     * PHP does not support reference-promoted properties.
     */
    protected function fakeProcessor(?string $result, bool $available = true): ImageProcessingContractInterface
    {
        return new class($result, $available) implements ImageProcessingContractInterface
        {
            /** @var array<int, int> */
            public array $calls = [];

            public function __construct(
                protected ?string $result,
                protected bool $available,
            ) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function encodeWebp(string $binary, int $quality): ?string
            {
                $this->calls[] = $quality;

                return $this->result;
            }
        };
    }

    /**
     * Remove the files MediaHelper writes during these tests.
     */
    protected function cleanMediaArtefacts(string $relativeDir): void
    {
        $dir = storage_path('app/public/'.$relativeDir);

        if (! is_dir($dir)) {
            return;
        }

        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
        @rmdir(dirname($dir));
    }
}
