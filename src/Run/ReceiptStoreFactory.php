<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use Closure;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;

/**
 * One receipt per pipeline, at `receipts/<name>.json`.
 *
 * This is what makes two answers true at once. With a single file a second run
 * replaced the first, so a project could hold "the PR pipeline verified this
 * tree" or "the release pipeline did", never both — which is the whole reason
 * tags could not stand in for named pipelines.
 *
 * The name is the filename and nothing else. A receipt read from
 * `receipts/pr.json` is the `pr` receipt by construction, so storing the name
 * inside the file as well would create two sources of truth that can disagree.
 */
final class ReceiptStoreFactory
{
    /** @var array<string, ReceiptStore> */
    private array $stores = [];

    /** @param Closure(string): ReceiptStore $build */
    public function __construct(private readonly Closure $build) {}

    public function for(string $pipeline): ReceiptStore
    {
        return $this->stores[$pipeline] ??= ($this->build)($pipeline);
    }
}
