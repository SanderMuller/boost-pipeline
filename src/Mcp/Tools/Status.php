<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
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
            'steps' => $schema->array()->description('Per-step verdicts so far, in resolution order.'),
            ...$this->stepSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $run = $this->runs->current();

        if (! $run instanceof Run) {
            return Response::error('No run is open. Call open_run first.');
        }

        return Response::structured(StepPayload::status($run));
    }
}
