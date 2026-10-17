<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * DEP-01 — APP_DEBUG / environment hygiene.
 *
 * Two defects are guarded here:
 *
 * 1. `.env.example` shipped `APP_ENV=local` + `APP_DEBUG=true`. It is the file a
 *    developer copies onto a server, so the default determined whether the deployed
 *    application leaked stack traces, absolute paths, environment values and query
 *    fragments to anonymous callers. The default is now production/debug-false.
 *
 * 2. `.env.testing` was COMMITTED TO GIT CONTAINING REAL CREDENTIALS byte-identical to
 *    the developer's working `.env`: APP_KEY, DB_PASSWORD, MAIL_PASSWORD, RAZORPAY_KEY,
 *    RAZORPAY_SECRET, REDIS_PASSWORD. They have been stripped; secrets are now supplied
 *    by the process environment.
 *
 * These tests never read or assert secret VALUES, and never print them. They only assert
 * that no committed env file carries a non-empty secret-looking value, and that debug
 * cannot be on when APP_ENV is not a developer environment.
 */
class Dep01DebugAndEnvironmentTest extends TestCase
{
    /**
     * Env files that are legitimately tracked in Git. `.env` is NOT listed: it is
     * git-ignored and is the developer's private working file.
     */
    private function trackedEnvFiles(): array
    {
        $root = base_path();
        $files = [];

        foreach (['.env.example', '.env.testing', '.env.testing.example'] as $name) {
            if (is_file($root . '/' . $name)) {
                $files[] = $name;
            }
        }

        return $files;
    }

    private function parseEnv(string $path): array
    {
        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $values[trim($k)] = trim($v);
        }

        return $values;
    }

    // =================================================================
    // 1. No committed env file may carry a secret value
    // =================================================================

    public function test_no_tracked_env_file_contains_a_secret_value()
    {
        $secretKey = '/(KEY|SECRET|TOKEN|PASSWORD|PASSWD|CREDENTIAL|PRIVATE)/i';

        // Placeholders that are obviously not credentials.
        $allowedPlaceholders = ['', 'null', 'none', 'changeme', 'your-', 'x', 'base64:'];

        $offenders = [];

        foreach ($this->trackedEnvFiles() as $name) {
            foreach ($this->parseEnv(base_path($name)) as $key => $value) {
                if (! preg_match($secretKey, $key)) {
                    continue;
                }
                $isPlaceholder = false;
                foreach ($allowedPlaceholders as $p) {
                    if ($p === '' ? $value === '' : stripos($value, $p) === 0) {
                        $isPlaceholder = true;
                        break;
                    }
                }
                if ($isPlaceholder) {
                    continue;
                }
                // Report the KEY NAME only. Values are never printed.
                $offenders[] = "{$name}: {$key} has a non-empty value";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These committed env files contain non-placeholder secret values (names only, "
            . "values intentionally not shown). Credentials must be supplied by the "
            . "process environment, not committed:\n  " . implode("\n  ", $offenders)
        );
    }

    public function test_env_testing_does_not_duplicate_the_working_env_secrets()
    {
        // Belt-and-braces: even if a future edit reintroduces a secret-looking key with a
        // plausible-looking value, it must not match the private working .env.
        $private = base_path('.env');
        if (! is_file($private)) {
            $this->markTestSkipped('No private .env present in this environment.');
        }

        $privateValues = $this->parseEnv($private);
        $testing = $this->parseEnv(base_path('.env.testing'));

        // Only SECRET-BEARING keys count. Non-secret values such as DB_HOST, DB_PORT,
        // LOG_LEVEL or APP_LOCALE are expected to be identical in both files and are
        // deliberately committed so every developer gets the same non-secret baseline.
        $secretKey = '/(KEY|SECRET|TOKEN|PASSWORD|PASSWD|CREDENTIAL|PRIVATE)/i';

        $shared = [];
        foreach ($testing as $k => $v) {
            if ($v === '' || ! isset($privateValues[$k])) {
                continue;
            }
            if (! preg_match($secretKey, $k)) {
                continue;
            }
            if ($privateValues[$k] === $v) {
                $shared[] = $k;
            }
        }

        $this->assertSame(
            [],
            $shared,
            "These keys in the committed .env.testing are byte-identical to the private "
            . "working .env (names only, values intentionally not shown). Each one is a "
            . "credential leak into version control:\n  " . implode("\n  ", $shared)
        );
    }

    public function test_app_key_in_testing_is_not_the_production_app_key()
    {
        $private = base_path('.env');
        $testing = base_path('.env.testing');

        if (! is_file($private) || ! is_file($testing)) {
            $this->markTestSkipped('Env files not available.');
        }

        $a = $this->parseEnv($private);
        $b = $this->parseEnv($testing);

        if (($a['APP_KEY'] ?? '') === '' || ($b['APP_KEY'] ?? '') === '') {
            $this->markTestSkipped('APP_KEY not set in one of the files.');
        }

        $this->assertNotSame(
            $a['APP_KEY'],
            $b['APP_KEY'],
            'SEC-CRED: .env.testing reuses the private .env APP_KEY. Tests must use a '
            . 'distinct key so the real key is never committed.'
        );
    }

    // =================================================================
    // 2. Debug must default to OFF outside developer environments
    // =================================================================

    public function test_env_example_ships_debug_disabled()
    {
        $values = $this->parseEnv(base_path('.env.example'));

        $this->assertArrayHasKey('APP_DEBUG', $values, '.env.example must define APP_DEBUG.');
        $this->assertSame(
            'false',
            strtolower($values['APP_DEBUG']),
            'DEP-01 REGRESSION: .env.example must ship APP_DEBUG=false. It is the file '
            . 'copied onto a server; a true default leaks stack traces and paths.'
        );

        $this->assertSame(
            'production',
            strtolower($values['APP_ENV'] ?? ''),
            'DEP-01 REGRESSION: .env.example must ship APP_ENV=production so the default '
            . 'deployment posture is safe.'
        );

        $this->assertSame(
            '',
            $values['APP_KEY'] ?? '',
            '.env.example must not ship an APP_KEY.'
        );
    }

    public function test_debug_cannot_be_enabled_when_app_env_is_production()
    {
        // Asserts the REAL contract, against the REAL config/app.php wiring, by booting a
        // fresh application instance for each combination. A previous version of this test
        // only asserted that `env('APP_DEBUG')` defaults to false when unset, which proved
        // nothing about production: config/app.php read APP_DEBUG directly, so
        // APP_ENV=production + APP_DEBUG=true still resolved to debug=true. The guard now
        // lives in config/app.php and is asserted here.
        //
        // RESTORING THE ENVIRONMENT IS THE HARD PART, AND GETTING IT WRONG IS SILENTLY
        // DESTRUCTIVE. Illuminate\Support\Env reads from $_SERVER, then $_ENV, then
        // getenv() - in that order. Restoring only putenv() is therefore NOT enough: the
        // $_ENV/$_SERVER copies would survive, and APP_ENV=production would silently leak
        // into every later test in the same PHP process. It was observed doing exactly
        // that, which made RolesAndPermissionsSeeder produce an incomplete role set and
        // failed an unrelated authorization test 60+ cases away. All three channels are
        // saved and all three are restored.
        $matrix = [
            // [APP_ENV,       APP_DEBUG, expected debug]
            ['production',    'true',     false],
            ['production',    '1',        false],
            ['production',    'false',    false],
            ['production',    null,       false],
            // Outside production the developer opt-in must still work, otherwise this
            // guard would be "safe" only by making local development impossible.
            ['local',         'true',     true],
            ['testing',       'true',     true],
            ['staging',       'true',     true],
        ];

        $saved = [];
        foreach (['APP_ENV', 'APP_DEBUG'] as $k) {
            $saved[$k] = [
                'getenv' => getenv($k) === false ? null : (string) getenv($k),
                'env' => array_key_exists($k, $_ENV) ? (string) $_ENV[$k] : null,
                'server' => array_key_exists($k, $_SERVER) ? (string) $_SERVER[$k] : null,
            ];
        }

        $results = [];

        try {
            foreach ($matrix as [$appEnv, $appDebug, $expected]) {
                $this->setEnvChannel('APP_ENV', $appEnv);
                $this->setEnvChannel('APP_DEBUG', $appDebug);

                // The config repository is built once at boot, so mutating the environment
                // afterwards cannot change config('app.debug'). The real config/app.php
                // expression is therefore evaluated directly - which is exactly what
                // Laravel does when it builds the repository, and is what
                // `php artisan config:cache` freezes into bootstrap/cache/config.php.
                $resolved = (require base_path('config/app.php'))['debug'];

                $results[] = sprintf(
                    'APP_ENV=%s APP_DEBUG=%s => debug=%s (expected %s)',
                    $appEnv,
                    $appDebug === null ? '<unset>' : $appDebug,
                    var_export($resolved, true),
                    var_export($expected, true)
                );

                $this->assertSame(
                    $expected,
                    $resolved,
                    "DEP-01 REGRESSION: debug mode resolved to " . var_export($resolved, true)
                    . " but must be " . var_export($expected, true) . ".\n  "
                    . implode("\n  ", $results)
                );
            }
        } finally {
            foreach ($saved as $k => $channels) {
                foreach (['getenv' => 'putenv', 'env' => '_ENV', 'server' => '_SERVER'] as $channel => $target) {
                    $value = $channels[$channel];
                    if ($value === null) {
                        if ($target === 'putenv') {
                            putenv($k);
                        } else {
                            unset($GLOBALS[$target][$k]);
                        }
                    } elseif ($target === 'putenv') {
                        putenv("{$k}={$value}");
                    } else {
                        $GLOBALS[$target][$k] = $value;
                    }
                }
            }
        }
    }

    private function setEnvChannel(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($GLOBALS['_ENV'][$key], $GLOBALS['_SERVER'][$key]);

            return;
        }

        putenv("{$key}={$value}");
        $GLOBALS['_ENV'][$key] = $value;
        $GLOBALS['_SERVER'][$key] = $value;
    }

    public function test_no_cached_config_is_committed()
    {
        // A committed bootstrap/cache/config.php would silently override every .env change,
        // which is a common way APP_DEBUG=true survives a "fix".
        $this->assertFileDoesNotExist(
            base_path('bootstrap/cache/config.php'),
            'A cached config is present. Run `php artisan config:clear`; never commit it.'
        );
        $this->assertFileDoesNotExist(
            base_path('bootstrap/cache/routes-v7.php'),
            'A cached route file is present. Run `php artisan route:clear`.'
        );
    }
}
