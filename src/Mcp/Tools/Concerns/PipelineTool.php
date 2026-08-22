<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use SanderMuller\BoostPipeline\Config\PipelineLoader;

trait PipelineTool
{
    /**
     * Decline to register when the project has not opted in.
     *
     * Defence in depth: the service provider already skips `Mcp::local()` when
     * `.config/pipeline.php` is absent, but that only covers registration through
     * this package. A host wiring the server up some other way (a `routes/ai.php`,
     * a manual `Mcp::local()`) would otherwise expose tools with no pipeline
     * behind them, and the failure would surface at call time instead of as an
     * honestly empty tool list.
     */
    public function shouldRegister(): bool
    {
        return app(PipelineLoader::class)->exists();
    }

    /**
     * The envelope every response shares.
     *
     * `all_verified` is optional here because it appears only on a terminal
     * payload — but when `state` is `complete` it is always present, which is
     * what stops a consumer reading the state alone as green.
     *
     * @return array<string, mixed>
     */
    protected function envelopeSchema(JsonSchema $schema): array
    {
        return [
            'run' => $schema->string()->description('Identifier for this run.'),
            'state' => $schema->string()->description(
                'One of: open, running, awaiting, blocked, halted, complete. "complete" means the walk finished, NOT that everything passed.'
            ),
            'position' => $schema->string()->description('Cursor position, as "n/total".'),
            'all_verified' => $schema->boolean()->description(
                'Present whenever state is "complete". True only when every step was a pass the server itself verified.'
            ),
            'acknowledged' => $schema->integer()->description(
                'How many steps were agent-acknowledged rather than server-verified.'
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function stepSchema(JsonSchema $schema): array
    {
        return [
            'step' => $schema->object([
                'id' => $schema->string(),
                'phase' => $schema->string(),
                'kind' => $schema->string()->description('shell (the server executes it) or skill (you invoke it).'),
                'command' => $schema->string()->description('Present for a shell step. Do not run it yourself.'),
                'invoke' => $schema->string()->description('Present for a skill step: the skill to invoke.'),
                'note' => $schema->string(),
            ])->description('The step at the cursor. Never a step beyond it.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function resultSchema(JsonSchema $schema): array
    {
        return [
            'result' => $schema->object([
                'verdict' => $schema->string()->description('passed, failed, error or acknowledged.'),
                'step_id' => $schema->string(),
                'summary' => $schema->string(),
                'exit_code' => $schema->integer(),
                'log' => $schema->string()->description('Full output on disk, when it was captured.'),
                'files_inspected' => $schema->integer()->description(
                    'Omitted when unknown. A 0 means the step inspected nothing and so proved nothing.'
                ),
                'server_run' => $schema->boolean()->description(
                    'Whether the server produced this verdict. True for failed and error too — it answers who ran it, not whether it passed.'
                ),
                'reason' => $schema->string(),
            ]),
        ];
    }
}
