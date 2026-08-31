<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineFingerprint;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\LiveProgressStore;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\RunHistoryStore;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\StepCollection;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Run\HistoryRecord;
use SanderMuller\BoostPipeline\Run\JsonLiveProgressStore;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\JsonRunHistoryStore;
use SanderMuller\BoostPipeline\Run\LiveProgress;
use SanderMuller\BoostPipeline\Run\LiveProgressStoreFactory;
use SanderMuller\BoostPipeline\Run\PipelineOverview;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Run\ReceiptStoreFactory;
use SanderMuller\BoostPipeline\Run\RunHistoryStoreFactory as History;
use SanderMuller\BoostPipeline\Run\RunState;
use SanderMuller\BoostPipeline\Runner\SafeFilename;
use SanderMuller\BoostPipeline\Steps\Shell;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bp-overview-'.bin2hex(random_bytes(4));
    @mkdir($this->root, recursive: true);
});

afterEach(function (): void {
    removeTree($this->root);
});

function removeTree(string $path): void
{
    $entries = scandir($path);

    foreach ($entries === false ? [] : $entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = $path.'/'.$entry;
        is_dir($child) ? removeTree($child) : unlink($child);
    }

    rmdir($path);
}

/** @param Closure(Steps): void $declare */
function overviewFor(string $root, Closure $declare, ?string $fingerprint = null): PipelineOverview
{
    return new PipelineOverview(
        Pipelines::single(Pipeline::configure()->withSteps($declare)),
        new ReceiptStoreFactory(static fn (string $name): ReceiptStore => new JsonReceiptStore($root."/receipts/{$name}.json")),
        new History(static fn (string $name): RunHistoryStore => new JsonRunHistoryStore($root."/history/{$name}")),
        new LiveProgressStoreFactory(static fn (string $name): LiveProgressStore => new JsonLiveProgressStore($root."/live/{$name}.json")),
        $fingerprint === null ? null : new readonly class($fingerprint) implements TreeFingerprint
        {
            public function __construct(private string $digest) {}

            public function capture(): string
            {
                return $this->digest;
            }
        },
    );
}

/** @param array<string, string> $verdicts */
function overviewReceipt(array $verdicts, ?string $scope = null, ?string $tree = null, ?string $config = null): Receipt
{
    return new Receipt(
        runId: 'r-one',
        state: 'complete',
        allVerified: true,
        tree: $tree,
        stale: null,
        verdicts: $verdicts,
        recordedAt: '2026-01-01T00:00:00+00:00',
        scope: $scope,
        config: $config,
    );
}

function twoSteps(Steps $steps): void
{
    $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
    $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'analyse'));
}

it('reports no run rather than an error when nothing was recorded', function (): void {
    $overview = overviewFor($this->root, twoSteps(...));

    expect(data_get($overview->forPipeline('default'), 'current'))->toBeNull()
        ->and(data_get($overview->forPipeline('default'), 'history'))->toBe([])
        ->and(data_get($overview->forPipeline('default'), 'live'))->toBeNull();
});

it('joins verdicts onto the walk, marking a step that has not run', function (): void {
    $overview = overviewFor($this->root, twoSteps(...));
    new JsonReceiptStore($this->root.'/receipts/default.json')->write(overviewReceipt(['fmt' => 'passed']));

    $pipeline = $overview->forPipeline('default');

    expect(data_get($pipeline, 'current.positions'))->toHaveCount(2)
        ->and(data_get($pipeline, 'current.positions.0.steps.0.verdict'))->toBe('passed')
        ->and(data_get($pipeline, 'current.positions.1.steps.0.id'))->toBe('analyse')
        ->and(data_get($pipeline, 'current.positions.1.steps.0.verdict'))->toBeNull();
});

it('renders a parallel group as one position, the way the run resolved it', function (): void {
    $overview = overviewFor($this->root, function (Steps $steps): void {
        $steps->in(Formatting::class)->parallel(function (StepCollection $steps): void {
            $steps->append(Shell::run('true', id: 'pint'));
            $steps->append(Shell::run('true', id: 'lint'));
        });
    });
    new JsonReceiptStore($this->root.'/receipts/default.json')
        ->write(overviewReceipt(['pint' => 'passed', 'lint' => 'failed']));

    $pipeline = $overview->forPipeline('default');

    expect(data_get($pipeline, 'current.positions'))->toHaveCount(1)
        ->and(data_get($pipeline, 'current.positions.0.parallel'))->toBeTrue()
        ->and(data_get($pipeline, 'current.positions.0.steps'))->toHaveCount(2);
});

it('resolves the walk with the receipt own scope, not the whole pipeline', function (): void {
    $overview = overviewFor($this->root, function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'pint')->tagged('backend'));
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'lint')->tagged('frontend'));
    });
    new JsonReceiptStore($this->root.'/receipts/default.json')
        ->write(overviewReceipt(['pint' => 'passed'], scope: 'backend'));

    $pipeline = $overview->forPipeline('default');

    // An unscoped walk would show `lint` as a step that never ran, which would
    // read as a gap rather than as a selection the run made deliberately.
    expect(data_get($pipeline, 'current.scope'))->toBe('backend')
        ->and(data_get($pipeline, 'current.positions'))->toHaveCount(1)
        ->and(data_get($pipeline, 'current.positions.0.steps.0.id'))->toBe('pint');
});

it('shows a verdict whose step the config no longer declares', function (): void {
    $overview = overviewFor($this->root, twoSteps(...));
    new JsonReceiptStore($this->root.'/receipts/default.json')
        ->write(overviewReceipt(['fmt' => 'passed', 'removed' => 'failed']));

    expect(data_get($overview->forPipeline('default'), 'current.undeclared'))
        ->toBe([['id' => 'removed', 'verdict' => 'failed', 'log' => null]]);
});

it('says whether the receipt still describes the code on disk', function (): void {
    new JsonReceiptStore($this->root.'/receipts/default.json')->write(overviewReceipt(['fmt' => 'passed'], tree: 'tree-a'));

    expect(data_get(overviewFor($this->root, twoSteps(...), 'tree-a')->forPipeline('default'), 'current.tree_matches'))->toBeTrue()
        ->and(data_get(overviewFor($this->root, twoSteps(...), 'tree-b')->forPipeline('default'), 'current.tree_matches'))->toBeFalse()
        ->and(data_get(overviewFor($this->root, twoSteps(...))->forPipeline('default'), 'current.tree_matches'))->toBeNull();
});

it('reports a run in flight that has written no receipt yet', function (): void {
    $overview = overviewFor($this->root, twoSteps(...));
    new JsonLiveProgressStore($this->root.'/live/default.json')->write(new LiveProgress(
        runId: 'r-flight',
        token: 't',
        state: RunState::Running,
        stepIds: ['fmt'],
        startedAt: gmdate('c'),
        timeoutSeconds: 540.0,
    ));

    $pipeline = $overview->forPipeline('default');

    // The first position starts before any receipt exists, which is exactly the
    // moment worth watching.
    expect(data_get($pipeline, 'current'))->toBeNull()
        ->and(data_get($pipeline, 'live.run'))->toBe('r-flight')
        ->and(data_get($pipeline, 'live.state'))->toBe('running')
        ->and(data_get($pipeline, 'live.interrupted'))->toBeFalse();
});

it('marks a running record past its ceiling as interrupted, and never an awaiting one', function (): void {
    $store = new JsonLiveProgressStore($this->root.'/live/default.json');
    $overview = overviewFor($this->root, twoSteps(...));

    $store->write(new LiveProgress('r-dead', 't', RunState::Running, ['fmt'], gmdate('c', 1_700_000_000), timeoutSeconds: 60.0));
    $running = data_get($overview->forPipeline('default'), 'live.interrupted');

    $store->write(new LiveProgress('r-wait', 't', RunState::Awaiting, ['review'], gmdate('c', 1_700_000_000), timeoutSeconds: 60.0));
    $awaiting = data_get($overview->forPipeline('default'), 'live.interrupted');

    expect($running)->toBeTrue()
        ->and($awaiting)->toBeFalse();
});

it('lists history newest first and carries a recorded log path into one run', function (): void {
    $history = new JsonRunHistoryStore($this->root.'/history/default');
    $history->write(new HistoryRecord(overviewReceipt(['fmt' => 'passed']), ['fmt' => '/logs/old.log']));
    rename(
        $this->root.'/history/default/'.SafeFilename::for('r-one').'.json',
        $this->root.'/history/default/'.SafeFilename::for('r-old').'.json',
    );
    touch($this->root.'/history/default/'.SafeFilename::for('r-old').'.json', 1_700_000_000);
    $history->write(new HistoryRecord(overviewReceipt(['fmt' => 'passed', 'analyse' => 'failed']), ['fmt' => '/logs/new.log']));

    $overview = overviewFor($this->root, twoSteps(...));
    $summaries = data_get($overview->forPipeline('default'), 'history');

    expect(data_get($summaries, '0.run'))->toBe('r-one')
        ->and(data_get($summaries, '0.verdicts'))->toBe(['passed' => 1, 'failed' => 1])
        ->and(data_get($overview->run('default', 'r-one'), 'positions.0.steps.0.log'))->toBe('/logs/new.log');
});

it('reports an unknown past run as absent', function (): void {
    expect(overviewFor($this->root, twoSteps(...))->run('default', 'r-never'))->toBeNull();
});

it('does not list history for a pipeline the config no longer declares', function (): void {
    new JsonRunHistoryStore($this->root.'/history/ghost')
        ->write(new HistoryRecord(overviewReceipt(['fmt' => 'passed'])));

    $overview = overviewFor($this->root, twoSteps(...));

    // Orphan directories are left on disk rather than deleted, but nothing reads
    // them: the declared names are the only ones asked about.
    expect(array_column($overview->all(), 'pipeline'))->toBe(['default'])
        ->and(data_get($overview->forPipeline('default'), 'history'))->toBe([]);
});

it('drops a run that retention deleted while a reader was watching', function (): void {
    $history = new JsonRunHistoryStore($this->root.'/history/default');
    $history->write(new HistoryRecord(overviewReceipt(['fmt' => 'passed'])));

    $overview = overviewFor($this->root, twoSteps(...));
    $before = data_get($overview->forPipeline('default'), 'history');

    unlink($this->root.'/history/default/'.SafeFilename::for('r-one').'.json');
    $after = data_get($overview->forPipeline('default'), 'history');

    // The next poll simply stops showing it. Nothing pins a run a reader opened.
    expect($before)->toHaveCount(1)
        ->and($after)->toBe([]);
});

it('links the current run logs, not only a past run', function (): void {
    // The receipt does not carry log paths; history does. Without the join the
    // run a reader most wants a log for is the one view that has none.
    new JsonReceiptStore($this->root.'/receipts/default.json')->write(overviewReceipt(['fmt' => 'failed']));
    new JsonRunHistoryStore($this->root.'/history/default')
        ->write(new HistoryRecord(overviewReceipt(['fmt' => 'failed']), ['fmt' => '/logs/r-one-fmt.log']));

    expect(data_get(overviewFor($this->root, twoSteps(...))->forPipeline('default'), 'current.positions.0.steps.0.log'))
        ->toBe('/logs/r-one-fmt.log');
});

it('says whether the run walked the declaration this config still produces', function (): void {
    // The one thing `tree_matches` cannot say. A server holding an older config
    // runs a different definition of the same step id, and the tree still matches
    // because the run ran against the tree that already held the new config.
    $declared = PipelineFingerprint::for(Pipeline::configure()->withSteps(twoSteps(...)));

    new JsonReceiptStore($this->root.'/receipts/default.json')
        ->write(overviewReceipt(['fmt' => 'passed'], config: $declared));

    expect(data_get(overviewFor($this->root, twoSteps(...))->forPipeline('default'), 'current.config_matches'))->toBeTrue();
});

it('says so when the run walked a declaration this config no longer produces', function (): void {
    // Split from the matching case rather than asserted beside it: two `expect()`
    // calls on a textually identical expression let the analyser carry the first
    // narrowing into the second, and it then reports the true assertion as
    // impossible. Separate tests also name the two outcomes.
    new JsonReceiptStore($this->root.'/receipts/default.json')
        ->write(overviewReceipt(['fmt' => 'passed'], config: 'a-different-digest'));

    expect(data_get(overviewFor($this->root, twoSteps(...))->forPipeline('default'), 'current.config_matches'))->toBeFalse();
});

it('reads a run recorded before the digest existed as unknown, never as mismatched', function (): void {
    // False would send a reader looking for a config change that never happened.
    new JsonReceiptStore($this->root.'/receipts/default.json')
        ->write(overviewReceipt(['fmt' => 'passed']));

    expect(data_get(overviewFor($this->root, twoSteps(...))->forPipeline('default'), 'current.config_matches'))->toBeNull();
});

it('flags a run still in flight that is walking a stale declaration', function (): void {
    // Worth knowing before the run ends: the steps it has not reached yet are the
    // ones a reader could still stop.
    new JsonLiveProgressStore($this->root.'/live/default.json')->write(new LiveProgress(
        runId: 'r-flight',
        token: 't',
        state: RunState::Running,
        stepIds: ['fmt'],
        startedAt: gmdate('c'),
        configDigest: 'a-different-digest',
    ));

    expect(data_get(overviewFor($this->root, twoSteps(...))->forPipeline('default'), 'live.config_matches'))->toBeFalse();
});
