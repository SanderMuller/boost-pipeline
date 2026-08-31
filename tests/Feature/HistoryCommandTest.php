<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Run\HistoryRecord;
use SanderMuller\BoostPipeline\Run\JsonLiveProgressStore;
use SanderMuller\BoostPipeline\Run\JsonRunHistoryStore;
use SanderMuller\BoostPipeline\Run\LiveProgress;
use SanderMuller\BoostPipeline\Run\PipelineOverview;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Run\RunState;
use SanderMuller\BoostPipeline\Runner\SafeFilename;
use SanderMuller\BoostPipeline\Steps\Shell;

/**
 * `pipeline:history` reports; `pipeline:verify` gates.
 *
 * The exit codes are the contract that keeps them apart. A stale run, a failed
 * run and an empty history are all answers here — only a question the command
 * cannot answer exits non-zero, so nobody can wire this into a hook expecting it
 * to block.
 */
function declareOne(): void
{
    app()->instance(Pipelines::class, Pipelines::single(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
            $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'analyse'));
        }),
    ));
}

function removeHistoryStorage(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);

    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path.'/'.$entry;
        is_dir($child) ? removeHistoryStorage($child) : unlink($child);
    }

    rmdir($path);
}

/**
 * `expectsOutputToContain` does not capture what `$this->components->*` writes,
 * so these read the buffered output instead of asserting through the pending
 * command.
 *
 * @param  array<string, string>  $arguments
 * @return array{code: int, output: string}
 */
function runHistory(array $arguments = []): array
{
    $code = Artisan::call('pipeline:history', $arguments);

    return ['code' => $code, 'output' => Artisan::output()];
}

/**
 * @param  array<string, string>  $verdicts
 * @param  array<string, string|null>  $logs
 */
function recordRun(string $runId, array $verdicts, string $state = 'complete', bool $allVerified = true, array $logs = []): void
{
    new JsonRunHistoryStore(storage_path('logs/pipeline/history/default'))->write(new HistoryRecord(
        new Receipt(
            runId: $runId,
            state: $state,
            allVerified: $allVerified,
            tree: 'tree-recorded',
            stale: null,
            verdicts: $verdicts,
            recordedAt: '2026-01-01T00:00:00+00:00',
        ),
        $logs,
    ));
}

beforeEach(function (): void {
    declareOne();
});

afterEach(function (): void {
    removeHistoryStorage(storage_path('logs/pipeline'));
});

it('reports an empty history and still exits 0', function (): void {
    // Absence is an answer. A reporting command that failed here would get wired
    // into a hook and block on a question it never asked.
    $result = runHistory();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('Nothing recorded');
});

it('lists recorded runs newest first', function (): void {
    recordRun('r-old', ['fmt' => 'passed']);
    touch(storage_path('logs/pipeline/history/default/'.SafeFilename::for('r-old').'.json'), 1_700_000_000);
    recordRun('r-new', ['fmt' => 'passed', 'analyse' => 'failed'], state: 'blocked', allVerified: false);

    $result = runHistory();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toMatch('/r-new[\s\S]*r-old/');
});

it('exits 0 for a failed run, a blocked run and a run whose tree moved', function (): void {
    // The contract that separates this from `pipeline:verify`, which returns
    // failure for the same receipts.
    recordRun('r-failed', ['fmt' => 'failed'], state: 'blocked', allVerified: false);

    expect(runHistory()['code'])->toBe(0)
        ->and(runHistory(['--run' => 'r-failed'])['code'])->toBe(0);
});

it('shows one run in detail, naming a step that never ran', function (): void {
    recordRun('r-detail', ['fmt' => 'passed'], state: 'blocked', allVerified: false);

    $result = runHistory(['--run' => 'r-detail']);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('fmt')
        ->and($result['output'])->toContain('analyse')
        ->and($result['output'])->toContain('not run');
});

it('names a verdict whose step the config no longer declares', function (): void {
    recordRun('r-drift', ['fmt' => 'passed', 'removed' => 'passed']);

    $result = runHistory(['--run' => 'r-drift']);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('removed')
        ->and($result['output'])->toContain('no longer declared');
});

it('fails on an unknown run id', function (): void {
    $result = runHistory(['--run' => 'r-never']);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('r-never');
});

it('shows the run in flight above the list', function (): void {
    new JsonLiveProgressStore(storage_path('logs/pipeline/live/default.json'))->write(new LiveProgress(
        runId: 'r-flight',
        token: 't',
        state: RunState::Awaiting,
        stepIds: ['review'],
        startedAt: gmdate('c'),
    ));

    $result = runHistory();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('awaiting')
        ->and($result['output'])->toContain('review');
});

it('rejects a limit that is not a positive integer', function (): void {
    foreach (['0', '-1', 'many', '', ' ', '1.5'] as $limit) {
        expect(runHistory(['--limit' => $limit])['code'])->toBe(1);
    }
});

it('accepts a positive limit and shows no more than that many runs', function (): void {
    foreach (['r-1', 'r-2', 'r-3'] as $runId) {
        recordRun($runId, ['fmt' => 'passed']);
    }

    $result = runHistory(['--limit' => '1']);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->not->toContain('r-2');
});

it('requires a name when the project declares several, and lists them', function (): void {
    app()->instance(Pipelines::class, Pipelines::fromArray([
        'pr' => Pipeline::configure(),
        'release' => Pipeline::configure(),
    ], '.config/pipeline.php'));

    $result = runHistory();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('[pr]')
        ->and($result['output'])->toContain('[release]');
});

it('names every declared pipeline when asked about one that is not declared', function (): void {
    app()->instance(Pipelines::class, Pipelines::fromArray([
        'pr' => Pipeline::configure(),
        'release' => Pipeline::configure(),
    ], '.config/pipeline.php'));

    $result = runHistory(['--pipeline' => 'ghost']);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('ghost')
        ->and($result['output'])->toContain('[pr]')
        ->and($result['output'])->toContain('[release]');
});

it('reads the named pipeline history, not another one', function (): void {
    app()->instance(Pipelines::class, Pipelines::fromArray([
        'pr' => Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
        }),
        'release' => Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'tag'));
        }),
    ], '.config/pipeline.php'));

    new JsonRunHistoryStore(storage_path('logs/pipeline/history/pr'))->write(new HistoryRecord(
        new Receipt('r-pr', 'complete', true, null, null, ['fmt' => 'passed'], '2026-01-01T00:00:00+00:00'),
    ));
    new JsonRunHistoryStore(storage_path('logs/pipeline/history/release'))->write(new HistoryRecord(
        new Receipt('r-release', 'complete', true, null, null, ['tag' => 'passed'], '2026-01-01T00:00:00+00:00'),
    ));

    $result = runHistory(['--pipeline' => 'release']);

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('r-release')
        ->and($result['output'])->not->toContain('r-pr');
});

it('resolves a past run with its own scope, not the current receipt scope', function (): void {
    new JsonRunHistoryStore(storage_path('logs/pipeline/history/default'))->write(new HistoryRecord(
        new Receipt(
            runId: 'r-scoped',
            state: 'complete',
            allVerified: true,
            tree: null,
            stale: null,
            verdicts: ['fmt' => 'passed'],
            recordedAt: '2026-01-01T00:00:00+00:00',
            scope: 'backend',
        ),
    ));

    $result = runHistory(['--run' => 'r-scoped']);

    // The walk is resolved with the record's own scope. Reusing the current
    // receipt's would label this run's steps against a selection it never made.
    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('backend');
});

it('reports the same verdicts and log paths as the page does for one run', function (): void {
    recordRun('r-shared', ['fmt' => 'passed', 'analyse' => 'failed'], state: 'blocked', allVerified: false, logs: ['analyse' => '/logs/r-shared-analyse.log']);

    $result = runHistory(['--run' => 'r-shared']);
    $fromOverview = resolve(PipelineOverview::class)->run('default', 'r-shared');

    // Two readers of one projection. The moment they disagree, a terminal and a
    // browser tell a developer different things about the same run.
    expect($result['output'])->toContain('/logs/r-shared-analyse.log')
        ->and(data_get($fromOverview, 'positions.1.steps.0.log'))->toBe('/logs/r-shared-analyse.log')
        ->and(data_get($fromOverview, 'positions.1.steps.0.verdict'))->toBe('failed');
});

it('reports with the page disabled and outside a local environment', function (): void {
    // The command reads the same records the page would, but nothing about it is
    // gated: history is worth having in a terminal without serving anything.
    config()->set('boost-pipeline.ui.enabled', false);
    app()->instance('env', 'production');

    recordRun('r-headless', ['fmt' => 'passed']);

    $result = runHistory();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('r-headless');
});
