<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Phases\Defaults;

use SanderMuller\BoostPipeline\Contracts\Phase;

final class Refactoring implements Phase
{
    public function id(): string
    {
        return 'refactoring';
    }

    public function name(): string
    {
        return 'Refactoring';
    }
}
