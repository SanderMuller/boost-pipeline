<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp;

use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Run\RunState;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;

/**
 * Builds every tool response body, in one place.
 *
 * Two rules hold here and nowhere else, so they cannot drift per tool:
 * no payload ever names a step beyond the cursor, and any payload whose state is
 * `complete` also carries `all_verified`.
 */
final readonly class StepPayload
{
    /** @return array<string, mixed> */
    public static function opened(Run $run): array
    {
        return [
            ...self::envelope($run),
            'total_steps' => $run->walk->count(),
            ...self::currentStep($run),
        ];
    }

    /** @return array<string, mixed> */
    public static function afterResolution(Run $run, Result $result): array
    {
        return [
            ...self::envelope($run),
            'result' => $result->toArray(),
            ...self::currentStep($run),
        ];
    }

    /** @return array<string, mixed> */
    public static function awaiting(Run $run): array
    {
        return [
            ...self::envelope($run),
            ...self::currentStep($run),
        ];
    }

    /** @return array<string, mixed> */
    public static function complete(Run $run): array
    {
        return self::envelope($run);
    }

    /** @return array<string, mixed> */
    public static function status(Run $run): array
    {
        $steps = [];

        foreach ($run->results() as $stepId => $result) {
            $entry = [
                'id' => $stepId,
                'verdict' => $result->verdict->value,
                'server_run' => $result->serverRun(),
            ];

            if ($result->filesInspected !== null) {
                $entry['files_inspected'] = $result->filesInspected;
            }

            if ($result->logPath !== null) {
                $entry['log'] = $result->logPath;
            }

            $steps[] = $entry;
        }

        return [
            ...self::envelope($run),
            // Separate keys, never one tally: "who produced the verdict" and "did
            // it pass" are different questions, and a consumer must not be able
            // to total verified and acknowledged work by accident.
            'server_run' => $run->serverRunTally(),
            'acknowledged' => $run->acknowledgedCount(),
            'steps' => $steps,
            ...self::currentStep($run),
        ];
    }

    /** @return array<string, mixed> */
    private static function envelope(Run $run): array
    {
        $envelope = [
            'run' => $run->id,
            'state' => $run->state()->value,
            'position' => $run->position(),
        ];

        // `complete` means the walk finished, not that everything passed. Shipping
        // all_verified alongside it is what stops a consumer reading the state
        // alone as green.
        if ($run->state() === RunState::Complete) {
            $envelope['all_verified'] = $run->allVerified();
            $envelope['acknowledged'] = $run->acknowledgedCount();

            // Without this, a run that dropped a declared step reports
            // all_verified: false with no way to see why.
            if ($run->walk->notices !== []) {
                $envelope['notices'] = $run->walk->notices;
            }
        }

        return $envelope;
    }

    /** @return array<string, mixed> */
    private static function currentStep(Run $run): array
    {
        $current = $run->currentStep();

        if ($current === null) {
            return [];
        }

        $step = [
            'id' => $current->step->id(),
            'phase' => $current->phaseName,
            'kind' => $current->step->kind()->value,
        ];

        if ($current->step instanceof Shell) {
            $step['command'] = $current->step->command();
        }

        if ($current->step instanceof Skill) {
            $step['invoke'] = $current->step->invocation();
            $step['note'] = 'Acknowledge with report_step when done. This step is recorded as acknowledged, not verified.';
        }

        return ['step' => $step];
    }
}
