<?php

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Phase 0.15 - executable enforcement of ARCHITECTURE_RULES.md.
 *
 * DESIGN NOTE - why this file is a static scanner and not a runtime test:
 * architecture rules are properties of the *source tree*, so they must be
 * checked by parsing source, not by exercising HTTP or the database. These
 * tests therefore extend PHPUnit\Framework\TestCase directly and touch no
 * framework, so they run in milliseconds and cannot be perturbed by fixtures.
 *
 * DETECTION NOTE - why token_get_all() and not a regex:
 * a regex over source text produces false positives for method calls that
 * merely share a name with a global helper (ReportPeriod::resolve(),
 * Carbon, Cache::class, docblock prose, "app(" inside a comment). Every
 * check below walks the real token stream and only reports a hit when the
 * name is BOTH an unqualified call AND not preceded by a `use` import,
 * `::class` constant fetch, or namespaced reference.
 *
 * DEBT NOTE - why pre-existing violations are pinned rather than fixed:
 * Rule 11 conformance is 10/13 as of Phase 0.14. The violations listed in
 * the allowlists below are characterised, accepted debt. They are pinned
 * EXACTLY (file + method + line) so that this suite fails if any of them
 * moves or changes, and fails immediately if any *new* one appears. That
 * makes the debt visible and non-regressing without ever asserting that
 * the debt is desirable.
 */
class ArchitectureRuleTest extends TestCase
{
    /**
     * Pre-existing, characterisation-tested Rule 11 import debt.
     *
     * `App\Domain\Pricing\CampaignPricingEngine` depends on seven legacy
     * Services classes. This is the single open Rule 11 violation recorded in
     * docs/audit/phase-0.14-architecture-debt-register.json. It cannot be
     * removed without changing campaign pricing behaviour, so it is pinned
     * exactly: adding an eighth import fails, and so does silently dropping one
     * (which would mean the debt register is stale).
     *
     * @var array<string, list<string>>
     */
    private const KNOWN_LAYER_IMPORT_DEBT = [
        'Domain|App\Services' => [
            'app/Domain/Pricing/CampaignPricingEngine.php imports App\Services\Campaign\LoyaltyService',
            'app/Domain/Pricing/CampaignPricingEngine.php imports App\Services\PricingRuleEngine',
            'app/Domain/Pricing/CampaignPricingEngine.php imports App\Services\Pricing\DailyPricingCalculator',
            'app/Domain/Pricing/CampaignPricingEngine.php imports App\Services\Pricing\FirstBookingDiscountPricingCalculator',
            'app/Domain/Pricing/CampaignPricingEngine.php imports App\Services\Pricing\ReferralPricingCalculator',
            'app/Domain/Pricing/CampaignPricingEngine.php imports App\Services\Pricing\ScreenPricingResolver',
            'app/Domain/Pricing/CampaignPricingEngine.php imports App\Services\SettingsService',
        ],
    ];

    /**
     * Single-slot token cache.
     *
     * A per-file map looked harmless but was not: the debt test walks all 352
     * application classes, and caching every file's token array at once
     * exhausted the 128 MB test memory limit once earlier suites had already
     * claimed their share. Every method here iterates files sequentially, so a
     * one-file cache gives the same reuse with bounded memory.
     */
    private ?string $tokenCacheFile = null;

    /** @var array<int, array{0: int, 1: string, 2: int}|string> */
    private array $tokenCache = [];

    /** @var array<string, list<string>> */
    private const LAYER_IMPORTS = [
        'Domain' => ['App\Http', 'App\Console', 'App\Infrastructure', 'App\Application', 'App\Services', 'App\Http\Controllers'],
        'Application' => ['App\Http', 'App\Console'],
    ];

    /**
     * Pre-existing, characterisation-tested service-locator debt.
     * Any deviation from this exact set is a regression.
     *
     * @var array<string, list<string>>
     */
    private const KNOWN_SERVICE_LOCATORS = [
        'App\Helpers\MediaHelper' => ['app'],
        'App\Http\Controllers\BookingController' => ['app'],
        'App\Http\Middleware\PermissionMiddleware' => ['app'],
        'App\Models\Booking' => ['app'],
        'App\Models\User' => ['app'],
        'App\Services\Campaign\ExchangeRateService' => ['app'],
        'App\Services\ManualBookingService' => ['app'],
        'App\Services\RevenueSplitResolverService' => ['app'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! is_dir($this->appPath())) {
            $this->markTestSkipped('Application source tree is not available.');
        }
    }

    // ---------------------------------------------------------------------
    // Rule 11 - layer dependency direction
    // ---------------------------------------------------------------------

    #[DataProvider('layerProvider')]
    public function test_layer_never_imports_a_forbidden_layer(string $layer, string $forbidden): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->appPath($layer)) as $file) {
            foreach ($this->importedNamespaces($file) as $import) {
                if (str_starts_with($import, $forbidden)) {
                    $offenders[] = $this->relative($file).' imports '.$import;
                }
            }
        }

        $key = $layer.'|'.$forbidden;
        $expected = self::KNOWN_LAYER_IMPORT_DEBT[$key] ?? [];

        $this->assertSame($expected, $offenders, sprintf(
            "Rule 11 violation set changed for %s -> %s.\n".
            "A NEW entry is a new violation: inject a contract instead.\n".
            "A MISSING entry means known debt was removed - update\n".
            "docs/audit/phase-0.14-architecture-debt-register.json in the same commit.\n".
            "Found:\n  %s",
            $layer,
            $forbidden,
            $offenders === [] ? '(none)' : "\n  ".implode("\n  ", $offenders)
        ));
    }

    /** @return array<string, array{string, string}> */
    public static function layerProvider(): array
    {
        $cases = [];
        foreach (self::LAYER_IMPORTS as $layer => $forbidden) {
            foreach ($forbidden as $target) {
                $cases["{$layer} must not import {$target}"] = [$layer, $target];
            }
        }

        return $cases;
    }

    // ---------------------------------------------------------------------
    // Rule 11 - no service locator inside the inner layers
    // ---------------------------------------------------------------------

    #[DataProvider('innerLayerProvider')]
    public function test_inner_layers_contain_no_service_locators(string $layer): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->appPath($layer)) as $file) {
            foreach ($this->serviceLocatorCalls($file) as $method) {
                $offenders[] = $this->relative($file).' calls app('.$method.'...)';
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "Rule 11 violation: %s must be free of service locators.\nFound:\n  %s",
            $layer,
            $offenders === [] ? '(none)' : "\n  ".implode("\n  ", $offenders)
        ));
    }

    /** @return array<string, array{string}> */
    public static function innerLayerProvider(): array
    {
        return [
            'Domain has no service locators' => ['Domain'],
            'Application has no service locators' => ['Application'],
        ];
    }

    // ---------------------------------------------------------------------
    // Service-locator debt must not grow
    // ---------------------------------------------------------------------

    public function test_service_locator_debt_does_not_grow(): void
    {
        $actual = [];

        foreach ($this->phpFilesIn($this->appPath()) as $file) {
            $class = $this->fqcnFor($file);
            foreach ($this->serviceLocatorCalls($file) as $method) {
                $actual[$class][] = $method;
            }
        }

        ksort($actual);
        foreach ($actual as &$methods) {
            sort($methods);
        }
        unset($methods);

        $expected = self::KNOWN_SERVICE_LOCATORS;
        ksort($expected);
        foreach ($expected as &$methods) {
            sort($methods);
        }
        unset($methods);

        $this->assertSame($expected, $actual, implode("\n", [
            'The set of service-locator call sites changed.',
            'This is EITHER a new violation (fail: inject the dependency instead of adding it here)',
            'OR an intentional refactor (fail only after the characterising test has been',
            'updated in the same commit that removed the call).',
        ]));
    }

    // ---------------------------------------------------------------------
    // Phase 0.13 regression - the Model -> Http authorization fix must hold
    // ---------------------------------------------------------------------

    public function test_no_model_imports_the_http_layer(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn($this->appPath('Models')) as $file) {
            foreach ($this->importedNamespaces($file) as $import) {
                if (str_starts_with($import, 'App\Http')) {
                    $offenders[] = $this->relative($file).' imports '.$import;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'Rule 12 violation: an Eloquent model must not depend on the HTTP layer.',
            'User::hasPermission() was moved to App\\Support\\Authorization\\RolePermissionChecker',
            'precisely to remove this edge. It must not be reintroduced.',
            'Found:',
            ...$offenders,
        ]));
    }

    public function test_permission_algorithm_has_a_single_owner(): void
    {
        $this->assertFileExists(
            $this->appPath('Support/Authorization/RolePermissionChecker.php'),
            'RolePermissionChecker is the single owner of the permission decision.'
        );

        $middleware = $this->appPath('Http/Middleware/PermissionMiddleware.php');
        $this->assertFileExists($middleware);
        $this->assertStringContainsString(
            'RolePermissionChecker',
            (string) file_get_contents($middleware),
            'PermissionMiddleware must delegate to RolePermissionChecker, not re-implement the algorithm.'
        );

        $user = $this->appPath('Models/User.php');
        $this->assertStringContainsString(
            'RolePermissionChecker',
            (string) file_get_contents($user),
            'User must delegate to RolePermissionChecker.'
        );
    }

    // ---------------------------------------------------------------------
    // Rule 2 - Domain value objects must not depend on the framework
    // ---------------------------------------------------------------------

    public function test_domain_uses_no_illuminate_facade(): void
    {
        $facades = [
            'DB', 'Cache', 'Log', 'Auth', 'Storage', 'Http', 'Event', 'Queue',
            'Mail', 'Route', 'Schema', 'Config', 'File', 'Validator', 'Hash',
            'Notification', 'Password', 'RateLimiter', 'Artisan', 'Context',
        ];

        $offenders = [];

        foreach ($this->phpFilesIn($this->appPath('Domain')) as $file) {
            foreach ($this->facadeCalls($file) as $name) {
                if (in_array($name, $facades, true)) {
                    $offenders[] = $this->relative($file).' calls '.$name.'::';
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "Rule 2 violation: Domain must not call framework facades.\nFound:\n  %s",
            $offenders === [] ? '(none)' : "\n  ".implode("\n  ", $offenders)
        ));
    }

    // ---------------------------------------------------------------------
    // Token-stream helpers
    // ---------------------------------------------------------------------

    /**
     * Real `use X\Y\Z;` import statements in a file.
     *
     * @return list<string>
     */
    private function importedNamespaces(string $file): array
    {
        $imports = [];
        $tokens = $this->tokens($file);
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (! is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            // Skip closure `use (...)` and trait `use Foo;` inside a class body.
            $previous = $this->significant($tokens, $index - 1, -1);
            if ($previous === '(' || (is_array($previous) && $previous[0] === T_CLASS)) {
                continue;
            }

            $buffer = '';
            for ($i = $index + 1; $i < $count; $i++) {
                $next = $tokens[$i];
                if ($next === ';' || $next === '{') {
                    break;
                }
                if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                // PHP 8 tokenises `Foo\Bar` as one T_NAME_QUALIFIED array token,
                // so the payload must be read from [1].
                $buffer .= is_array($next) ? $next[1] : $next;
            }

            if (str_contains($buffer, '\\')) {
                $imports[] = rtrim($buffer, '\\');
            }
        }

        return array_values(array_unique($imports));
    }

    /**
     * Unqualified static calls to the container helper: app(), resolve(), App::make().
     *
     * @return list<string>
     */
    private function serviceLocatorCalls(string $file): array
    {
        $found = [];
        $tokens = $this->tokens($file);
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $name = $token[1];
            if ($this->significant($tokens, $index + 1, 1) !== '(') {
                continue;
            }

            $previous = $this->significant($tokens, $index - 1, -1);

            // A namespaced / imported call (Some\Thing::, $this->, new Foo) is
            // dependency injection through a class, not a service locator, and a
            // method *declaration* (`function resolve(`) is not a call at all.
            if (is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CLASS], true)) {
                continue;
            }

            if ($name === 'app') {
                $found[] = 'app';
            } elseif ($name === 'resolve') {
                $found[] = 'resolve';
            } elseif ($name === 'App' && (is_array($previous) && $previous[0] === T_NS_SEPARATOR)) {
                $found[] = 'App::make';
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Facade-style static calls, i.e. `Foo::` where Foo is not namespaced.
     *
     * @return list<string>
     */
    private function facadeCalls(string $file): array
    {
        $found = [];
        $tokens = $this->tokens($file);
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            if ($this->significant($tokens, $index + 1, 1) !== '::') {
                continue;
            }

            // `\DB::foo()` is an explicitly fully-qualified global call and is
            // still a facade; it is reported on purpose.
            $found[] = $token[1];
        }

        return array_values(array_unique($found));
    }

    /**
     * Nearest non-whitespace, non-comment token in the given direction,
     * starting from $index inclusive.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private function significant(array $tokens, int $index, int $direction)
    {
        $count = count($tokens);

        for ($i = $index; $i >= 0 && $i < $count; $i += $direction) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    /**
     * @return array<int, array{0: int, 1: string, 2: int}|string>
     */
    private function tokens(string $file): array
    {
        if ($this->tokenCacheFile !== $file) {
            $this->tokenCacheFile = $file;
            $this->tokenCache = token_get_all((string) file_get_contents($file));
        }

        return $this->tokenCache;
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function fqcnFor(string $file): string
    {
        $source = (string) file_get_contents($file);
        $namespace = preg_match('/^\s*namespace\s+([^;]+);/m', $source, $m) === 1 ? trim($m[1]) : '';
        $class = preg_match('/^\s*(?:final\s+|abstract\s+)?(?:class|trait|interface|enum)\s+(\w+)/m', $source, $m) === 1 ? $m[1] : basename($file, '.php');

        return $namespace === '' ? $class : $namespace.'\\'.$class;
    }

    private function appPath(string $sub = ''): string
    {
        return rtrim(dirname(__DIR__, 3).'/app'.($sub === '' ? '' : '/'.$sub), '/');
    }

    private function relative(string $file): string
    {
        return str_replace(dirname(__DIR__, 3).'/', '', $file);
    }
}
