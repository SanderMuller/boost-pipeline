<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Console;

use Illuminate\Console\Command;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Run\RunState;

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
    protected $signature = 'pipeline:verify
        {--only= : Ask whether this scope was verified, rather than the whole tree.}
        {--server-verified : Ask whether every verdict the server produced is a pass, setting aside steps it could only acknowledge.}';

    protected $description = 'Exit 0 only when the pipeline has verified the code currently on disk.';

    public function handle(ReceiptStore $receipts, TreeFingerprint $tree): int
    {
        $receipt = $receipts->read();

        if (! $receipt instanceof Receipt) {
            $this->components->error('No pipeline run has been recorded. Nothing has been verified.');

            return self::FAILURE;
        }

        // Nothing was recorded, so nothing was verified. `--server-verified`
        // rejects the empty set as a guard of its own; the bare call had no
        // equivalent, and answered "verified this tree: 0 step(s)" for a receipt
        // holding none. A real run cannot produce this — a receipt is only
        // written from a resolution, which always records at least one result —
        // so refusing it costs nothing and closes every way the file could
        // arrive empty at once, rather than one JSON shape at a time.
        //
        // Ahead of the tree, staleness and scope checks, which would otherwise
        // answer first and report that an empty receipt verified a different
        // tree. It verified no tree.
        if ($receipt->verdicts === []) {
            $this->components->error(sprintf(
                'Run [%s] recorded no step verdicts at all. Whatever it says about itself, there is nothing here that was verified. Open a new run.',
                $receipt->runId,
            ));

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

        $scopeFailure = $this->scopeMismatch($receipt);

        if ($scopeFailure !== null) {
            $this->components->error($scopeFailure);

            return self::FAILURE;
        }

        if ($this->option('server-verified') === true) {
            return $this->answerServerVerified($receipt, $now);
        }

        if (! $receipt->allVerified) {
            $this->components->error($this->explainUnverified($receipt));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Run [%s] verified %s: %d step(s), every one a pass the server produced.',
            $receipt->runId,
            $receipt->scope === null ? 'this tree' : "scope [{$receipt->scope}]",
            count($receipt->verdicts),
        ));

        return self::SUCCESS;
    }

    /**
     * Whether every verdict the server produced is a pass.
     *
     * A narrower question than the bare call, and the only answerable one for a
     * run that sequences agent work: an acknowledgement keeps `all_verified`
     * false forever, so the aggregate answer never changes however green the
     * shell steps are.
     *
     * Narrower is not looser. Five guards stand before the verdicts, because
     * `all_verified` was carrying several questions at once and this predicate
     * drops only the one about acknowledgements.
     */
    private function answerServerVerified(Receipt $receipt, ?string $now): int
    {
        // 1. The tree is identifiable. The bare call tolerates a missing
        //    fingerprint and answers from the receipt alone, which is defensible
        //    for a gate. It is not defensible here: this flag exists so a caller
        //    can SKIP work because the tree already matched, and with nothing to
        //    compare there is no "already".
        if ($now === null || $receipt->tree === null) {
            $this->components->error(sprintf(
                'Run [%s] cannot be tied to the code on disk: %s. This flag answers whether the tree still matches what ran, so without a fingerprint there is nothing to answer from.',
                $receipt->runId,
                $now === null
                    ? 'the working tree cannot be fingerprinted here'
                    : 'the run recorded no tree fingerprint',
            ));

            return self::FAILURE;
        }

        // 2. The walk covered the config that declared it. `all_verified` is
        //    false both for an acknowledgement and for a declared step dropped
        //    before the walk began, and nothing else on disk tells those apart.
        if ($receipt->coverage !== 'complete') {
            $this->components->error($receipt->coverage === null
                ? sprintf(
                    'Run [%s] was recorded before this command could tell a dropped gate from an acknowledged step, so it cannot answer this. Unknown coverage is not clean coverage. Open a new run.',
                    $receipt->runId,
                )
                : sprintf(
                    'Run [%s] did not cover the config that declared it: a declared step never reached the cursor, or a selected tag no step carries. What the other steps found says nothing worth having while a gate is missing. Open `status` on a live run to see which.',
                    $receipt->runId,
                ));

            return self::FAILURE;
        }

        // 3. The cursor finished. `recordReceipt()` writes after every
        //    resolution, deliberately, so a walk abandoned at step one leaves a
        //    readable receipt holding one pass and nothing else.
        if ($receipt->state !== RunState::Complete->value) {
            $this->components->error(sprintf(
                'Run [%s] is in state [%s], so the walk did not finish and the steps behind the cursor never ran. What resolved before it says nothing about them.',
                $receipt->runId,
                $receipt->state,
            ));

            return self::FAILURE;
        }

        $serverProduced = array_filter(
            $receipt->verdicts,
            static fn (string $verdict): bool => $verdict !== Verdict::Acknowledged->value,
        );

        // 4. Something was actually verified. "Every server verdict passed" is
        //    vacuously true over an empty set, so a walk of nothing but
        //    acknowledgements would pass here having verified nothing at all.
        if ($serverProduced === []) {
            $this->components->error(sprintf(
                'Run [%s] holds %d step(s) and the server produced a verdict for none of them: every one was acknowledged. There is nothing here it verified, so this cannot pass.',
                $receipt->runId,
                count($receipt->verdicts),
            ));

            return self::FAILURE;
        }

        $unverified = array_filter(
            $serverProduced,
            static fn (string $verdict): bool => Verdict::tryFrom($verdict)?->isVerified() !== true,
        );

        if ($unverified !== []) {
            $named = [];

            foreach ($unverified as $stepId => $verdict) {
                $named[] = "[{$stepId}] {$verdict}";
            }

            $this->components->error(sprintf(
                'Run [%s] did not pass every verdict the server produced. State [%s], with %s.',
                $receipt->runId,
                $receipt->state,
                implode(', ', $named),
            ));

            return self::FAILURE;
        }

        return $this->reportAssertions($receipt, array_keys($serverProduced));
    }

    /**
     * The last guard, and the success message that has to survive it.
     *
     * A pass says a step succeeded. It does not say the step checked anything: a
     * step declared `->mutating()` produced the tree rather than reading it, so
     * its pass describes the code it was handed, not the code left behind. A walk
     * holding nothing but a passing formatter used to exit 0 reporting one
     * verified step, and the only thing that ran had verified nothing.
     *
     * @param  list<string>  $serverProduced  step ids the server produced a passing verdict for
     */
    private function reportAssertions(Receipt $receipt, array $serverProduced): int
    {
        // 5. The receipt can tell a check from a rewrite. Absent means it was
        //    written before this was recorded, and unknown is never clean.
        if ($receipt->asserted === null) {
            $this->components->error(sprintf(
                'Run [%s] was recorded before this command could tell a step that checked the tree from one that rewrote it, so it cannot answer this. Open a new run.',
                $receipt->runId,
            ));

            return self::FAILURE;
        }

        $asserting = array_values(array_intersect($serverProduced, $receipt->asserted));

        if ($asserting === []) {
            $this->components->error(sprintf(
                'Run [%s] passed %d step(s), and every one of them rewrites the tree rather than checking it. A formatter reports that it ran, never that the result is correct, so there is nothing here to verify against this code.',
                $receipt->runId,
                count($serverProduced),
            ));

            return self::FAILURE;
        }

        $rewrote = count($serverProduced) - count($asserting);
        $acknowledged = count($receipt->verdicts) - count($serverProduced);

        $this->components->info(sprintf(
            'Run [%s] passed all %d step(s) the server verified against this tree%s: %s.%s%s',
            $receipt->runId,
            count($asserting),
            $receipt->scope === null ? '' : " in scope [{$receipt->scope}]",
            // Named, because exit 0 alone never said WHICH checks ran. A caller
            // skipping work on the strength of it would otherwise be skipping
            // checks this pipeline may not hold at all.
            '['.implode('], [', $asserting).']',
            $rewrote === 0
                ? ''
                : sprintf(' %d step(s) rewrote the tree rather than checking it and are not counted.', $rewrote),
            $acknowledged === 0
                ? ''
                : sprintf(' %d step(s) were only acknowledged and are not counted, so this is not a claim that the tree is verified.', $acknowledged),
        ));

        return self::SUCCESS;
    }

    /**
     * Whether the run's coverage falls short of what was asked, and why.
     *
     * Coverage, not equality. An unscoped run walked every step, so it answers a
     * question about any single scope as well. A scoped run answers only its own,
     * and never the question "is this tree verified?", which is what a bare call
     * asks.
     */
    private function scopeMismatch(Receipt $receipt): ?string
    {
        $asked = $this->option('only');
        $asked = is_string($asked) && trim($asked) !== '' ? $asked : null;

        if ($receipt->scope === null) {
            return null;
        }

        if ($asked === null) {
            return sprintf(
                'Run [%s] verified scope [%s], not this whole tree, so it cannot answer whether the tree is verified. Ask about the scope with --only=%s, or open an unscoped run.',
                $receipt->runId,
                $receipt->scope,
                $receipt->scope,
            );
        }

        if ($asked !== $receipt->scope) {
            return sprintf(
                'Run [%s] verified scope [%s], and the question was about scope [%s]. Nothing here says anything about [%s].',
                $receipt->runId,
                $receipt->scope,
                $asked,
                $asked,
            );
        }

        return null;
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
            // Not "finished": a blocked or halted run is retryable, and saying it
            // finished contradicts the server, which hands the same step back on
            // the next call.
            $named = [];

            foreach ($unverified as $stepId => $verdict) {
                $named[] = "[{$stepId}] {$verdict}";
            }

            return sprintf(
                'Run [%s] has not verified every step. State [%s]%s.',
                $receipt->runId,
                $receipt->state,
                $named === [] ? '' : ', with '.implode(', ', $named),
            );
        }

        return sprintf(
            'Run [%s] passed every step the server ran, but %s only acknowledged, never verified, so this command cannot exit 0. '
            .'That is expected for a pipeline that sequences agent work: judgement leaves nothing to check, and the walk still '
            .'guarantees the order and the one-at-a-time delivery. Read the run with `status`%s.',
            $receipt->runId,
            count($acknowledged) === 1
                ? sprintf('step [%s] was', $acknowledged[0])
                : sprintf('%d steps ([%s]) were', count($acknowledged), implode('], [', $acknowledged)),
            ', and where a step leaves an artifact behind, Skill::proving() can turn that one into a verified pass',
        );
    }
}
