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
use SanderMuller\BoostPipeline\Run\RunManager;

final class OpenRun extends Tool
{
    use PipelineTool;

    protected string $name = 'open_run';

    protected string $description = 'Start a verification run for this working tree and get the first step. Call this before any other pipeline tool.';

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
            'total_steps' => $schema->integer()->description(
                'How many steps the walk holds. Included deliberately: the agent can count them in .config/pipeline.php anyway, and a denominator makes the walk legible. What is never included is the identity of a step past the cursor.'
            ),
            ...$this->stepSchema($schema),
            'notices' => $schema->array()->description('Config problems found while resolving the walk, such as a dropped transition step.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        // Idempotent: opening an already-open run returns it where it stands.
        // Restarting would discard verdicts silently.
        $run = $this->runs->open();

        $payload = StepPayload::opened($run);

        if ($run->walk->notices !== []) {
            $payload['notices'] = $run->walk->notices;
        }

        return Response::structured($payload);
    }
}
