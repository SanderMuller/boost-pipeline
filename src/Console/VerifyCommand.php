<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Console;

use Illuminate\Console\Command;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
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
            $this->components->error(sprintf(
                'Run [%s] finished in state [%s] without verifying every step.',
                $receipt->runId,
                $receipt->state,
            ));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Run [%s] verified this tree: %d step(s), every one a pass the server produced.',
            $receipt->runId,
            count($receipt->verdicts),
        ));

        return self::SUCCESS;
    }
}
