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

it('forbids running a step\'s command and forbids calling complete a pass', function (): void {
    PipelineServer::prompt(RunPipeline::class)
        ->assertSee('Do not run a step\'s command yourself')
        ->assertSee('unless `all_verified` is true')
        ->assertSee('not that everything passed');
});
