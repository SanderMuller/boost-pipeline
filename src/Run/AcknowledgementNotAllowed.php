<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use RuntimeException;

final class AcknowledgementNotAllowed extends RuntimeException
{
    public static function forState(RunState $state): self
    {
        return new self(match ($state) {
            RunState::Awaiting => 'The step under the cursor is not a skill step.',
            RunState::Complete => 'This run is already complete.',
            RunState::Halted => 'This run halted: a tool could not run, so there is nothing to acknowledge.',
            default => "report_step is only valid while a skill step is awaiting acknowledgement (state: {$state->value}). Shell steps are resolved by the server.",
        });
    }
}
