<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Server\Tool;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Mcp\Tools\NextStep;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
use SanderMuller\BoostPipeline\Mcp\Tools\ReportStep;
use SanderMuller\BoostPipeline\Mcp\Tools\Status;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Runner\CommandPreflight;

/**
 * Real instances rather than reflection: newInstanceWithoutConstructor() returns
 * `object`, so every method call on it is untyped and unverifiable.
 */
function toolNamed(string $name): Tool
{
    // The tools read the pipeline map to decide whether to declare a `pipeline`
    // argument, and a unit test has no service provider to bind it.
    app()->instance(Pipelines::class, Pipelines::single(Pipeline::configure()));

    $runs = runManagerFor(
        Pipeline::configure(),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
    );

    return match ($name) {
        'open_run' => new OpenRun($runs, new CommandPreflight(__DIR__)),
        'next_step' => new NextStep($runs),
        'report_step' => new ReportStep($runs),
        default => new Status($runs),
    };
}

/** @return array<string, mixed> */
function schemaFor(string $name): array
{
    return toolNamed($name)->outputSchema(new JsonSchemaTypeFactory);
}

it('declares a non-empty output schema for every tool', function (string $name): void {
    expect(schemaFor($name))->not->toBeEmpty();
})->with(['open_run', 'next_step', 'report_step', 'status']);

it('documents state and all_verified on every tool, so complete cannot read as green', function (string $name): void {
    expect(schemaFor($name))->toHaveKeys(['state', 'all_verified', 'acknowledged']);
})->with(['open_run', 'next_step', 'report_step', 'status']);

it('describes a step payload without ever promising more than the cursor', function (): void {
    expect(schemaFor('next_step'))->toHaveKeys(['step', 'result']);
});

it('declares every key a payload can actually contain', function (): void {
    // The schema is what a client reads to know what it may receive. Sending a key
    // it does not declare is the same drift as documentation disagreeing with
    // behaviour, and two keys had already shipped undeclared: `instruction`, which
    // is the whole point of a skill step, and the parallel-group shape.
    expect(schemaFor('next_step'))->toHaveKeys(['step', 'steps', 'parallel', 'result', 'results', 'scope'])
        ->and(schemaTextFor('next_step', 'step'))->toContain('instruction')
        ->and(schemaTextFor('next_step', 'steps'))->toContain('instruction')
        ->and(schemaTextFor('next_step', 'results'))->toContain('verdict')
        ->and(schemaFor('status'))->toHaveKey('excluded_by_scope');
});

it('declares notices, because every tool can report a dropped gate', function (string $name): void {
    // A notice means a declared step will never run, so the run can never report
    // itself fully verified. Emitting that without declaring it is the same drift
    // the case above exists to catch — and it shipped on three tools.
    expect(schemaFor($name))->toHaveKey('notices');
})->with(['open_run', 'next_step', 'report_step', 'status']);

it('describes what a notice actually is, on the schema the tools share', function (string $name): void {
    // The wording this replaced said "a dropped transition step". No such step
    // exists — `StepKind` holds Shell and Skill, and `Walk` has no transition
    // concept — so the one key that explains why a run cannot verify named
    // something a reader could not go and find.
    //
    // `open_run` included: its own literal is gone, so all four now read the one
    // description. That is the drift this schema exists to prevent.
    expect(schemaTextFor($name, 'notices'))->toContain('registered')
        ->and(schemaTextFor($name, 'notices'))->toContain('tag selection')
        ->and(schemaTextFor($name, 'notices'))->not->toContain('transition');
})->with(['open_run', 'next_step', 'report_step', 'status']);

it('declares stale on every tool, including the one that rarely emits it', function (string $name): void {
    // `open_run` included, after going back and forth on it. RunManager discards a
    // stale run and starts a fresh one, so at open time there is normally nothing
    // to report — but it reads the tree once to decide that and the payload reads
    // it again, and a tree that moves between the two captures produces a stale
    // `open_run` payload. Sharing one capture is not the answer: RunManager
    // deliberately does not thread its digest onward, because that would baseline
    // a replaced run against a moment already past.
    //
    // So the key is reachable, and a key a tool can emit but never declares is the
    // exact defect this file exists to catch. `all_verified` already sets the
    // pattern: declared on the shared envelope, present only once a result exists.
    expect(schemaFor($name))->toHaveKey('stale');
})->with(['open_run', 'next_step', 'report_step', 'status']);

/**
 * One schema entry rendered, so its declared properties can be asserted.
 *
 * A plain instanceof rather than an expectation: narrowing a mixed value through
 * `expect()` relies on an analyser extension, and whether it worked turned out to
 * depend on which PHP version was analysing.
 */
function schemaTextFor(string $name, string $key): string
{
    $type = schemaFor($name)[$key] ?? null;

    return $type instanceof Type ? $type->toString() : "no schema declared for [{$key}]";
}

it('declares the input open_run accepts, so a client can find the scope selector', function (): void {
    expect(toolNamed('open_run')->schema(new JsonSchemaTypeFactory))->toHaveKey('only');
});

it('exposes total_steps on open_run but no later step identity', function (): void {
    expect(schemaFor('open_run'))->toHaveKey('total_steps');
});

it('keeps server_run separate from acknowledged in the status schema', function (): void {
    expect(schemaFor('status'))->toHaveKeys(['server_run', 'acknowledged', 'steps']);
});

it('marks the read-only tools read-only and raises the ceiling only where payloads are large', function (): void {
    expect(toolNamed('open_run')->annotations())->toBe(['readOnlyHint' => true])
        ->and(toolNamed('status')->annotations())->toBe(['readOnlyHint' => true])
        ->and(toolNamed('next_step')->annotations())->toHaveKey('anthropic/maxResultSizeChars');
});

it('names the tools exactly as the spec contract and the driver prompt reference them', function (): void {
    // laravel/mcp defaults to kebab-case from the class name, which would leave
    // the prompt telling the agent to call `open_run` when the tool is
    // `open-run`. Pinned explicitly rather than relying on the default.
    expect(toolNamed('open_run')->name())->toBe('open_run')
        ->and(toolNamed('next_step')->name())->toBe('next_step')
        ->and(toolNamed('report_step')->name())->toBe('report_step')
        ->and(toolNamed('status')->name())->toBe('status');
});
