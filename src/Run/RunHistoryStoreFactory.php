<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use Closure;
use SanderMuller\BoostPipeline\Contracts\RunHistoryStore;

/**
 * One history directory per pipeline, at `history/<name>/`.
 *
 * The name is the directory and nothing else, the same rule the receipt follows:
 * a record read from `history/pr/` belongs to the `pr` pipeline by construction,
 * so storing the name inside the file as well would create two sources of truth
 * that can disagree.
 */
final class RunHistoryStoreFactory
{
    /** @var array<string, RunHistoryStore> */
    private array $stores = [];

    /** @param Closure(string): RunHistoryStore $build */
    public function __construct(private readonly Closure $build) {}

    public function for(string $pipeline): RunHistoryStore
    {
        return $this->stores[$pipeline] ??= ($this->build)($pipeline);
    }
}
