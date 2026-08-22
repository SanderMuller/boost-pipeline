<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases\Defaults;

use SanderMuller\BoostPipeline\Contracts\Phase;

final class Formatting implements Phase
{
    public function id(): string
    {
        return 'formatting';
    }

    public function name(): string
    {
        return 'Formatting';
    }
}
