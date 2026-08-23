<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Walk\Walk;

/**
 * Warns at `open_run` about a step whose binary is not there.
 *
 * Without this, a walk pays for every earlier step before finding out that step
 * three cannot run. Halting there is correct and far too late to be useful.
 *
 * Deliberately conservative: it only checks a command whose first token is a path
 * (`vendor/bin/pint`, `node_modules/.bin/oxlint`), because that is the case a
 * filesystem check can answer honestly. `php artisan test` or `composer phpstan`
 * resolve through PATH and through another tool's own dispatch, and guessing at
 * those would produce warnings about steps that run perfectly well — which is
 * worse than not warning, since a warning nobody trusts is noise.
 */
final readonly class CommandPreflight
{
    public function __construct(private string $workingDirectory) {}

    /**
     * @return list<string>
     */
    public function warnings(Walk $walk): array
    {
        $warnings = [];

        foreach ($walk->steps as $walkStep) {
            $step = $walkStep->step;

            if (! $step instanceof Shell) {
                continue;
            }

            $binary = $this->leadingPath($step->command());

            if ($binary === null) {
                continue;
            }

            if (! is_file(rtrim($this->workingDirectory, '/').'/'.$binary)) {
                $warnings[] = sprintf(
                    'Step [%s] runs `%s`, which is not present. The run will halt there unless it is installed first.',
                    $step->id(),
                    $binary,
                );
            }
        }

        return $warnings;
    }

    /**
     * The command's first token, when it is a relative path this package can
     * check. Anything absolute, PATH-resolved, or shell-quoted is left alone.
     */
    private function leadingPath(string $command): ?string
    {
        $first = strtok(trim($command), " \t");

        if ($first === false || ! str_contains($first, '/')) {
            return null;
        }

        // Shell punctuation means it is not a filename yet: `vendor/bin/pint;
        // composer test` would be checked as a file named `vendor/bin/pint;` and
        // warn about a step that runs fine. Absolute paths may live outside.
        if (str_starts_with($first, '/') || preg_match('#^[A-Za-z0-9._/-]+$#', $first) !== 1) {
            return null;
        }

        return $first;
    }
}
