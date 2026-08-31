<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\HistoryRecord;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Run\RunState;
use SanderMuller\BoostPipeline\Steps\Shell;

/** Passes every step, reporting the log path a shipped runner would have written. */
final readonly class LoggingRunner implements StepRunner
{
    public function __construct(private ?string $logPath = '/logs/{run}-{step}.log') {}

    public function run(Step $step, string $runId): Result
    {
        return Result::passed($step->id(), 'ok', logPath: $this->logPath === null
            ? null
            : str_replace(['{run}', '{step}'], [$runId, $step->id()], $this->logPath));
    }
}

use SanderMuller\BoostPipeline\Run\JsonRunHistoryStore;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Runner\SafeFilename;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/bp-history-'.bin2hex(random_bytes(4));
    $this->store = new JsonRunHistoryStore($this->directory);
});

afterEach(function (): void {
    foreach (historyFiles($this->directory) as $path) {
        unlink($path);
    }

    if (is_dir($this->directory)) {
        rmdir($this->directory);
    }
});

/** @return list<string> Every history file, including one whose name begins with a dot. */
function historyFiles(string $directory): array
{
    $entries = is_dir($directory) ? scandir($directory) : false;

    if ($entries === false) {
        return [];
    }

    return array_values(array_filter(
        array_map(static fn (string $entry): string => $directory.'/'.$entry, $entries),
        static fn (string $path): bool => is_file($path) && str_ends_with($path, '.json'),
    ));
}

/** @param array<string, string|null> $logs */
function historyRecord(string $runId, array $logs = [], string $state = 'complete'): HistoryRecord
{
    return new HistoryRecord(
        new Receipt(
            runId: $runId,
            state: $state,
            allVerified: true,
            tree: 'tree-a',
            stale: null,
            verdicts: ['pint' => 'passed'],
            recordedAt: '2026-01-01T00:00:00+00:00',
        ),
        $logs,
    );
}

it('keeps one file per run and overwrites it on the next resolution', function (): void {
    $this->store->write(historyRecord('r-one', state: 'running'));
    $this->store->write(historyRecord('r-one', state: 'complete'));

    expect(glob($this->directory.'/*.json'))->toHaveCount(1)
        ->and($this->store->read('r-one')?->receipt->state)->toBe('complete');
});

it('carries a log path back out, which a bare receipt would drop', function (): void {
    // Receipt::fromArray() builds from a fixed key list, so a store returning a
    // Receipt would write `logs` and never read it back.
    $this->store->write(historyRecord('r-logs', ['pint' => '/logs/r-logs-pint.log', 'phpstan' => null]));

    expect($this->store->read('r-logs')?->logs)
        ->toBe(['pint' => '/logs/r-logs-pint.log', 'phpstan' => null]);
});

it('keeps only the newest runs and deletes the rest', function (): void {
    // Seeded through a store that prunes nothing, so the mtimes below are the
    // only thing deciding what survives. Stamping between pruning writes would
    // have prune read times the next write was about to change, and touch would
    // silently recreate a file it had already deleted.
    $seed = new JsonRunHistoryStore($this->directory, keep: 100);

    foreach (range(1, 6) as $index) {
        $seed->write(historyRecord("r-{$index}"));
        touch($this->directory.'/'.SafeFilename::for("r-{$index}").'.json', 1_700_000_000 + $index);
    }

    // Rewriting the newest run is an ordinary resolution, and it prunes.
    new JsonRunHistoryStore($this->directory, keep: 3)->write(historyRecord('r-6'));

    expect(array_map(
        static fn (string $path): string => basename($path, '.json'),
        historyFiles($this->directory),
    ))->toEqualCanonicalizing(array_map(
        SafeFilename::for(...),
        ['r-4', 'r-5', 'r-6'],
    ));
});

it('defaults its retention to the documented cap', function (): void {
    expect(JsonRunHistoryStore::KEEP)->toBe(20);
});

it('lists runs newest first', function (): void {
    $this->store->write(historyRecord('r-old'));
    touch($this->directory.'/'.SafeFilename::for('r-old').'.json', 1_800_000_000);
    $this->store->write(historyRecord('r-new'));
    touch($this->directory.'/'.SafeFilename::for('r-new').'.json', 1_800_000_500);

    expect(array_map(
        static fn (HistoryRecord $record): string => $record->receipt->runId,
        $this->store->all(),
    ))->toBe(['r-new', 'r-old']);
});

it('cannot be made to write outside its own directory by a run id', function (): void {
    // Run::start() takes a caller-supplied id, so this is reachable input.
    $this->store->write(historyRecord('../escape'));

    // It encodes to `..-escape-<hash>` and lands inside the directory. The file
    // starting with a dot is exactly why the store lists with scandir, not glob.
    expect(dirname($this->directory).'/escape.json')->not->toBeFile()
        ->and(historyFiles($this->directory))->toHaveCount(1)
        ->and($this->store->all())->toHaveCount(1);
});

it('reads a run id holding separators back through the same encoding', function (): void {
    $this->store->write(historyRecord('a/b'));

    expect($this->store->read('a/b')?->receipt->runId)->toBe('a/b');
});

it('reports an unwritten run as absent rather than failing', function (): void {
    expect($this->store->read('r-never'))->toBeNull()
        ->and($this->store->all())
        ->toBeEmpty();
});

it('treats an unparseable file as absent', function (): void {
    @mkdir($this->directory, recursive: true);
    file_put_contents($this->directory.'/r-broken.json', 'not json');

    expect($this->store->read('r-broken'))->toBeNull()
        ->and($this->store->all())
        ->toBeEmpty();
});

it('records a run through every resolution, keeping one entry for it', function (): void {
    $run = Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
            $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'analyse'));
        })->walk(),
        new LoggingRunner,
        'r-walk',
        history: $this->store,
    );

    $run->resolveCurrent();

    $afterFirst = $this->store->read('r-walk');

    expect($afterFirst?->receipt->verdicts)->toBe(['fmt' => 'passed']);

    $run->resolveCurrent();
    $afterSecond = $this->store->read('r-walk');

    // The second resolution replaces the first rather than adding a file.
    expect(historyFiles($this->directory))->toHaveCount(1)
        ->and($afterSecond?->receipt->verdicts)->toBe(['fmt' => 'passed', 'analyse' => 'passed']);
});

it('records where each step wrote its log, which the receipt discards', function (): void {
    $run = Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
        })->walk(),
        new LoggingRunner,
        'r-logs-run',
        history: $this->store,
    );

    $run->resolveCurrent();

    expect($this->store->read('r-logs-run')?->logs)->toBe(['fmt' => '/logs/r-logs-run-fmt.log']);
});

it('records a null where a custom runner wrote no log', function (): void {
    $run = Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
        })->walk(),
        new LoggingRunner(logPath: null),
        'r-nolog',
        history: $this->store,
    );

    $run->resolveCurrent();

    // A page must not guess the path the shipped runner would have used.
    expect($this->store->read('r-nolog')?->logs)->toBe(['fmt' => null]);
});

it('does not fail a run when the record cannot be written', function (): void {
    $run = Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
        })->walk(),
        new LoggingRunner,
        'r-unwritable',
        history: new JsonRunHistoryStore('/dev/null/nope'),
    );

    $run->resolveCurrent();

    expect($run->state())->toBe(RunState::Complete);
});

it('drops a malformed log map rather than the record that carries it', function (): void {
    // The verdicts are the answer. A bad log link costs a reader one click.
    $record = HistoryRecord::fromArray([
        'run' => 'r-bad-logs',
        'state' => 'complete',
        'verdicts' => ['fmt' => 'passed'],
        'logs' => ['fmt' => '/logs/ok.log', 'analyse' => 42, 7 => '/logs/keyed-by-int.log'],
    ]);

    expect($record?->receipt->runId)->toBe('r-bad-logs')
        ->and($record?->logs)->toBe(['fmt' => '/logs/ok.log']);
});

it('rejects a payload with no readable receipt', function (): void {
    expect(HistoryRecord::fromArray(['logs' => ['fmt' => '/logs/ok.log']]))->toBeNull();
});

it('does not let unreadable files evict the runs it kept', function (): void {
    $store = new JsonRunHistoryStore($this->directory, keep: 2);

    $seed = new JsonRunHistoryStore($this->directory, keep: 100);
    $seed->write(historyRecord('r-real-1'));
    touch($this->directory.'/'.SafeFilename::for('r-real-1').'.json', 1_700_000_001);
    $seed->write(historyRecord('r-real-2'));
    touch($this->directory.'/'.SafeFilename::for('r-real-2').'.json', 1_700_000_002);

    // Newer, and not runs. Counting them would push both real records past the cap.
    foreach (['a', 'b', 'c'] as $index) {
        file_put_contents($this->directory."/junk-{$index}.json", 'not a run');
        touch($this->directory."/junk-{$index}.json", 1_700_000_100);
    }

    $store->write(historyRecord('r-real-3'));

    $runs = array_map(static fn (HistoryRecord $record): string => $record->receipt->runId, $store->all());

    // The valid runs survive, and the directory stays bounded: an unreadable
    // file in this store's own directory is its own failed write.
    expect($runs)->toEqualCanonicalizing(['r-real-3', 'r-real-2'])
        ->and($this->directory.'/junk-a.json')->not->toBeFile()
        ->and(historyFiles($this->directory))->toHaveCount(2);
});

it('clears temporary files a crashed write left behind', function (): void {
    $store = new JsonRunHistoryStore($this->directory, keep: 1);
    $store->write(historyRecord('r-kept'));

    // Named the way a failed rename leaves it: not `.json`, so retention would
    // never see it and the directory would grow for the life of the checkout.
    $abandoned = $this->directory.'/r-gone.json.deadbeef.tmp';
    file_put_contents($abandoned, '{"partial":');
    touch($abandoned, Date::now()
        ->subHours(1)
        ->getTimestamp());

    $inFlight = $this->directory.'/r-busy.json.cafebabe.tmp';
    file_put_contents($inFlight, '{"partial":');

    $store->write(historyRecord('r-newer'));

    expect($abandoned)->not->toBeFile()
        // A write in another process is left alone.
        ->and($inFlight)
        ->toBeFile();
});
