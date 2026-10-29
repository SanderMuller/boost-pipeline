<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Mcp\Prompts\RunPipeline;

it('tells the agent the four things the tools do not say', function (): void {
    PipelineServer::prompt(RunPipeline::class)
        ->assertOk()
        ->assertSee('open_run')
        ->assertSee('next_step')
        ->assertSee('report_step')
        ->assertSee('status');
});

it("forbids running a step's command and forbids calling complete a pass", function (): void {
    PipelineServer::prompt(RunPipeline::class)
        ->assertSee("Do not run a step's command yourself")
        ->assertSee('unless `all_verified` is true')
        ->assertSee('not that everything passed');
});

it('warns against working in the repository before a walk can be spoiled by it', function (): void {
    // The package delivered the cure in context and the prevention only in the
    // README. An agent reads the prompt and the tool descriptions; it does not
    // read a dependency's README, and by the time a stale message arrives the
    // walk is already spent.
    PipelineServer::prompt(RunPipeline::class)
        ->assertOk()
        ->assertSee('unrelated repository work while a walk is open');
});

it('lets a commit keep the walk and warns about a partial one', function (): void {
    // The fingerprint reads content, so a commit moves no run. Saying otherwise
    // costs a second walk; saying nothing invites half a change committed.
    PipelineServer::prompt(RunPipeline::class)
        ->assertSee('Staging and committing change no content')
        ->assertSee('makes `pipeline:verify` refuse')
        ->assertDontSee('equally a commit');
});

it('sends a reader of a forgotten walk to the history, not to a new run', function (): void {
    PipelineServer::prompt(RunPipeline::class)
        ->assertSee('php artisan pipeline:history')
        ->assertSee('Do not open a run to read one');
});
