<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Registrar;
use SanderMuller\BoostPipeline\BoostPipelineServiceProvider;
use SanderMuller\BoostPipeline\Run\HistoryRecord;
use SanderMuller\BoostPipeline\Run\JsonLiveProgressStore;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\JsonRunHistoryStore;
use SanderMuller\BoostPipeline\Run\LiveProgress;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Run\RunState;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;

/**
 * What the page shows before any JavaScript runs.
 *
 * The polling script rebuilds the same shape from JSON, but the first paint is
 * server-rendered — which is also what lets these assert every state without a
 * browser.
 */
function bootPage(): void
{
    $path = app()->basePath('.config/pipeline.php');

    if (! is_dir(dirname($path))) {
        @mkdir(dirname($path), recursive: true);
    }

    file_put_contents($path, <<<'PHP'
        <?php

        use SanderMuller\BoostPipeline\Config\Pipeline;
        use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
        use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
        use SanderMuller\BoostPipeline\Phases\Steps;
        use SanderMuller\BoostPipeline\Steps\Shell;

        return Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
            $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'analyse'));
        });
        PHP);

    config()->set('boost-pipeline.ui.enabled', true);
    config()->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

    app()->instance('env', 'local');
    app()->instance(Registrar::class, new Registrar);

    new BoostPipelineServiceProvider(app())->boot();
    Route::getRoutes()->refreshNameLookups();
}

/** @param array<string, string> $verdicts */
function writeReceipt(array $verdicts, string $state = 'complete', bool $allVerified = true): void
{
    new JsonReceiptStore(storage_path('logs/pipeline/receipts/default.json'))->write(new Receipt(
        runId: 'r-page',
        state: $state,
        allVerified: $allVerified,
        tree: null,
        stale: null,
        verdicts: $verdicts,
        recordedAt: '2026-01-01T00:00:00+00:00',
    ));
}

beforeEach(function (): void {
    bootPage();
});

afterEach(function (): void {
    @unlink(app()->basePath('.config/pipeline.php'));
    removePipelineStorage(storage_path('logs/pipeline'));
});

function removePipelineStorage(string $path): void
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
        is_dir($child) ? removePipelineStorage($child) : unlink($child);
    }

    rmdir($path);
}

it('says nothing was recorded rather than showing an error', function (): void {
    $this->get('/boost-pipelines')
        ->assertOk()
        ->assertSee('nothing recorded');
});

it('renders every step of a finished run with its verdict', function (): void {
    writeReceipt(['fmt' => 'passed', 'analyse' => 'passed']);

    $this->get('/boost-pipelines')
        ->assertOk()
        ->assertSee('complete')
        ->assertSee('fmt')
        ->assertSee('analyse')
        ->assertSee('passed');
});

it('shows a step that has not run yet as not run', function (): void {
    writeReceipt(['fmt' => 'passed'], state: 'blocked', allVerified: false);

    $this->get('/boost-pipelines')
        ->assertOk()
        ->assertSee('blocked')
        ->assertSee('not run');
});

it('shows the run in flight while it has written no receipt', function (): void {
    new JsonLiveProgressStore(storage_path('logs/pipeline/live/default.json'))->write(new LiveProgress(
        runId: 'r-flight',
        token: 't',
        state: RunState::Running,
        stepIds: ['fmt'],
        startedAt: gmdate('c'),
        timeoutSeconds: 540.0,
    ));

    $this->get('/boost-pipelines')
        ->assertOk()
        ->assertSee('running — fmt')
        ->assertSee('nothing recorded');
});

it('escapes a step id that carries markup', function (): void {
    // A step id is whatever the pipeline config passed, and it reaches the page.
    writeReceipt(['<script>alert(1)</script>' => 'failed'], state: 'blocked', allVerified: false);

    $this->get('/boost-pipelines')
        ->assertOk()
        ->assertDontSee('<script>alert(1)</script>', escape: false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false);
});

it('never writes untrusted output through innerHTML', function (): void {
    // Every value the polling script renders is command output or a path the
    // server produced, so the rule is textContent only. This is the regression
    // guard for it: a reviewer would not otherwise notice the day it changes.
    $this->get('/boost-pipelines')
        ->assertOk()
        ->assertDontSee('.innerHTML', escape: false)
        ->assertDontSee('innerHTML =', escape: false)
        ->assertSee('textContent', escape: false);
});

it('escapes a config error rather than rendering it as markup', function (): void {
    @unlink(app()->basePath('.config/pipeline.php'));
    file_put_contents(
        app()->basePath('.config/pipeline.php'),
        '<?php use SanderMuller\BoostPipeline\Config\Pipeline; return ["<script>x</script>" => Pipeline::configure()];',
    );

    $this->get('/boost-pipelines')
        ->assertOk()
        ->assertDontSee('<script>x</script>', escape: false)
        ->assertSee('&lt;script&gt;', escape: false);
});

it('serves a recorded log through the run own log map', function (): void {
    $logPath = storage_path('logs/pipeline/r-page-fmt.log');
    @mkdir(dirname($logPath), recursive: true);
    file_put_contents($logPath, "first line\nsecond line\n");

    new JsonRunHistoryStore(storage_path('logs/pipeline/history/default'))->write(new HistoryRecord(
        new Receipt('r-page', 'blocked', false, null, null, ['fmt' => 'failed'], '2026-01-01T00:00:00+00:00'),
        ['fmt' => $logPath],
    ));

    $response = $this->get('/boost-pipelines/log/default/r-page/fmt');

    $response->assertOk();

    expect($response->json('summary'))->toContain('second line');
});

it('refuses a recorded path that resolves outside the log root', function (): void {
    $outside = sys_get_temp_dir().'/bp-outside-'.bin2hex(random_bytes(4)).'.log';
    file_put_contents($outside, 'secrets');

    new JsonRunHistoryStore(storage_path('logs/pipeline/history/default'))->write(new HistoryRecord(
        new Receipt('r-out', 'complete', true, null, null, ['fmt' => 'passed'], '2026-01-01T00:00:00+00:00'),
        ['fmt' => $outside],
    ));

    // A custom runner may record any path, so the recorded one is checked rather
    // than trusted.
    $this->get('/boost-pipelines/log/default/r-out/fmt')->assertNotFound();

    unlink($outside);
});

it('refuses a symlink that points out of the log root', function (): void {
    $target = sys_get_temp_dir().'/bp-target-'.bin2hex(random_bytes(4)).'.log';
    file_put_contents($target, 'secrets');

    $link = storage_path('logs/pipeline/escape.log');
    @mkdir(dirname($link), recursive: true);
    symlink($target, $link);

    new JsonRunHistoryStore(storage_path('logs/pipeline/history/default'))->write(new HistoryRecord(
        new Receipt('r-link', 'complete', true, null, null, ['fmt' => 'passed'], '2026-01-01T00:00:00+00:00'),
        ['fmt' => $link],
    ));

    // realpath() resolves the link, which is what makes this fail the check
    // rather than pass it.
    $this->get('/boost-pipelines/log/default/r-link/fmt')->assertNotFound();

    unlink($link);
    unlink($target);
});

it('answers the same way for a step with no log, a missing file and an unknown run', function (): void {
    new JsonRunHistoryStore(storage_path('logs/pipeline/history/default'))->write(new HistoryRecord(
        new Receipt('r-none', 'complete', true, null, null, ['fmt' => 'passed'], '2026-01-01T00:00:00+00:00'),
        ['fmt' => null, 'gone' => storage_path('logs/pipeline/never-written.log')],
    ));

    // One answer, never a hint about which of the three it was.
    $this->get('/boost-pipelines/log/default/r-none/fmt')->assertNotFound();
    $this->get('/boost-pipelines/log/default/r-none/gone')->assertNotFound();
    $this->get('/boost-pipelines/log/default/r-missing/fmt')->assertNotFound();
});

it('treats an unusual step id as a lookup key, not a path', function (): void {
    $logPath = storage_path('logs/pipeline/r-keys-weird.log');
    @mkdir(dirname($logPath), recursive: true);
    file_put_contents($logPath, 'output');

    new JsonRunHistoryStore(storage_path('logs/pipeline/history/default'))->write(new HistoryRecord(
        new Receipt('r-keys', 'complete', true, null, null, ['..weird..' => 'passed'], '2026-01-01T00:00:00+00:00'),
        ['..weird..' => $logPath],
    ));

    $response = $this->get('/boost-pipelines/log/default/r-keys/'.rawurlencode('..weird..'));

    // The id addresses the recorded log, never the filesystem, so dots in it
    // reach no path.
    $response->assertOk();

    expect($response->json('summary'))->toContain('output');
});

it('cannot address a step id containing a separator at all', function (): void {
    new JsonRunHistoryStore(storage_path('logs/pipeline/history/default'))->write(new HistoryRecord(
        new Receipt('r-slash', 'complete', true, null, null, ['a/b' => 'passed'], '2026-01-01T00:00:00+00:00'),
        ['a/b' => storage_path('logs/pipeline/r-slash-a-b.log')],
    ));

    // A route segment cannot carry one, so such a log is simply unreachable from
    // the page. Unreachable rather than mis-resolved is the safe failure.
    $this->get('/boost-pipelines/log/default/r-slash/'.rawurlencode('a/b'))->assertNotFound();
});

it('refuses a log request from another machine', function (): void {
    $this->call('GET', '/boost-pipelines/log/default/r-page/fmt', server: ['REMOTE_ADDR' => '10.0.0.5'])
        ->assertForbidden();
});

it('answers 404 for a pipeline the config never declared', function (): void {
    // The stores refuse an undeclared name by throwing. Reaching the route with
    // one is a bad request, not a server fault.
    $this->get('/boost-pipelines/log/ghost/r-page/fmt')->assertNotFound();
});

it('serves an oversized log truncated rather than whole', function (): void {
    $logPath = storage_path('logs/pipeline/r-big-fmt.log');
    @mkdir(dirname($logPath), recursive: true);
    file_put_contents($logPath, implode("\n", array_map(
        static fn (int $line): string => 'line '.$line,
        range(1, 500),
    )));

    new JsonRunHistoryStore(storage_path('logs/pipeline/history/default'))->write(new HistoryRecord(
        new Receipt('r-big', 'complete', true, null, null, ['fmt' => 'passed'], '2026-01-01T00:00:00+00:00'),
        ['fmt' => $logPath],
    ));

    $response = $this->get('/boost-pipelines/log/default/r-big/fmt');

    $response->assertOk();

    // Head and tail, with the flag that says so — the page must not have to
    // hold half a megabyte to show why a step failed.
    expect($response->json('truncated'))->toBeTrue()
        ->and($response->json('shown_lines'))->toBe(OutputSummariser::MAX_LINES)
        ->and($response->json('output_lines'))->toBe(500);
});

it('builds the log url from the named route, not from the configured path', function (): void {
    // The provider normalises `ui.path` before registering. A page that rebuilt
    // the URL from the raw value would break on anything it normalised away.
    config()->set('boost-pipeline.ui.path', ['not', 'a', 'string']);
    app()->instance(Registrar::class, new Registrar);
    new BoostPipelineServiceProvider(app())->boot();
    Route::getRoutes()->refreshNameLookups();

    // @json escapes the slashes, so the template appears as boost-pipelines\/log.
    $this->get('/boost-pipelines')
        ->assertOk()
        ->assertSee('boost-pipelines\\/log\\/---P', escape: false);
});
