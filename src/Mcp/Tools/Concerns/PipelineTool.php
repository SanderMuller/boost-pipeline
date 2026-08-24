<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp\Tools\Concerns;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\ObjectType;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Config\Pipelines;

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
        return resolve(PipelineLoader::class)->exists();
    }

    /**
     * The `pipeline` argument, present only when the project declares a map.
     *
     * Required there, and never defaulted to the most recently opened run: an
     * agent that omitted it would advance the wrong pipeline's cursor, run the
     * wrong steps and write a verdict into the wrong receipt, silently. An error
     * message is a far cheaper failure.
     *
     * Absent for a project declaring one pipeline, so nothing changes for a
     * client that never had a choice to make.
     *
     * @return array<string, Type>
     */
    protected function pipelineSchema(JsonSchema $schema): array
    {
        $pipelines = resolve(Pipelines::class);

        if (! $pipelines->requiresName()) {
            return [];
        }

        return [
            'pipeline' => $schema->string()->required()->description(
                'Which pipeline to act on. This project declares: '.implode(', ', $pipelines->names())
                .'. Keep it the same across a walk — each pipeline has its own cursor and its own receipt.'
            ),
        ];
    }

    /**
     * The schema declares this required; the schema is not the guard.
     *
     * A declared-required argument is what a well-behaved client sends, not what
     * an ill-behaved one is stopped from omitting. `RunManager` refuses a null
     * name whenever the project declares several, and that refusal is the guard.
     */
    protected function pipelineArgument(Request $request): ?string
    {
        $name = $request->get('pipeline');

        return is_string($name) && trim($name) !== '' ? $name : null;
    }

    /**
     * The envelope every response shares.
     *
     * `all_verified` is optional here only because a run with no results yet has
     * nothing to answer. From the first receipt it is always present, in every
     * state — including the retryable `blocked` and `halted`, which is when a
     * consumer actually asks.
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
            'position' => $schema->string()->description('Cursor position in steps, as "n/total", or "n-m/total" for a parallel group. Counts steps, not remaining calls.'),
            'scope' => $schema->string()->description('Present only when the run was opened with a tag selection. The walk holds the steps carrying it plus every untagged step, so the run verifies less than the whole pipeline.'),
            'pipeline' => $schema->string()->description('Which pipeline this run walks. Present only when the project declares more than one. A name selects which walk exists; a scope narrows that walk.'),
            'all_verified' => $schema->boolean()->description(
                'Present once the run has any result, in any state. True only when the walk finished AND every step was a pass the server itself verified against the code now on disk.'
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
            'step' => $this->stepObjectSchema($schema)
                ->description('The step at the cursor, when the position holds one. Never a step beyond it.'),
            'steps' => $schema->array()
                ->items($this->stepObjectSchema($schema))
                ->description('Present instead of `step` when the position is a parallel group. Every one of them is at the cursor, and they resolve together on one next_step call.'),
            'parallel' => $schema->boolean()
                ->description('Present and true alongside `steps`. The server runs them at the same time; you still do nothing for a shell step.'),
        ];
    }

    protected function stepObjectSchema(JsonSchema $schema): ObjectType
    {
        return $schema->object([
            'id' => $schema->string(),
            'phase' => $schema->string(),
            'kind' => $schema->string()->description('shell (the server executes it) or skill (you invoke it).'),
            'command' => $schema->string()->description('Present for a shell step. Do not run it yourself.'),
            'invoke' => $schema->string()->description('Present for a skill step: the skill to invoke.'),
            'instruction' => $schema->string()->description("Present for a skill step: what this step is for. Act on this, not on the skill's own breadth."),
            'note' => $schema->string(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function resultSchema(JsonSchema $schema): array
    {
        return [
            'result' => $this->resultObjectSchema($schema)
                ->description('The verdict, when the position held one step.'),
            'results' => $schema->array()
                ->items($this->resultObjectSchema($schema))
                ->description('Present instead of `result` when a parallel group resolved. One verdict per step, so every failure in the group is visible at once.'),
        ];
    }

    protected function resultObjectSchema(JsonSchema $schema): ObjectType
    {
        return $schema->object([
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
        ]);
    }
}
