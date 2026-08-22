<?php

declare(strict_types=1);

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use SanderMuller\BoostPipeline\Mcp\Tools\NextStep;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
use SanderMuller\BoostPipeline\Mcp\Tools\ReportStep;
use SanderMuller\BoostPipeline\Mcp\Tools\Status;

/** @param class-string $tool */
function schemaFor(string $tool): array
{
    return (new ReflectionClass($tool))
        ->newInstanceWithoutConstructor()
        ->outputSchema(new JsonSchemaTypeFactory);
}

it('declares a non-empty output schema for every tool', function (string $tool): void {
    expect(schemaFor($tool))->not->toBeEmpty();
})->with([OpenRun::class, NextStep::class, ReportStep::class, Status::class]);

it('documents state and all_verified on every tool, so complete cannot read as green', function (string $tool): void {
    $schema = schemaFor($tool);

    expect($schema)->toHaveKeys(['state', 'all_verified', 'acknowledged']);
})->with([OpenRun::class, NextStep::class, ReportStep::class, Status::class]);

it('describes a step payload without ever promising more than the cursor', function (): void {
    expect(schemaFor(NextStep::class))->toHaveKeys(['step', 'result']);
});

it('exposes total_steps on open_run but no later step identity', function (): void {
    expect(schemaFor(OpenRun::class))->toHaveKey('total_steps');
});

it('keeps server_run separate from acknowledged in the status schema', function (): void {
    $schema = schemaFor(Status::class);

    expect($schema)->toHaveKey('server_run')
        ->and($schema)->toHaveKey('acknowledged')
        ->and($schema)->toHaveKey('steps');
});

it('marks the read-only tools read-only and raises the ceiling only where payloads are large', function (): void {
    $readOnly = fn (string $tool): array => (new ReflectionClass($tool))->newInstanceWithoutConstructor()->annotations();

    expect($readOnly(OpenRun::class))->toBe(['readOnlyHint' => true])
        ->and($readOnly(Status::class))->toBe(['readOnlyHint' => true])
        ->and($readOnly(NextStep::class))->toHaveKey('anthropic/maxResultSizeChars');
});

it('names the tools exactly as the spec contract and the driver prompt reference them', function (): void {
    // laravel/mcp defaults to kebab-case from the class name, which would leave
    // the prompt telling the agent to call `open_run` when the tool is
    // `open-run`. Pinned explicitly rather than relying on the default.
    $name = fn (string $tool): string => (new ReflectionClass($tool))->newInstanceWithoutConstructor()->name();

    expect($name(OpenRun::class))->toBe('open_run')
        ->and($name(NextStep::class))->toBe('next_step')
        ->and($name(ReportStep::class))->toBe('report_step')
        ->and($name(Status::class))->toBe('status');
});
