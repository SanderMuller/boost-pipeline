<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Enums;

enum StepKind: string
{
    /** The server executes a command and reads its exit code. */
    case Shell = 'shell';

    /** The server hands the agent an instruction and waits for an acknowledgement. */
    case Skill = 'skill';
}
