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
use SanderMuller\BoostPipeline\Runner\CommandPreflight;

final class OpenRun extends Tool
{
    use PipelineTool;

    protected string $name = 'open_run';

    protected string $description = 'Start a verification run for this working tree and get the first step. Call this before any other pipeline tool.';

    public function __construct(
        private readonly RunManager $runs,
        private readonly CommandPreflight $preflight,
    ) {}

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
            'warnings' => $schema->array()->description('Problems that will bite later in this walk, such as a step whose binary is missing. Not a reason to stop, but install it before you pay for the earlier steps.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        // Idempotent: opening an already-open run returns it where it stands.
        // Restarting would discard verdicts silently.
        $run = $this->runs->open();

        $payload = StepPayload::opened($run);

        if ($run->walk->notices !== []) {
            $payload['notices'] = $run->walk->notices;
        }

        // Separate from notices on purpose. A notice means the config asked for a
        // gate that will not run, so the run can never be fully verified. This is
        // only "you will halt at step three" — worth knowing before paying for
        // steps one and two, but not a reason to call the run unverifiable.
        $warnings = $this->preflight->warnings($run->walk);

        if ($warnings !== []) {
            $payload['warnings'] = $warnings;
        }

        return Response::structured($payload);
    }
}
