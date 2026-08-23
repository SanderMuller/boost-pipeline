<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Console;

use Illuminate\Console\Command;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Run\Receipt;

/**
 * Answers "has this tree been verified?" with an exit code.
 *
 * The point of the exit code is that a consumer needs no knowledge of this
 * package: a CI job, a PR gate or a skill runs one command and reads 0 or 1.
 * Until this existed every guarantee the server produced died with the session
 * that produced it.
 *
 * NO RUN IS A FAILURE. That is the whole reason for the command. A gate that
 * treats a missing answer as "nothing to check" passes exactly the case it exists
 * to catch — the run that never happened.
 */
final class VerifyCommand extends Command
{
    protected $signature = 'pipeline:verify';

    protected $description = 'Exit 0 only when the pipeline has verified the code currently on disk.';

    public function handle(ReceiptStore $receipts, TreeFingerprint $tree): int
    {
        $receipt = $receipts->read();

        if (! $receipt instanceof Receipt) {
            $this->components->error('No pipeline run has been recorded. Nothing has been verified.');

            return self::FAILURE;
        }

        $now = $tree->capture();

        if ($now !== null && $receipt->tree !== null && $receipt->tree !== $now) {
            $this->components->error(sprintf(
                'Run [%s] verified a different working tree, so its result does not describe this code. Open a new run.',
                $receipt->runId,
            ));

            return self::FAILURE;
        }

        if ($receipt->stale !== null) {
            $this->components->error($receipt->stale);

            return self::FAILURE;
        }

        if (! $receipt->allVerified) {
            $this->components->error($this->explainUnverified($receipt));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Run [%s] verified this tree: %d step(s), every one a pass the server produced.',
            $receipt->runId,
            count($receipt->verdicts),
        ));

        return self::SUCCESS;
    }

    /**
     * Why the run is not verified — and whether re-running could change that.
     *
     * These are two different answers wearing one message. A failed step is
     * fixable: fix it, run again, exit 0. An acknowledged step is structural —
     * the server never verified it and never will, so this pipeline cannot exit 0
     * however many times it runs. A consumer told only "without verifying every
     * step" reads the second as the first, wires up a gate that can never pass,
     * and learns to skip it. That is worse than having no gate.
     */
    private function explainUnverified(Receipt $receipt): string
    {
        $acknowledged = array_keys(array_filter(
            $receipt->verdicts,
            static fn (string $verdict): bool => $verdict === Verdict::Acknowledged->value,
        ));

        $unverified = array_filter(
            $receipt->verdicts,
            static fn (string $verdict): bool => $verdict !== Verdict::Passed->value
                && $verdict !== Verdict::Acknowledged->value,
        );

        if ($acknowledged === [] || $unverified !== []) {
            return sprintf(
                'Run [%s] finished in state [%s] without verifying every step.',
                $receipt->runId,
                $receipt->state,
            );
        }

        return sprintf(
            'Run [%s] passed every step the server ran, but %s only acknowledged, never verified. '
            .'This pipeline cannot verify a tree: give %s a proof with Skill::proving(), or gate those steps outside the pipeline.',
            $receipt->runId,
            count($acknowledged) === 1
                ? sprintf('step [%s] was', $acknowledged[0])
                : sprintf('%d steps were ([%s])', count($acknowledged), implode('], [', $acknowledged)),
            count($acknowledged) === 1 ? 'it' : 'them',
        );
    }
}
