<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases\Defaults;

use SanderMuller\BoostPipeline\Contracts\Phase;

final class Tests implements Phase
{
    public function id(): string
    {
        return 'tests';
    }

    public function name(): string
    {
        return 'Tests';
    }
}
