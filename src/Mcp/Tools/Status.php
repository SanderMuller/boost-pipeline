<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Mcp\StepPayload;
use SanderMuller\BoostPipeline\Mcp\Tools\Concerns\PipelineTool;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Run\RunManager;

final class Status extends Tool
{
    use PipelineTool;

    protected string $name = 'status';

    protected string $description = 'Show the current run: position, per-step verdicts, and which were verified by the server versus acknowledged by you.';

    public function __construct(private readonly RunManager $runs) {}

    /** @return array<string, mixed> */
    public function annotations(): array
    {
        return ['readOnlyHint' => true];
    }

    /**
     * Which run to act on.
     *
     * Empty for a project declaring one pipeline. Declared and required for a
     * project that names them — without it a conforming client has no way to
     * send the name this tool's handler requires, and a walk would stop dead
     * after open_run.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->pipelineSchema($schema);
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            ...$this->envelopeSchema($schema),
            'server_run' => $schema->object([
                'passed' => $schema->integer(),
                'failed' => $schema->integer(),
                'error' => $schema->integer(),
            ])->description('Verdicts the server produced by executing something.'),
            'excluded_by_scope' => $schema->integer()->description("How many declared steps this run's scope left out. Present only for a scoped run."),
            'steps' => $schema->array()->description('Per-step verdicts so far, in resolution order.'),
            ...$this->stepSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $pipeline = $this->pipelineArgument($request);

        try {
            $run = $this->runs->for($pipeline);
        } catch (InvalidPipelineConfigException $invalidPipelineConfigException) {
            return Response::error($invalidPipelineConfigException->getMessage());
        }

        if (! $run instanceof Run) {
            return Response::error(sprintf(
                'No run is open%s. Call open_run first.',
                $pipeline === null ? '' : " for pipeline [{$pipeline}]",
            ));
        }

        return Response::structured(StepPayload::status($run));
    }
}
