<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

enum RunState: string
{
    /** A run exists, cursor on the first step. */
    case Open = 'open';

    /** Cursor mid-walk. */
    case Running = 'running';

    /** Cursor on a skill step, waiting for report_step and nothing else. */
    case Awaiting = 'awaiting';

    /** Current step failed. The agent must change something; calling again is expected. */
    case Blocked = 'blocked';

    /** Current step errored: the tool could not run, so calling again changes nothing. */
    case Halted = 'halted';

    /** The walk finished. NOT a claim that everything passed — see Run::allVerified(). */
    case Complete = 'complete';

    public function isTerminal(): bool
    {
        return $this === self::Halted || $this === self::Complete;
    }
}
