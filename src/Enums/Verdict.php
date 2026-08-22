<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Enums;

enum Verdict: string
{
    /** A shell step exited 0. */
    case Passed = 'passed';

    /** A shell step ran and found problems. */
    case Failed = 'failed';

    /** A shell step did not run: binary missing, timeout, thrown exception. */
    case Error = 'error';

    /** A skill step the agent reports it invoked. Never verified by the server. */
    case Acknowledged = 'acknowledged';

    public function advancesCursor(): bool
    {
        return $this === self::Passed || $this === self::Acknowledged;
    }

    /**
     * Whether this verdict is a pass the server itself established.
     *
     * Deliberately false for Acknowledged: reporting an agent's self-report as a
     * pass would launder a claim into a receipt. Distinct from "who produced the
     * verdict" — see Result::serverRun().
     */
    public function isVerified(): bool
    {
        return $this === self::Passed;
    }

    public function isTerminalForRun(): bool
    {
        return $this === self::Error;
    }
}
