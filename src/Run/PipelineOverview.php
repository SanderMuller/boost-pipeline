<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineFingerprint;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Runner\TreeDigest;
use SanderMuller\BoostPipeline\Walk\Walk;
use SanderMuller\BoostPipeline\Walk\WalkStep;

/**
 * @phpstan-type StepRow array{id: string, phase: string, kind: string, description: string, tags: list<string>, verdict: string|null, log: string|null}
 * @phpstan-type PositionRow array{phase: string, parallel: bool, steps: non-empty-list<StepRow>}
 * @phpstan-type UndeclaredRow array{id: string, verdict: string, log: string|null}
 * @phpstan-type RunRow array{run: string, state: string, all_verified: bool, stale: string|null, scope: string|null, recorded_at: string, coverage: string|null, tree_matches: bool|null, config_matches: bool|null, positions: list<PositionRow>, undeclared: list<UndeclaredRow>}
 * @phpstan-type LiveRow array{run: string, state: string, steps: list<string>, scope: string|null, started_at: string, interrupted: bool, config_matches: bool|null}
 * @phpstan-type HistoryRow array{run: string, state: string, all_verified: bool, scope: string|null, recorded_at: string, tree_matches: bool|null, config_matches: bool|null, verdicts: array<string, int>}
 * @phpstan-type PipelineRow array{pipeline: string, current: RunRow|null, live: LiveRow|null, history: list<HistoryRow>}
 * @phpstan-type DeclarationRow array{pipeline: string, purpose: string|null, scopes: list<string>, positions: list<PositionRow>, dropped: list<array{id: string, phase: string}>}
 *
 * What a reader should be told about each declared pipeline.
 *
 * One answer, three surfaces: the page polls it, `pipeline:history` prints it,
 * and `pipeline:list` prints the declaration half alone. Two readers of the same
 * files would drift, and the first symptom is a terminal and a browser
 * disagreeing about one run — or, for the declaration half, two commands
 * disagreeing about which steps share a position.
 *
 * Everything it returns is a plain array. The stores and the walk are the
 * contracts; this is a projection over them, not a third thing to keep in step.
 */
final readonly class PipelineOverview
{
    public function __construct(
        private Pipelines $pipelines,
        private ReceiptStoreFactory $receipts,
        private RunHistoryStoreFactory $history,
        private LiveProgressStoreFactory $live,
        private ?TreeFingerprint $tree = null,
    ) {}

    /**
     * Every declared pipeline, in declaration order.
     *
     * @return list<PipelineRow>
     */
    public function all(): array
    {
        return array_map($this->forPipeline(...), $this->pipelines->names());
    }

    /**
     * @return PipelineRow
     */
    public function forPipeline(string $name, ?int $historyLimit = null): array
    {
        $receipt = $this->receipts->for($name)->read();
        $live = $this->currentLive($name);

        return [
            'pipeline' => $name,
            'current' => $receipt instanceof Receipt
                ? $this->describe($name, $receipt, $this->logsFor($name, $receipt->runId))
                : null,
            'live' => $live,
            'history' => $this->historySummaries($name, $historyLimit),
        ];
    }

    /**
     * One past run, joined the way the current one is.
     *
     * Its own scope decides the walk, never the current receipt's: a past run may
     * have walked a narrower selection, and reusing today's would label its steps
     * wrongly.
     *
     * @return RunRow|null
     */
    public function run(string $pipeline, string $runId): ?array
    {
        $record = $this->history->for($pipeline)->read($runId);

        return $record instanceof HistoryRecord
            ? $this->describe($pipeline, $record->receipt, $record->logs)
            : null;
    }

    /**
     * Where the current run wrote each step's log.
     *
     * The receipt does not carry them — history does — so the current run would
     * otherwise be the one view with no log links, which is the run a reader is
     * most likely to want them for.
     *
     * @return array<string, string|null>
     */
    private function logsFor(string $name, string $runId): array
    {
        $record = $this->history->for($name)->read($runId);

        return $record instanceof HistoryRecord ? $record->logs : [];
    }

    /**
     * The in-flight record, once expiry has been applied.
     *
     * A record is authoritative on its own — a run's first position starts before
     * any receipt exists, which is exactly the moment worth watching — so it is
     * never required to match one.
     *
     * @return LiveRow|null
     */
    private function currentLive(string $name): ?array
    {
        $progress = $this->live->for($name)->read();

        if (! $progress instanceof LiveProgress) {
            return null;
        }

        return [
            'run' => $progress->runId,
            'state' => $progress->state->value,
            'steps' => $progress->stepIds,
            'scope' => $progress->scope,
            'started_at' => $progress->startedAt,
            // A running record past the ceiling its runner enforces means the
            // process died. An awaiting one never expires, by design.
            'interrupted' => $progress->hasExpired(),
            // Worth knowing before the run finishes: the steps left to walk are
            // the ones a reader could still stop.
            'config_matches' => $this->digestMatches($name, $progress->configDigest),
        ];
    }

    /**
     * What each declared pipeline would do, with no run behind it.
     *
     * The other methods here answer "what happened", and every one of them starts
     * from a stored record — so a pipeline that has never run has nothing to say
     * through them, which is exactly the pipeline a reader choosing between
     * several most needs described. This reads the config alone: no receipt, no
     * history, no live record.
     *
     * Unscoped, always. A scoped walk would describe one selection while the
     * listing's whole job is to show which selections exist.
     *
     * @return list<DeclarationRow>
     */
    public function declarations(): array
    {
        return array_map($this->declarationOf(...), $this->pipelines->names());
    }

    /** @return DeclarationRow */
    private function declarationOf(string $name): array
    {
        $pipeline = $this->pipelines->get($name);
        $walk = $pipeline instanceof Pipeline ? $pipeline->walk() : null;
        $steps = $walk instanceof Walk ? $walk->steps : [];

        return [
            'pipeline' => $name,
            'purpose' => $pipeline instanceof Pipeline ? $pipeline->purpose() : null,
            'scopes' => $this->scopes($steps),
            'positions' => $this->positions($steps, [], []),
            // A step declared into an unregistered phase never runs. Omitting it
            // would let the listing describe a pipeline that does more than it
            // does, which is the one way a listing can actively mislead.
            'dropped' => $walk instanceof Walk ? $walk->dropped : [],
        ];
    }

    /**
     * Every tag some step carries, sorted, without repeats.
     *
     * These are the values `--only` accepts. Derived rather than declared: a tag
     * exists because a step carries it, so a separate list would be a second
     * source of truth that can disagree with the steps.
     *
     * @param  list<WalkStep>  $steps
     * @return list<string>
     */
    private function scopes(array $steps): array
    {
        $tags = [];

        foreach ($steps as $walkStep) {
            foreach ($walkStep->step->tags() as $tag) {
                $tags[$tag] = true;
            }
        }

        // Cast back, because a tag of "2" arrives as an int: PHP coerces
        // numeric-string array keys, and dedupe here needs keys. `tagged()`
        // refuses only a blank tag, so a numeric one is a legal config, and this
        // list is declared `list<string>` for consumers reading it under
        // strict_types. Same coercion `Receipt::readVerdicts()` undoes for ids.
        $scopes = array_map(static fn (string|int $tag): string => (string) $tag, array_keys($tags));
        sort($scopes);

        return $scopes;
    }

    /**
     * @param  array<string, string|null>  $logs
     * @return RunRow
     */
    private function describe(string $name, Receipt $receipt, array $logs): array
    {
        $pipeline = $this->pipelines->get($name);
        $walk = $pipeline instanceof Pipeline ? $pipeline->walk($receipt->scope) : null;
        $steps = $walk instanceof Walk ? $walk->steps : [];

        return [
            'run' => $receipt->runId,
            'state' => $receipt->state,
            'all_verified' => $receipt->allVerified,
            'stale' => $receipt->stale,
            'scope' => $receipt->scope,
            'recorded_at' => $receipt->recordedAt,
            'coverage' => $receipt->coverage,
            'tree_matches' => $this->treeMatches($receipt),
            'config_matches' => $this->configMatches($name, $receipt),
            'positions' => $this->positions($steps, $receipt->verdicts, $logs),
            'undeclared' => $this->undeclared($steps, $receipt, $logs),
        ];
    }

    /**
     * The walk grouped the way the run reports it: one entry per position, so a
     * parallel group reads as the single unit it resolved as.
     *
     * The walk comes from the config as it stands now, never from the record —
     * nothing stores the step list a past run walked. A step added since shows as
     * never run, and one removed since surfaces under `undeclared`.
     *
     * Verdicts and logs arrive as maps rather than as a `Receipt`, so the same
     * grouping serves a declaration that has no run behind it. Both are empty
     * there, and every step reads as never run — which is the truth.
     *
     * @param  list<WalkStep>  $steps
     * @param  array<string, string>  $verdicts
     * @param  array<string, string|null>  $logs
     * @return list<PositionRow>
     */
    private function positions(array $steps, array $verdicts, array $logs): array
    {
        $positions = [];

        foreach ($steps as $walkStep) {
            $key = $walkStep->batchId === null
                ? 'step:'.$walkStep->step->id()
                : 'batch:'.$walkStep->batchId;

            // Built key by key rather than spread from WalkStep::toArray(): that
            // returns an open map, and both readers of this projection index into
            // it by name.
            $positions[$key][] = [
                'id' => $walkStep->step->id(),
                'phase' => $walkStep->phaseName,
                'kind' => $walkStep->step->kind()->value,
                'description' => $walkStep->step->description(),
                // The scopes this step belongs to, which is the one thing a reader
                // cannot work out from a step list: it decides what `--only` runs.
                'tags' => $walkStep->step->tags(),
                'verdict' => $verdicts[$walkStep->step->id()] ?? null,
                'log' => $logs[$walkStep->step->id()] ?? null,
            ];
        }

        return array_values(array_map(
            static fn (array $steps): array => [
                'phase' => $steps[0]['phase'],
                'parallel' => count($steps) > 1,
                'steps' => $steps,
            ],
            $positions,
        ));
    }

    /**
     * Verdicts whose step the walk no longer holds.
     *
     * Shown rather than dropped: the config changed since the run, and silently
     * hiding what it recorded would make the run look smaller than it was.
     *
     * @param  list<WalkStep>  $steps
     * @param  array<string, string|null>  $logs
     * @return list<UndeclaredRow>
     */
    private function undeclared(array $steps, Receipt $receipt, array $logs): array
    {
        $declared = array_map(static fn (WalkStep $walkStep): string => $walkStep->step->id(), $steps);
        $undeclared = [];

        foreach ($receipt->verdicts as $stepId => $verdict) {
            if (! in_array($stepId, $declared, true)) {
                $undeclared[] = ['id' => $stepId, 'verdict' => $verdict, 'log' => $logs[$stepId] ?? null];
            }
        }

        return $undeclared;
    }

    /**
     * Whether the run walked the declaration this config still produces.
     *
     * Null when there is nothing to compare — a receipt written before the digest
     * existed, or a pipeline the config no longer declares. Never false for
     * unknown: a reader shown "no" for a run that simply predates the field would
     * go looking for a config change that never happened.
     *
     * False is the one thing `tree_matches` cannot tell them. A server holding an
     * older config runs a different definition of the same step id, and the tree
     * still matches because the run ran against the tree that already held the new
     * config.
     */
    private function configMatches(string $name, Receipt $receipt): ?bool
    {
        return $this->digestMatches($name, $receipt->config);
    }

    /** @param string|null $digest what the run recorded, or null when it recorded none */
    private function digestMatches(string $name, ?string $digest): ?bool
    {
        if ($digest === null) {
            return null;
        }

        $pipeline = $this->pipelines->get($name);

        // Three answers collapse into the two this projection already had: null
        // covers both "recorded nothing" and "recorded a format this build cannot
        // reproduce", because a reader shown false for either would go looking for
        // a config change that never happened. Every surface here already renders
        // null as unknown rather than as mismatched.
        return $pipeline instanceof Pipeline ? PipelineFingerprint::matches($pipeline, $digest) : null;
    }

    /**
     * Whether the receipt still describes the code on disk.
     *
     * Null when there is nothing to compare — no fingerprint available, or a
     * receipt that recorded none. Unknown is not the same answer as no.
     */
    private function treeMatches(Receipt $receipt): ?bool
    {
        if (! $this->tree instanceof TreeFingerprint || $receipt->tree === null) {
            return null;
        }

        // Content, not the whole digest. A receipt written before a commit still
        // describes the code the commit carries, and reporting it as moved there
        // was the false stale this projection exists to avoid.
        return TreeDigest::sameContent($this->tree->capture(), $receipt->tree);
    }

    /**
     * The listing stays cheap: no walk is resolved per row, because the full join
     * happens only for the record a reader actually opens.
     *
     * @return list<HistoryRow>
     */
    private function historySummaries(string $name, ?int $limit = null): array
    {
        return array_map(
            fn (HistoryRecord $record): array => [
                'run' => $record->receipt->runId,
                'state' => $record->receipt->state,
                'all_verified' => $record->receipt->allVerified,
                'scope' => $record->receipt->scope,
                'recorded_at' => $record->receipt->recordedAt,
                'tree_matches' => $this->treeMatches($record->receipt),
                // A list row without this reads worst for the run that most needs
                // attention: a stale declaration leaves the tree matching, so the
                // one run the gate refuses is the only row claiming to be healthy.
                'config_matches' => $this->digestMatches($name, $record->receipt->config),
                'verdicts' => array_count_values($record->receipt->verdicts),
            ],
            $this->history->for($name)->all($limit),
        );
    }
}
