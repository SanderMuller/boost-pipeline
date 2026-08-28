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

    A project may declare several pipelines. Where it does, every tool takes a
    required `pipeline` name, and each pipeline keeps its own cursor and its own
    receipt — so you can leave one part-walked, work in another, and come back.
    Pass the same name for a whole walk. There is no default: naming nothing is an
    error rather than a guess, because guessing would advance the wrong cursor.

    A step reporting "failed" returns the same step again. Fix the cause, then call
    open_run rather than next_step whenever the fix changed a file: the steps that
    already passed were measured against the tree before your edit, and next_step
    does not re-check that — the walk would finish carrying passes that no longer
    describe the code, and report all_verified false. open_run notices the moved
    tree and starts a fresh run. next_step is right only when nothing on disk
    changed, such as installing a missing binary. A "skill" step is handed to you to invoke, then acknowledged with
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
