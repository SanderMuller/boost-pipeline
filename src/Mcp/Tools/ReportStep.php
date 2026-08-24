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
            ...$this->pipelineSchema($schema),
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

        $summary = $request->get('summary');

        if (! is_string($summary) || trim($summary) === '') {
            return Response::error('report_step needs a non-empty [summary] describing what you did.');
        }

        try {
            $result = $run->acknowledgeCurrentStep($summary);
        } catch (AcknowledgementNotAllowed $acknowledgementNotAllowed) {
            return Response::error($acknowledgementNotAllowed->getMessage());
        }

        return Response::structured(StepPayload::afterResolution($run, [$result]));
    }
}
