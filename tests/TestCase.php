<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Load the developer's private, git-ignored test credential overrides.
     *
     * Background
     * ----------
     * `.env.testing` used to be committed to Git CONTAINING REAL CREDENTIALS: APP_KEY,
     * DB_PASSWORD, RAILZ, MAIL_PASSWORD, RAZORPAY_KEY, RAZORPAY_SECRET and
     * REDIS_PASSWORD were byte-identical to the values in the working `.env`. Those
     * values have been removed, because a committed credential is a credential leak.
     *
     * The trade-off is that `php artisan test` no longer has a database password. Rather
     * than reintroduce the leak, or require every developer to remember a shell incantation,
     * this base class reads an OPTIONAL, GIT-IGNORED file:
     *
     *     .env.testing.local
     *
     * Create it once with:
     *
     *     { set -a; . ./.env; set +a; } > .env.testing.local
     *     chmod 600 .env.testing.local
     *
     * or, in CI, simply export DB_USERNAME / DB_PASSWORD / RAZORPAY_KEY /
     * RAZORPAY_SECRET as repository secrets. Real environment variables always win,
     * because Laravel's Dotenv is immutable.
     *
     * If the file is absent the values are simply not overridden and the suite fails with
     * the usual "Access denied" MySQL error, which `test_suite_has_database_credentials`
     * in tests/Feature/Security/Dep01DebugAndEnvironmentTest.php turns into an
     * actionable message.
     */
    protected function setUp(): void
    {
        $this->loadLocalTestCredentials();

        parent::setUp();
    }

    private function loadLocalTestCredentials(): void
    {
        $file = dirname(__DIR__) . '/.env.testing.local';

        if (! is_file($file) || ! is_readable($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            if ($key === '' || getenv($key) !== false) {
                // Never override a variable the developer or CI exported on purpose.
                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
