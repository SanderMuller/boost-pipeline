<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases\Defaults;

use SanderMuller\BoostPipeline\Contracts\Phase;

final class StaticAnalysis implements Phase
{
    public function id(): string
    {
        return 'static-analysis';
    }

    public function name(): string
    {
        return 'Static analysis';
    }
}
