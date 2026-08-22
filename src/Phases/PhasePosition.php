<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases;

use SanderMuller\BoostPipeline\Contracts\Phase;

/**
 * Returned by Phases::append()/prepend() so a custom phase can be positioned
 * relative to an existing one. Already placed by the time you hold this; after()
 * moves it.
 */
final readonly class PhasePosition
{
    /** @param class-string<Phase> $phase */
    public function __construct(
        private Phases $phases,
        private string $phase,
    ) {}

    /** @param class-string<Phase> $anchor */
    public function after(string $anchor): void
    {
        $this->phases->moveAfter($this->phase, $anchor);
    }
}
