<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases\Defaults;

use SanderMuller\BoostPipeline\Contracts\Phase;

final class Agent implements Phase
{
    public function id(): string
    {
        return 'agent';
    }

    public function name(): string
    {
        return 'Agent';
    }
}
