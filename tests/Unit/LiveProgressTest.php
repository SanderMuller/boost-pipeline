<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\BatchStepRunner;
use SanderMuller\BoostPipeline\Contracts\LiveProgressStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\StepCollection;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\JsonLiveProgressStore;
use SanderMuller\BoostPipeline\Run\LiveProgress;
use SanderMuller\BoostPipeline\Run\LiveProgressStoreFactory;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Run\RunState;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
use SanderMuller\BoostPipeline\Runner\LogWriter;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Runner\StepRunnerFactory;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;

/** Reads the live record while a step is running — the only moment it exists. */
final class WatchingRunner implements StepRunner
{
    public ?LiveProgress $seen = null;

    public function __construct(private LiveProgressStore $live, private bool $fail = false) {}

    public function run(Step $step, string $runId): Result
    {
        $this->seen = $this->live->read();

        return $this->fail
            ? Result::failed($step->id(), 'found problems', exitCode: 1)
            : Result::passed($step->id(), 'ok');
    }
}

final class ThrowingRunner implements StepRunner
{
    public function run(Step $step, string $runId): Result
    {
        throw new RuntimeException('runner exploded');
    }
}

final class ThrowingBatchRunner implements BatchStepRunner, StepRunner
{
    public function run(Step $step, string $runId): Result
    {
        throw new RuntimeException('runner exploded');
    }

    public function runBatch(array $steps, string $runId): array
    {
        throw new RuntimeException('batch exploded');
    }
}

beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/bp-live-'.bin2hex(random_bytes(4)).'/live.json';
    $this->live = new JsonLiveProgressStore($this->path);
});

afterEach(function (): void {
    if (is_file($this->path)) {
        unlink($this->path);
    }

    if (is_dir(dirname($this->path))) {
        rmdir(dirname($this->path));
    }
});

/** @param Closure(Steps): void $declare */
function liveRun(LiveProgressStore $live, StepRunner $runner, Closure $declare, ?TreeFingerprint $tree = null): Run
{
    return Run::start(
        Pipeline::configure()->withSteps($declare)->walk(),
        $runner,
        'r-live',
        tree: $tree,
        live: $live,
    );
}

/** A manager wired to one live store, which is what RunManager clears on discard. */
function liveManager(LiveProgressStore $live, StepRunner $runner, ?TreeFingerprint $tree = null): RunManager
{
    return new RunManager(
        // Shell then skill: resolving once records a result AND leaves an
        // awaiting record. A run with no results is rebaselined rather than
        // discarded, so a skill-only pipeline could never exercise a discard.
        Pipelines::single(Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
            $steps->in(Agent::class)->append(Skill::run('/evaluate', id: 'review'));
        })),
        new StepRunnerFactory(static fn (string $name): StepRunner => $runner),
        $tree,
        null,
        null,
        new LiveProgressStoreFactory(static fn (string $name): LiveProgressStore => $live),
    );
}

function oneShellStep(Steps $steps): void
{
    $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
}

it('records the position while it runs and clears it once resolved', function (): void {
    $runner = new WatchingRunner($this->live);
    $run = liveRun($this->live, $runner, oneShellStep(...));

    $run->resolveCurrent();

    expect($runner->seen?->runId)->toBe('r-live')
        ->and($runner->seen?->state)->toBe(RunState::Running)
        ->and($runner->seen?->stepIds)->toBe(['fmt'])
        ->and($this->live->read())->toBeNull();
});

it('records an awaiting skill step at the cursor, which writes no receipt at all', function (): void {
    $run = liveRun($this->live, new WatchingRunner($this->live), function (Steps $steps): void {
        $steps->in(Agent::class)->append(Skill::run('/evaluate', id: 'review'));
    });

    $run->resolveCurrent();

    expect($run->state())->toBe(RunState::Awaiting)
        ->and($this->live->read()?->state)->toBe(RunState::Awaiting)
        ->and($this->live->read()?->stepIds)->toBe(['review']);
});

it('leaves an awaiting record when a shell step hands over to a skill step', function (): void {
    // This is the ordinary handover, and it settles inside record() rather than
    // through the early branch — a blanket finally would erase it.
    $run = liveRun($this->live, new WatchingRunner($this->live), function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
        $steps->in(Agent::class)->append(Skill::run('/evaluate', id: 'review'));
    });

    $run->resolveCurrent();

    expect($run->state())->toBe(RunState::Awaiting)
        ->and($this->live->read()?->state)->toBe(RunState::Awaiting)
        ->and($this->live->read()?->stepIds)->toBe(['review']);
});

it('clears the record when the acknowledgement resolves the skill step', function (): void {
    $run = liveRun($this->live, new WatchingRunner($this->live), function (Steps $steps): void {
        $steps->in(Agent::class)->append(Skill::run('/evaluate', id: 'review'));
    });

    $run->resolveCurrent();
    $run->acknowledgeCurrentStep('done');

    expect($this->live->read())->toBeNull();
});

it('clears the record when a proof command throws during acknowledgement', function (): void {
    $run = liveRun($this->live, new ThrowingRunner, function (Steps $steps): void {
        $steps->in(Agent::class)->append(Skill::run('/evaluate', id: 'review')->proving('true'));
    });

    $run->resolveCurrent();
    $awaiting = $this->live->read();

    try {
        $run->acknowledgeCurrentStep('done');
    } catch (RuntimeException) {
        // The throw is the point; the record must not outlive it.
    }

    expect($awaiting?->state)->toBe(RunState::Awaiting)
        ->and($this->live->read())->toBeNull();
});

it('clears the record when the runner throws', function (): void {
    $run = liveRun($this->live, new ThrowingRunner, oneShellStep(...));

    try {
        $run->resolveCurrent();
    } catch (RuntimeException) {
        // Consumer code on the documented seam. The record must not survive it.
    }

    expect($this->live->read())->toBeNull();
});

it('clears the record when a batch runner throws', function (): void {
    $run = liveRun($this->live, new ThrowingBatchRunner, function (Steps $steps): void {
        $steps->in(Formatting::class)->parallel(function (StepCollection $steps): void {
            $steps->append(Shell::run('true', id: 'one'));
            $steps->append(Shell::run('true', id: 'two'));
        });
    });

    try {
        $run->resolveCurrent();
    } catch (RuntimeException) {
        // As above, through the batch branch.
    }

    expect($this->live->read())->toBeNull();
});

it('replaces the record when a blocked position is entered again', function (): void {
    $runner = new WatchingRunner($this->live, fail: true);
    $run = liveRun($this->live, $runner, oneShellStep(...));

    $run->resolveCurrent();
    $first = $runner->seen?->token;

    $run->resolveCurrent();
    $second = $runner->seen?->token;

    expect($run->state())->toBe(RunState::Blocked)
        ->and($first)->not->toBeNull()
        ->and($second)->not->toBe($first)
        ->and($this->live->read())->toBeNull();
});

it('leaves a record alone when the token does not match', function (): void {
    $this->live->write(new LiveProgress(
        runId: 'r-live',
        token: 'mine',
        state: RunState::Running,
        stepIds: ['fmt'],
        startedAt: gmdate('c'),
    ));

    $this->live->clear('r-live', 'someone-elses');

    expect($this->live->read()?->token)->toBe('mine');

    $this->live->clear('r-live', 'mine');

    expect($this->live->read())->toBeNull();
});

it('does not fail a run when the record cannot be written', function (): void {
    $unwritable = new JsonLiveProgressStore('/dev/null/nope/live.json');
    $run = liveRun($unwritable, new WatchingRunner($unwritable), oneShellStep(...));

    $run->resolveCurrent();

    expect($run->state())->toBe(RunState::Complete);
});

it('expires a running record once its own recorded timeout has passed', function (): void {
    $record = new LiveProgress(
        runId: 'r-live',
        token: 't',
        state: RunState::Running,
        stepIds: ['fmt'],
        startedAt: gmdate('c', 1_700_000_000),
        timeoutSeconds: 60.0,
    );

    expect($record->hasExpired(margin: 30.0, now: 1_700_000_080))->toBeFalse()
        ->and($record->hasExpired(margin: 30.0, now: 1_700_000_200))->toBeTrue();
});

it('never expires an awaiting record, however old', function (): void {
    // The package deliberately does not time out a skill step, so a page that
    // invented one would contradict the server it reports on.
    $record = new LiveProgress(
        runId: 'r-live',
        token: 't',
        state: RunState::Awaiting,
        stepIds: ['review'],
        startedAt: gmdate('c', 1_700_000_000),
        timeoutSeconds: 60.0,
    );

    expect($record->hasExpired(margin: 30.0, now: 1_900_000_000))->toBeFalse();
});

it('never expires a record from a runner that declares no timeout', function (): void {
    // A custom StepRunner enforces nothing, so ageing its record out on this
    // package's default would report a live run as interrupted.
    $record = new LiveProgress(
        runId: 'r-live',
        token: 't',
        state: RunState::Running,
        stepIds: ['fmt'],
        startedAt: gmdate('c', 1_700_000_000),
        timeoutSeconds: null,
    );

    expect($record->hasExpired(margin: 30.0, now: 1_900_000_000))->toBeFalse();
});

it('records the shipped runner ceiling so a reader can tell a dead process from a slow step', function (): void {
    $store = new class implements LiveProgressStore
    {
        /** @var list<LiveProgress> */
        public array $writes = [];

        public function write(LiveProgress $progress): bool
        {
            $this->writes[] = $progress;

            return true;
        }

        public function read(): ?LiveProgress
        {
            return $this->writes === [] ? null : $this->writes[count($this->writes) - 1];
        }

        public function clear(string $runId, string $token): void {}
    };

    $run = Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt')->timeout(123.0));
        })->walk(),
        new ProcessStepRunner(
            workingDirectory: sys_get_temp_dir(),
            logs: new LogWriter(sys_get_temp_dir().'/bp-live-logs'),
            summariser: new OutputSummariser,
            environment: new EnvironmentScrubber(sys_get_temp_dir()),
        ),
        'r-timeout',
        live: $store,
    );

    $run->resolveCurrent();

    // The step's own ceiling, not the runner default — that is what expiry reads.
    expect($store->writes[0]->timeoutSeconds)->toBe(123.0);
});

it('records no ceiling for a runner that enforces none', function (): void {
    $runner = new WatchingRunner($this->live);
    $run = liveRun($this->live, $runner, oneShellStep(...));

    $run->resolveCurrent();

    expect($runner->seen?->timeoutSeconds)->toBeNull();
});

it('clears a discarded run record when the scope changes', function (): void {
    $manager = liveManager($this->live, new WatchingRunner($this->live));

    // A skill step at the cursor leaves an awaiting record, which never expires
    // on age — so without an explicit clear it would outlive the run forever.
    $manager->open(null, 'backend')->resolveCurrent();
    $beforeDiscard = $this->live->read();

    $manager->open(null, 'frontend');

    expect($beforeDiscard?->state)->toBe(RunState::Awaiting)
        ->and($this->live->read())->toBeNull();
});

it('clears a discarded run record when the tree moves', function (): void {
    $tree = new class implements TreeFingerprint
    {
        public string $digest = 'tree-a';

        public function capture(): string
        {
            return $this->digest;
        }
    };

    $manager = liveManager($this->live, new WatchingRunner($this->live), $tree);
    $manager->open()->resolveCurrent();
    $beforeDiscard = $this->live->read();

    $tree->digest = 'tree-b';
    $manager->open();

    expect($beforeDiscard?->state)->toBe(RunState::Awaiting)
        ->and($this->live->read())->toBeNull();
});

it('rejects a record that cannot prove ownership or name a state', function (): void {
    // Without the token a clear cannot tell its own record from another's, and
    // without the state a reader cannot tell a running step from a wait.
    $base = ['run' => 'r-live', 'token' => 't', 'state' => 'running'];

    expect(LiveProgress::fromArray($base)?->runId)->toBe('r-live')
        ->and(LiveProgress::fromArray([...$base, 'token' => null]))->toBeNull()
        ->and(LiveProgress::fromArray([...$base, 'state' => 'not-a-state']))->toBeNull()
        ->and(LiveProgress::fromArray([...$base, 'run' => 42]))->toBeNull();
});

it('ignores step ids that are not strings', function (): void {
    $record = LiveProgress::fromArray([
        'run' => 'r-live',
        'token' => 't',
        'state' => 'running',
        'steps' => ['fmt', 42, null, 'analyse'],
    ]);

    expect($record?->stepIds)->toBe(['fmt', 'analyse']);
});

it('keeps the previous token when a replacement write fails', function (): void {
    // An awaiting record never expires on age, so clearing a token that never
    // reached disk would strand the record it meant to replace.
    $store = new class implements LiveProgressStore
    {
        public ?LiveProgress $stored = null;

        public bool $accept = true;

        public function write(LiveProgress $progress): bool
        {
            if (! $this->accept) {
                return false;
            }

            $this->stored = $progress;

            return true;
        }

        public function read(): ?LiveProgress
        {
            return $this->stored;
        }

        public function clear(string $runId, string $token): void
        {
            if ($this->stored?->runId === $runId && $this->stored->token === $token) {
                $this->stored = null;
            }
        }
    };

    $run = liveRun($store, new WatchingRunner($store), function (Steps $steps): void {
        $steps->in(Agent::class)->append(Skill::run('/evaluate', id: 'review'));
    });

    $run->resolveCurrent();
    $landed = $store->stored?->token;

    // The next write fails, so the run must not believe it replaced anything.
    $store->accept = false;
    $run->resolveCurrent();

    expect($store->stored?->token)->toBe($landed);

    // Acknowledging now clears the record that is actually on disk.
    $store->accept = true;
    $run->acknowledgeCurrentStep('done');

    expect($store->read())->toBeNull();
});
