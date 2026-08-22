<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use SanderMuller\BoostPipeline\Mcp\StepPayload;
use SanderMuller\BoostPipeline\Mcp\Tools\Concerns\PipelineTool;
use SanderMuller\BoostPipeline\Run\AcknowledgementNotAllowed;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Run\RunManager;

final class ReportStep extends Tool
{
    use PipelineTool;

    protected string $name = 'report_step';

    protected string $description = 'Acknowledge a skill step after you have invoked it. Only valid while a skill step is awaiting. Your report is recorded as acknowledged, never as verified.';

    public function __construct(private readonly RunManager $runs) {}

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()
                ->description('What you did for this step.')
                ->required(),
        ];
    }

    /** @return array<string, mixed> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            ...$this->envelopeSchema($schema),
            ...$this->resultSchema($schema),
            ...$this->stepSchema($schema),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $run = $this->runs->current();

        if (! $run instanceof Run) {
            return Response::error('No run is open. Call open_run first.');
        }

        try {
            $result = $run->acknowledgeCurrentStep((string) $request->get('summary'));
        } catch (AcknowledgementNotAllowed $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured(StepPayload::afterResolution($run, $result));
    }
}
