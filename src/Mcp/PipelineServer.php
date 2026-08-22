<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp;

use Laravel\Mcp\Server;
use SanderMuller\BoostPipeline\Mcp\Prompts\RunPipeline;
use SanderMuller\BoostPipeline\Mcp\Tools\NextStep;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
use SanderMuller\BoostPipeline\Mcp\Tools\ReportStep;
use SanderMuller\BoostPipeline\Mcp\Tools\Status;

/**
 * The MCP server.
 *
 * The drip-feed contract lives here in $instructions, once, rather than being
 * repeated across four tool descriptions where the copies would drift.
 */
final class PipelineServer extends Server
{
    protected string $name = 'Pipeline';

    protected string $version = '0.1.0';

    protected string $instructions = <<<'TXT'
    A verification pipeline walked one step at a time.

    Call open_run to start, then next_step until the run reports state "complete"
    or "halted". The server executes every shell step itself and owns the verdict
    — do not run a step's command yourself.

    A step reporting "failed" returns the same step again: fix the cause and call
    next_step. A "skill" step is handed to you to invoke, then acknowledged with
    report_step; it is recorded as acknowledged, never as verified.

    "complete" means the walk finished, not that everything passed. Only
    all_verified: true means every step was verified by the server.
    TXT;

    /** @var array<int|string, array<int, class-string<Server\Tool>|Server\Tool>|class-string<Server\Tool>|Server\Tool> */
    protected array $tools = [
        OpenRun::class,
        NextStep::class,
        ReportStep::class,
        Status::class,
    ];

    /** @var array<int, class-string<Server\Prompt>|Server\Prompt> */
    protected array $prompts = [
        RunPipeline::class,
    ];
}
