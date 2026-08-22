<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Results\Result;

interface Step
{
    public function id(): string;

    public function description(): string;

    public function kind(): StepKind;

    /** Optional setup, run before the step resolves. */
    public function before(): void;

    /** Optional teardown, run whatever the verdict was. */
    public function after(Result $result): void;
}
