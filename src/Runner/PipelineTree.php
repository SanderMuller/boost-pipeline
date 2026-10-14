<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;

/**
 * The fingerprint one pipeline measures with.
 *
 * Every reader of a pipeline's digests goes through here — the run that writes a
 * receipt, `pipeline:verify` that checks it, and the overview that renders it. A
 * receipt measured without the excluded paths and compared to a capture taken with
 * them would never match, and every gate would fail with nothing wrong.
 */
final class PipelineTree
{
    public static function for(?TreeFingerprint $tree, ?Pipeline $pipeline): ?TreeFingerprint
    {
        if (! $tree instanceof GitTreeFingerprint || ! $pipeline instanceof Pipeline || $pipeline->ignoredPaths() === []) {
            return $tree;
        }

        return $tree->excluding($pipeline->ignoredPaths());
    }
}
