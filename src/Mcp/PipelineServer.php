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
    open_run rather than next_step — and do not try to work out whether your fix
    moved the tree. open_run hands back the run you were already in when nothing
    moved, and a fresh one when anything did: an edit, but also a commit, an
    amend, a checkout or a rebase, because the fingerprint covers the commit too.
    next_step never re-checks, so carrying on finishes a walk whose earlier passes
    no longer describe the code, and reports all_verified false.

    Neither call sees a fix that touches only a git-ignored file, `.env` for
    instance: nothing moves, so judge that one yourself.

    While a walk is open, do no unrelated repository work — no committing finished
    work, amending, rebasing or switching branches. A run holds a claim about one
    tree, and any git-visible change invalidates it.

    A "skill" step is handed to you to invoke, then acknowledged with report_step;
    it is recorded as acknowledged, never as verified.

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
