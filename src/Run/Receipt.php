<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

/**
 * A run's outcome, in a form something outside the session can read.
 *
 * Run state is otherwise in-process, so every guarantee the server produced died
 * with the session that produced it: no CI job, skill or PR gate could ask
 * whether the pipeline passed for a given tree.
 *
 * WHAT THIS IS NOT. A receipt is a file in the working copy, so anything that can
 * run a shell step can write one. It is not evidence that a run happened, and it
 * closes no trust hole — an agent able to fake it was already able to claim a
 * pass in prose. What it does carry is the part prose could never get right: the
 * tree the verdicts were measured against, so a reader can tell a current pass
 * from a stale one without asking anybody. A consumer that must not trust the
 * working copy runs the pipeline itself; that has not changed.
 */
final readonly class Receipt
{
    /**
     * @param  array<string, string>  $verdicts  step id => verdict
     */
    public function __construct(
        public string $runId,
        public string $state,
        public bool $allVerified,
        public ?string $tree,
        public ?string $stale,
        public array $verdicts,
        public string $recordedAt,
        /**
         * The tag this run was scoped to, null when it walked everything.
         *
         * Without this a scoped pass is indistinguishable from a full one, and a
         * gate reading the exit code would treat "the backend is fine" as "the
         * tree is verified".
         */
        public ?string $scope = null,
        /**
         * Whether the walk covered the config that declared it.
         *
         * `all_verified` is false for two unrelated reasons: a step the server
         * could only acknowledge, and a declared step dropped before the walk
         * began. Only the first is benign, and nothing on disk told them apart,
         * so a reader willing to accept acknowledgements had no way to still
         * refuse a run that never held a gate its config declared.
         *
         * `complete` means the walk raised no coverage notice. It is not a claim
         * that every declared step ran: a scoped run leaves its out-of-scope
         * steps out deliberately and silently. `scope` answers what the run was
         * about; this answers whether anything went missing by accident.
         *
         * Absent means unknown, never clean. A receipt written before this
         * existed did record notices in memory and dropped them on the way to
         * disk.
         */
        public ?string $coverage = null,
        /**
         * The step ids whose pass asserted the state of the tree.
         *
         * A verdict answers whether a step succeeded. It does not answer whether
         * anything checked the code that is on disk now, and for a step declared
         * `->mutating()` those come apart: the step produced the tree rather than
         * reading it, so its pass says nothing about the result. `Run` already
         * knows the difference — it excludes such a step from staleness for the
         * same reason — and until this key the receipt threw that away.
         *
         * Without it a walk holding nothing but a passing formatter reported one
         * verified step, and the only thing that ran had checked nothing.
         *
         * An empty list is a real answer: the run asserted nothing. Absent is
         * unknown, and unknown is never clean.
         *
         * @var list<string>|null
         */
        public ?array $asserted = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'run' => $this->runId,
            'state' => $this->state,
            'all_verified' => $this->allVerified,
            'tree' => $this->tree,
            'stale' => $this->stale,
            'verdicts' => $this->verdicts,
            'recorded_at' => $this->recordedAt,
            'scope' => $this->scope,
            'coverage' => $this->coverage,
            'asserted' => $this->asserted,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $runId = $data['run'] ?? null;
        $state = $data['state'] ?? null;

        // A receipt missing either of those is not a partially-usable receipt, it
        // is a file that happens to be JSON.
        if (! is_string($runId) || ! is_string($state)) {
            return null;
        }

        if (! self::fieldsAreWellFormed($data)) {
            return null;
        }

        $verdicts = self::readVerdicts($data['verdicts'] ?? null);

        if ($verdicts === null) {
            return null;
        }

        $tree = $data['tree'] ?? null;
        $stale = $data['stale'] ?? null;
        $recordedAt = $data['recorded_at'] ?? '';
        $scope = $data['scope'] ?? null;
        $coverage = $data['coverage'] ?? null;

        return new self(
            runId: $runId,
            state: $state,
            allVerified: (bool) ($data['all_verified'] ?? false),
            tree: is_string($tree) ? $tree : null,
            stale: is_string($stale) ? $stale : null,
            verdicts: $verdicts,
            recordedAt: is_string($recordedAt) ? $recordedAt : '',
            scope: is_string($scope) ? $scope : null,
            coverage: is_string($coverage) ? $coverage : null,
            asserted: self::readAsserted($data['asserted'] ?? null),
        );
    }

    /**
     * Whether every optional field is absent or holds the type it declares.
     *
     * Coercing a present-but-malformed value to null was the permissive direction
     * on all of these: a bad `stale` read as not stale, a bad `scope` let a
     * partial run answer a whole-tree question, and a bad `tree` removed the
     * fingerprint comparison entirely. That is the laundering rejecting a
     * malformed verdict map exists to stop, and these fields decide whether the
     * verdicts apply at all.
     *
     * Absent still means absent. Only a present key of the wrong type rejects.
     *
     * @param  array<mixed, mixed>  $data
     */
    private static function fieldsAreWellFormed(array $data): bool
    {
        foreach (['tree', 'stale', 'recorded_at', 'scope', 'coverage'] as $key) {
            $value = $data[$key] ?? null;

            if ($value !== null && ! is_string($value)) {
                return false;
            }
        }

        $allVerified = $data['all_verified'] ?? null;

        if ($allVerified !== null && ! is_bool($allVerified)) {
            return false;
        }

        $asserted = $data['asserted'] ?? null;

        if ($asserted === null) {
            return true;
        }

        if (! is_array($asserted)) {
            return false;
        }

        return array_all($asserted, static fn (mixed $stepId): bool => is_string($stepId) || is_int($stepId));
    }

    /**
     * @return array<string, string>|null null rejects the whole receipt
     */
    private static function readVerdicts(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return [];
        }

        $verdicts = [];

        foreach ($raw as $stepId => $verdict) {
            // Dropping a malformed entry instead would hand back a receipt
            // holding only what happened to survive, and a predicate reading it
            // would pass a run whose broken half went missing on the way in. An
            // unreadable receipt is the honest answer, and the command already
            // reports that as no run recorded.
            if (! is_string($verdict)) {
                return null;
            }

            // A step id of "123" arrives as an int, because PHP coerces
            // numeric-string array keys. Nothing forbids that id, so cast it back
            // rather than rejecting a legal config.
            $verdicts[(string) $stepId] = $verdict;
        }

        return $verdicts;
    }

    /**
     * @return list<string>|null
     */
    private static function readAsserted(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $asserted = [];

        foreach ($raw as $stepId) {
            // Well-formedness is settled before this runs, so nothing is dropped
            // here: a bad entry rejected the receipt rather than shrinking the
            // list, which would have been a quieter answer to the same question.
            if (is_string($stepId)) {
                $asserted[] = $stepId;
            } elseif (is_int($stepId)) {
                $asserted[] = (string) $stepId;
            }
        }

        return $asserted;
    }
}
