<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Mcp\StepPayload;
use SanderMuller\BoostPipeline\Mcp\Tools\Concerns\PipelineTool;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Run\RunState;

final class NextStep extends Tool
{
    use PipelineTool;

    protected string $name = 'next_step';

    protected string $description = 'Resolve the current step and get the next one. Shell steps are executed by the server — do not run the command yourself. If a step failed, fix the cause and call this again; you will get the same step until it passes.';

    public function __construct(private readonly RunManager $runs) {}

    /** @return array<string, mixed> */
    public function annotations(): array
    {
        // This tool carries the large payloads, so it raises its own ceiling
        // rather than making a consumer lift MAX_MCP_OUTPUT_TOKENS globally.
        return ['anthropic/maxResultSizeChars' => 60000];
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

        return match ($run->state()) {
            RunState::Complete => Response::structured(StepPayload::complete($run)),
            RunState::Awaiting => Response::structured(StepPayload::awaiting($run)),
            // Retried, not refused: a missing binary is exactly what the agent then
            // installs, and refusing forever left only a server restart.
            RunState::Halted => $this->resolve($run),
            default => $this->resolve($run),
        };
    }

    private function resolve(Run $run): Response|ResponseFactory
    {
        $results = $run->resolveCurrent();

        if ($results === []) {
            // Resolving revealed a skill step (now awaiting) or the walk's end.
            return $run->state() === RunState::Awaiting
                ? Response::structured(StepPayload::awaiting($run))
                : Response::structured(StepPayload::complete($run));
        }

        // An `error` means the tool did not run — a failed tool CALL, so it goes
        // on MCP's error channel. A `failed` verdict is a SUCCESSFUL call
        // reporting a real finding; marking that isError would make every failing
        // check look like a broken server and invite the client to retry it.
        //
        // A group reports on the error channel if any one of its steps could not
        // run, and names every one that could not: the whole group re-runs, so the
        // agent needs all of them, not the first.
        $errors = array_values(array_filter(
            $results,
            static fn (Result $result): bool => $result->verdict === Verdict::Error,
        ));

        if ($errors !== []) {
            return Response::error(sprintf(
                "%s\nRun state: halted. The cursor stays here — fix what stopped it and call next_step again.",
                implode("\n", array_map(
                    static fn (Result $result): string => sprintf(
                        'Step [%s] could not run: %s',
                        $result->stepId,
                        $result->reason ?? $result->summary,
                    ),
                    $errors,
                )),
            ));
        }

        return Response::structured(StepPayload::afterResolution($run, $results));
    }
}
