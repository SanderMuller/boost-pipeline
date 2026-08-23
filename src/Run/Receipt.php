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

        $verdicts = [];
        $raw = $data['verdicts'] ?? null;

        if (is_array($raw)) {
            foreach ($raw as $stepId => $verdict) {
                if (is_string($stepId) && is_string($verdict)) {
                    $verdicts[$stepId] = $verdict;
                }
            }
        }

        $tree = $data['tree'] ?? null;
        $stale = $data['stale'] ?? null;
        $recordedAt = $data['recorded_at'] ?? '';

        return new self(
            runId: $runId,
            state: $state,
            allVerified: (bool) ($data['all_verified'] ?? false),
            tree: is_string($tree) ? $tree : null,
            stale: is_string($stale) ? $stale : null,
            verdicts: $verdicts,
            recordedAt: is_string($recordedAt) ? $recordedAt : '',
        );
    }
}
