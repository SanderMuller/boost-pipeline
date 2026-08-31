<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use Closure;
use SanderMuller\BoostPipeline\Contracts\LiveProgressStore;

/**
 * One live record per pipeline, at `live/<name>.json`.
 *
 * Separate per pipeline for the same reason the receipt is: a project asks more
 * than one question of its code, and two walks in flight at once must not
 * overwrite each other's answer.
 */
final class LiveProgressStoreFactory
{
    /** @var array<string, LiveProgressStore> */
    private array $stores = [];

    /** @param Closure(string): LiveProgressStore $build */
    public function __construct(private readonly Closure $build) {}

    public function for(string $pipeline): LiveProgressStore
    {
        return $this->stores[$pipeline] ??= ($this->build)($pipeline);
    }
}
