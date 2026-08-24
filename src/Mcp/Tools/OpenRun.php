<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
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
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->pipelineSchema($schema),
            'only' => $schema->string()->description(
                'Run only the steps carrying this tag, plus every untagged step. Omit it to run the whole pipeline. A scoped run verifies less than a full one, and its receipt records the scope, so `pipeline:verify` will not report the tree verified on the strength of it.'
            ),
        ];
    }

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

    public function handle(Request $request): Response|ResponseFactory
    {
        // Idempotent: opening an already-open run returns it where it stands.
        // Restarting would discard verdicts silently.
        $selection = $request->get('only');

        try {
            $run = $this->runs->open(
                $this->pipelineArgument($request),
                is_string($selection) && trim($selection) !== '' ? $selection : null,
            );
        } catch (InvalidPipelineConfigException $invalidPipelineConfigException) {
            return Response::error($invalidPipelineConfigException->getMessage());
        }

        $payload = StepPayload::opened($run);

        if ($run->walk->notices !== []) {
            $payload['notices'] = $run->walk->notices;
        }

        // Not a notice: a notice means a declared gate will never run, so the run
        // cannot be fully verified. This only says where the walk will stop.
        $warnings = $this->preflight->warnings($run->walk);

        if ($warnings !== []) {
            $payload['warnings'] = $warnings;
        }

        return Response::structured($payload);
    }
}
