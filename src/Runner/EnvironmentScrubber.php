<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

/**
 * Builds the environment a step's subprocess runs in.
 *
 * A step must not silently inherit the parent's already-resolved environment —
 * most importantly `DB_DATABASE`, which a Tests step has to set for itself so it
 * never touches a shared test database. The technique is `laravel/boost`'s
 * `Mcp\ToolExecutor`: set every key the app's own `.env` defines to `false`,
 * which removes it from the child, so the child reads `.env` itself.
 *
 * Scope of that scrub, stated plainly: it removes keys the app's `.env` DEFINES.
 * Everything else in the parent environment is inherited, so a secret exported
 * only in the parent shell (a `GITHUB_TOKEN`, an API key, a CI secret) is visible
 * to every configured step command. That is the same exposure as running the tool
 * by hand in that shell, and step commands come from `.config/pipeline.php` —
 * repo code, trusted at the same level as any other PHP in the project. An
 * allowlist would be stricter but breaks any step needing `PATH`, `HOME`,
 * `COMPOSER_HOME` or similar, turning a misconfiguration into "the tool did not
 * run". Allowlisting is v2 hardening, not a prototype default.
 */
final readonly class EnvironmentScrubber
{
    public function __construct(private string $basePath) {}

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string|false>
     */
    public function forStep(array $overrides = []): array
    {
        $scrubbed = [];

        foreach ($this->dotenvKeys() as $key) {
            $scrubbed[$key] = false;
        }

        // Overrides win — that is how a step pins its own DB_DATABASE.
        return [...$scrubbed, ...$overrides];
    }

    /** @return list<string> */
    private function dotenvKeys(): array
    {
        $path = rtrim($this->basePath, '/').'/.env';

        if (! is_file($path)) {
            return [];
        }

        $keys = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $name = trim(strtok($line, '=') ?: '');

            if ($name !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1) {
                $keys[] = $name;
            }
        }

        return array_values(array_unique($keys));
    }
}
