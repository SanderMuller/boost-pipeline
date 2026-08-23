<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Walk\Walk;

/**
 * Warns at `open_run` about a step whose binary is not there.
 *
 * Nothing used to check until the cursor arrived, so a walk paid for every
 * earlier step before finding out that step three could not run. A real run lost
 * two minutes of server-verified receipts that way — the halt was correct, it was
 * just far too late to be useful.
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

        // An absolute path may legitimately live outside the project, and a
        // substitution or variable is not a path at all until the shell runs it.
        if (str_starts_with($first, '/') || preg_match('/[$`"\'()]/', $first) === 1) {
            return null;
        }

        return $first;
    }
}
