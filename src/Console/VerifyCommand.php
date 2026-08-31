<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Console;

use Illuminate\Console\Command;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Run\ReceiptStoreFactory;
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
        {--pipeline= : Which pipeline to ask about. Required when the project declares more than one.}
        {--only= : Ask whether this scope was verified, rather than the whole tree.}
        {--server-verified : Ask whether every verdict the server produced is a pass, setting aside steps it could only acknowledge.}';

    protected $description = 'Exit 0 only when the pipeline has verified the code currently on disk.';

    public function handle(Pipelines $pipelines, ReceiptStoreFactory $receipts, TreeFingerprint $tree): int
    {
        $name = $this->pipelineName($pipelines);

        if ($name === null) {
            return self::FAILURE;
        }

        $receipt = $receipts->for($name)->read();

        if (! $receipt instanceof Receipt) {
            $this->components->error($this->nothingRecorded());

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

        // Named here as well as in the receipt's `stale` message, because these
        // two reach different readers: `stale` reaches an agent mid-walk, this
        // reaches a person at a gate. Merging a base branch in before opening a
        // PR is the ordinary way to arrive here having changed no file.
        $now = $tree->capture();

        if ($now !== null && $receipt->tree !== null && $receipt->tree !== $now) {
            $this->components->error(sprintf(
                'Run [%s] verified a different working tree, so its result does not describe this code. A commit, amend, checkout or rebase counts as much as an edit does — the fingerprint covers the commit — so there may be nothing to hunt for and nothing to undo. Open a new run.',
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

        $uncoveredFailure = $this->declaredButNeverRecorded($pipelines, $name, $receipt);

        if ($uncoveredFailure !== null) {
            $this->components->error($uncoveredFailure);

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
     * Why there is no answer — and, after an upgrade, why that is not a fault.
     *
     * Receipts moved to `receipts/<name>.json` in 0.10.0 and the old file is not
     * read. Reporting that as "nothing has ever been verified" is true and
     * useless: it reads as a broken gate, and the reader diagnoses a move that
     * this command already knows about.
     */
    private function nothingRecorded(): string
    {
        $legacy = $this->laravel->storagePath(JsonReceiptStore::LEGACY_PATH);

        if (! is_file($legacy)) {
            return 'No pipeline run has been recorded. Nothing has been verified.';
        }

        return sprintf(
            'No pipeline run has been recorded here. A receipt written before 0.10.0 is still at [%s], and is deliberately not read: it predates the keys this command needs, and unknown is not clean. Open a new run — then that file is safe to delete.',
            $legacy,
        );
    }

    /**
     * The name of the pipeline the caller meant, or null with the reason
     * printed.
     *
     * A project declaring several pipelines has no single answer to "is this tree
     * verified?" — the same rule a scoped receipt already follows, one level up.
     * Naming them is the useful half of refusing: a caller who did not know the
     * project had three now does, and knows what to ask for.
     *
     * There is deliberately no aggregate "every pipeline is green" answer. A
     * project that routinely runs only its PR pipeline could never reach exit 0
     * through it, and a gate that cannot pass is one people learn to skip.
     */
    private function pipelineName(Pipelines $pipelines): ?string
    {
        $asked = $this->option('pipeline');
        $asked = is_string($asked) && trim($asked) !== '' ? $asked : null;

        if ($asked === null) {
            // Counts, not declaration shape: a map holding one pipeline still has
            // exactly one answer to "is this tree verified".
            $implied = $pipelines->soleName();

            if ($implied === null) {
                $this->components->error(sprintf(
                    'This project declares %d pipelines [%s], so "is this tree verified" has no single answer. Ask about one with --pipeline=%s.',
                    count($pipelines->names()),
                    implode('], [', $pipelines->names()),
                    $pipelines->names()[0] ?? '',
                ));

                return null;
            }

            return $implied;
        }

        if (! $pipelines->has($asked)) {
            $this->components->error(sprintf(
                'No pipeline named [%s] is configured. This project declares [%s].',
                $asked,
                implode('], [', $pipelines->names()),
            ));

            return null;
        }

        return $asked;
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

    /** The scope the caller asked about, or null for the whole tree. */
    private function askedScope(): ?string
    {
        $asked = $this->option('only');

        return is_string($asked) && trim($asked) !== '' ? $asked : null;
    }

    /**
     * Steps the config declares now that the run recorded no verdict for.
     *
     * The receipt cannot answer this and never could. `coverage` is written from
     * the walk's own notices, so it reports a step the server LOADED and then
     * dropped. A step the server never loaded raises no notice, leaves no
     * verdict, and lands in a receipt that calls itself complete.
     *
     * That is reachable in ordinary use: the MCP server resolves the config once
     * when its process starts, so a step declared after that is invisible to
     * every run until the client reconnects. The tree fingerprint does not catch
     * it either — the run ran against the tree that already held the new step, so
     * the fingerprints match.
     *
     * This command is the one place that can answer it. It runs in its own
     * process and loads the config as it stands now, so it can compare what is
     * declared against what was recorded. The page and `pipeline:history` already
     * show the same gap per step; until this guard, a gate reading the exit code
     * was the only reader told nothing.
     *
     * Declared-now must be a subset of recorded. A verdict for a step no longer
     * declared does not fail: the question is whether the run covers what the
     * config asks for today, and a step since removed asks for nothing. A renamed
     * step still fails, through its new id.
     *
     * "Declared" is read in the scope the answer is about — the receipt's own for
     * a scoped run, otherwise the one the caller asked about.
     */
    private function declaredButNeverRecorded(Pipelines $pipelines, string $name, Receipt $receipt): ?string
    {
        // Only a finished run. An unfinished one is missing verdicts because the
        // cursor never reached them, and `all_verified` and the state guard
        // already say so in the terms that fit it.
        if ($receipt->state !== RunState::Complete->value) {
            return null;
        }

        $pipeline = $pipelines->get($name);

        if (! $pipeline instanceof Pipeline) {
            return sprintf(
                'No pipeline named [%s] is configured any more, so there is nothing to check run [%s] against.',
                $name,
                $receipt->runId,
            );
        }

        // A scoped run leaves its out-of-scope steps out deliberately, so its own
        // scope decides the comparison — the whole walk would fail every scoped
        // run. An UNSCOPED run walked everything, so it answers a question about
        // any single scope, and the question asked is the one to compare against:
        // measuring it against the whole walk would fail `--only=backend` because
        // the config gained a frontend step, which says nothing about backend.
        $scope = $receipt->scope ?? $this->askedScope();

        // Resolving the walk is where a duplicate step id is caught — `Pipelines`
        // validates names and types, not step ids — so this is the first point in
        // the command that can fail on a config it could previously ignore. It
        // refuses rather than throwing, because a gate wants an answer and not a
        // stack trace, and refusing is the right answer anyway: a config that
        // cannot be walked is one the server would refuse to run.
        try {
            $walk = $pipeline->walk($scope);
        } catch (InvalidPipelineConfigException $invalidPipelineConfigException) {
            return sprintf(
                'Run [%s] cannot be checked against this config, because the config cannot be resolved: %s',
                $receipt->runId,
                $invalidPipelineConfigException->getMessage(),
            );
        }

        // A step this config DROPS never reaches the comparison below, so counting
        // step ids finds nothing wrong and passes a config that declares a gate
        // nothing can reach. A step declared into a phase nothing registers is
        // dropped that way, and the walk reports it as a notice.
        //
        // Only for a whole-tree question, where every declared step is in scope and
        // a notice therefore always describes one. A scoped question cannot use
        // this: `noticesForUnregisteredPhases()` ignores the selection, so a broken
        // step in another scope would fail an answer that has nothing to do with
        // it — and for a selection, a notice can instead mean the tag matches no
        // step, which drops nothing at all. That leaves one narrow gap: a scoped
        // question does not detect a step dropped into an unregistered phase. It
        // takes a stale server to reach, because a run against this config raises
        // the same notice and records `coverage: incomplete`, which already fails.
        if ($scope === null && $walk->notices !== []) {
            return sprintf(
                'Run [%s] cannot be checked against this config, because resolving it drops a step the config declares: %s',
                $receipt->runId,
                implode(' ', $walk->notices),
            );
        }

        $missing = [];

        foreach ($walk->steps as $walkStep) {
            $stepId = $walkStep->step->id();

            // Presence, not verdict. An acknowledged step did reach the cursor;
            // whether an acknowledgement is good enough is a different question,
            // and `all_verified` owns it.
            if (! array_key_exists($stepId, $receipt->verdicts)) {
                $missing[] = $stepId;
            }
        }

        if ($missing === []) {
            return null;
        }

        return sprintf(
            'Run [%s] never held %d step(s) this pipeline declares%s: [%s]. The run reports itself complete because it walked every step it knew about — these were not among them, so nothing failed and nothing was skipped. The usual cause is a step declared after the MCP server process started, which resolves the config once: reconnect the client, then open a new run.',
            $receipt->runId,
            count($missing),
            $receipt->scope === null ? '' : " in scope [{$receipt->scope}]",
            implode('], [', $missing),
        );
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
        $asked = $this->askedScope();

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
