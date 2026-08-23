<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp;

use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;
use SanderMuller\BoostPipeline\Walk\WalkStep;

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

        // Answered from the first receipt onward, not only at the end. `halted` and
        // `blocked` are both retryable now, so a run sits in them while the agent
        // decides what to do — which is exactly when "can I trust this run?" gets
        // asked. Omitting the key there left a consumer distinguishing absent from
        // false, and a run whose tree had already moved looked indistinguishable
        // from one that was simply mid-walk.
        //
        // Before any receipt exists there is genuinely nothing to answer, so the
        // key stays absent rather than claiming a verified-nothing run is false.
        if ($run->results() !== []) {
            // Never true before the walk finishes: `complete` means it finished,
            // not that everything passed, and this is what stops a consumer
            // reading the state alone as green.
            // One reading of the tree for both, so they cannot contradict each other
            // in the same payload.
            $verification = $run->verification();

            $envelope['all_verified'] = $verification['all_verified'];
            $envelope['acknowledged'] = $run->acknowledgedCount();

            // all_verified: false with no reason reads as a broken pipeline rather
            // than a stale run, and those need opposite responses.
            if ($verification['stale'] !== null) {
                $envelope['stale'] = $verification['stale'];
            }

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

        if (! $current instanceof WalkStep) {
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

            // The instruction IS the product. A step handed over as a bare
            // invocation makes the agent run a broad skill, which then presents
            // its own list of concerns — reproducing inside the step the wall of
            // context the cursor exists to break up. This field was reachable
            // from config and read by nothing, so the narrowing never happened.
            $step['instruction'] = $current->step->description();

            // Says what the server guarantees rather than what it cannot do.
            // "Recorded as acknowledged, not verified" is true, and repeating it
            // as the only note on every step framed the normal outcome for
            // judgement work as a shortfall.
            $step['note'] = 'Do this step now, then call report_step. Nothing else is handed over until you do. '
                .'The server guarantees this step was delivered on its own and in order — not that its finding is correct, '
                .'so the verdict is acknowledged rather than verified.';
        }

        return ['step' => $step];
    }
}
