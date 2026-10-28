<?php

declare(strict_types=1);

use Illuminate\Support\Sleep;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Mcp\StepPayload;
use SanderMuller\BoostPipeline\Phases\Defaults\Tests;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\JsonLiveProgressStore;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
use SanderMuller\BoostPipeline\Runner\LogWriter;
use SanderMuller\BoostPipeline\Runner\MachineSlots;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Runner\SlotHold;
use SanderMuller\BoostPipeline\Runner\SlotUnavailable;
use SanderMuller\BoostPipeline\Steps\Shell;
use Symfony\Component\Process\Process;

/**
 * A slot is shared by every pipeline server on the machine, so a step that loads
 * the whole machine runs only a few at a time. The step waits before it starts,
 * and the wait never counts toward its timeout.
 */
beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bp-slots-'.bin2hex(random_bytes(4));
    $this->slots = new MachineSlots($this->dir.'/slots');
    $this->runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($this->dir.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        timeoutSeconds: 20.0,
        slots: $this->slots,
    );
});

afterEach(function (): void {
    new Process(['rm', '-rf', $this->dir])->run();
});

it('hands out as many holds of a slot as it has room for', function (): void {
    $first = $this->slots->take('suite', 2);
    $second = $this->slots->take('suite', 2);

    expect($first)->toBeInstanceOf(SlotHold::class)
        ->and($second)->toBeInstanceOf(SlotHold::class)
        ->and($this->slots->take('suite', 2))->toBeNull()
        ->and($this->slots->take('other', 1))->toBeInstanceOf(SlotHold::class);

    $first?->release();

    expect($this->slots->take('suite', 2))->toBeInstanceOf(SlotHold::class);
});

it('frees a slot when the process holding it dies', function (): void {
    $holder = new Process([PHP_BINARY, '-r', sprintf(
        '$h = fopen(%s, "c"); flock($h, LOCK_EX); echo "held\n"; sleep(30);',
        var_export($this->dir.'/slots/suite.1.lock', true),
    )]);
    @mkdir($this->dir.'/slots', recursive: true);
    $holder->start();

    // Polled, not waitUntil(): that misses output read while the process started.
    for ($attempt = 0; $attempt < 250 && ! str_contains($holder->getOutput(), 'held'); $attempt++) {
        Sleep::usleep(20_000);
    }

    expect($this->slots->take('suite', 1))->toBeNull();

    $holder->signal(9);
    $holder->wait();

    expect($this->slots->take('suite', 1))->toBeInstanceOf(SlotHold::class);
})->skipOnWindows();

it('starts a step only once its slot is free, and keeps the wait out of its timeout', function (): void {
    $other = $this->slots->take('suite', 1);
    $step = Shell::run('sleep 0.3; echo done', id: 'suite')->exclusive('suite')->timeout(0.8);
    $pending = $this->runner->begin([$step], 'r-test');

    expect($pending->await(1.0))->toBeNull()
        ->and($pending->waitingForSlots())->toBe(['suite' => 'suite']);

    $other?->release();
    $results = $pending->await(INF) ?? [];

    expect(($results['suite'] ?? null)?->verdict)->toBe(Verdict::Passed)
        ->and(($results['suite'] ?? null)?->slotWaitMs)->toBeGreaterThanOrEqual(1000)
        ->and(($results['suite'] ?? null)?->durationMs)->toBeGreaterThanOrEqual(300)
        ->and(($results['suite'] ?? null)?->durationMs)->toBeLessThan(1000)
        ->and($pending->waitingForSlots())
        ->toBeEmpty();
});

it('holds the slot while the step runs, and has freed it by the time the verdict is in', function (): void {
    $step = Shell::run('sleep 0.3', id: 'suite')->exclusive('suite');
    $pending = $this->runner->begin([$step], 'r-test');

    expect($this->slots->take('suite', 1))->toBeNull();

    $pending->await(INF);

    expect($this->slots->take('suite', 1))->toBeInstanceOf(SlotHold::class);
});

/** Polls until the slot can be taken, without collecting any verdict. */
function slotFreesWithin(MachineSlots $slots, string $name, float $seconds): bool
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        $hold = $slots->take($name, 1);

        if ($hold instanceof SlotHold) {
            $hold->release();

            return true;
        }

        Sleep::usleep(20_000);
    }

    return false;
}

it('frees the slot when the step ends, before anything collects its verdict', function (): void {
    $pending = $this->runner->begin([Shell::run('sleep 0.2; exit 3', id: 'suite')->exclusive('suite')], 'r-test');

    expect($this->slots->take('suite', 1))->toBeNull()
        ->and(slotFreesWithin($this->slots, 'suite', 3.0))->toBeTrue();

    $result = ($pending->await(INF) ?? [])['suite'];

    expect($result->verdict)->toBe(Verdict::Failed)
        ->and($result->exitCode)->toBe(3);
})->skip(! function_exists('pcntl_signal'), 'needs ext-pcntl');

it('frees only the slot of the step that ended', function (): void {
    $pending = $this->runner->begin([
        Shell::run('sleep 0.2', id: 'short')->exclusive('short'),
        Shell::run('sleep 10', id: 'long')->exclusive('long'),
    ], 'r-test');

    try {
        expect(slotFreesWithin($this->slots, 'short', 3.0))->toBeTrue()
            ->and($this->slots->take('long', 1))->toBeNull();
    } finally {
        $pending->stop();
    }
})->skip(! function_exists('pcntl_signal'), 'needs ext-pcntl');

it('keeps the slot of a step stopped on its timeout until its onAbort command has run', function (): void {
    mkdir($this->dir.'/slots', recursive: true);
    $probe = sprintf(
        '%s -r %s > %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg(sprintf('$h = fopen(%s, "c"); echo flock($h, LOCK_EX | LOCK_NB) ? "free" : "held";', var_export($this->dir.'/slots/suite.1.lock', true))),
        escapeshellarg($this->dir.'/abort'),
    );
    $step = Shell::run('sleep 10', id: 'suite')->exclusive('suite')->timeout(0.3)->onAbort($probe);
    $pending = $this->runner->begin([$step], 'r-test');

    Sleep::usleep(500_000);
    $result = ($pending->await(INF) ?? [])['suite'];

    expect($result->verdict)->toBe(Verdict::Error)
        ->and((string) @file_get_contents($this->dir.'/abort'))->toBe('held')
        ->and($this->slots->take('suite', 1))->toBeInstanceOf(SlotHold::class);
})->skipOnWindows();

it('runs the SIGCHLD handler that was there before, and puts it back with the async setting', function (): void {
    $calls = 0;
    $previous = function () use (&$calls): void {
        $calls++;
    };
    $originalHandler = pcntl_signal_get_handler(SIGCHLD);
    $originalAsync = pcntl_async_signals();
    pcntl_signal(SIGCHLD, $previous);
    pcntl_async_signals(false);

    try {
        $pending = $this->runner->begin([Shell::run('sleep 0.2', id: 'suite')->exclusive('suite')], 'r-test');

        expect(pcntl_signal_get_handler(SIGCHLD))->not->toBe($previous)
            ->and(slotFreesWithin($this->slots, 'suite', 3.0))->toBeTrue()
            ->and($calls)->toBeGreaterThan(0);

        $pending->await(INF);

        expect(pcntl_signal_get_handler(SIGCHLD))->toBe($previous)
            ->and(pcntl_async_signals())->toBeFalse();
    } finally {
        pcntl_signal(SIGCHLD, $originalHandler);
        pcntl_async_signals($originalAsync);
    }
})->skip(! function_exists('pcntl_signal'), 'needs ext-pcntl');

it('still stops a dropped run and frees its slot, though its handler is installed', function (): void {
    $before = pcntl_signal_get_handler(SIGCHLD);
    $pending = $this->runner->begin([Shell::run('sleep 10', id: 'suite')->exclusive('suite')], 'r-test');

    expect($this->slots->take('suite', 1))->toBeNull();

    unset($pending);

    expect($this->slots->take('suite', 1))->toBeInstanceOf(SlotHold::class)
        ->and(pcntl_signal_get_handler(SIGCHLD))->toBe($before);
})->skip(! function_exists('pcntl_signal'), 'needs ext-pcntl');

it('keeps freeing slots for a second run after the first run settles', function (): void {
    $before = pcntl_signal_get_handler(SIGCHLD);
    $first = $this->runner->begin([Shell::run('true', id: 'first')->exclusive('first')], 'r-one');
    $second = $this->runner->begin([Shell::run('sleep 0.5', id: 'second')->exclusive('second')], 'r-two');

    try {
        $first->await(INF);

        expect($this->slots->take('second', 1))->toBeNull()
            ->and(slotFreesWithin($this->slots, 'second', 3.0))->toBeTrue();

        $second->await(INF);

        expect(pcntl_signal_get_handler(SIGCHLD))->toBe($before);
    } finally {
        $first->stop();
        $second->stop();
    }
})->skip(! function_exists('pcntl_signal'), 'needs ext-pcntl');

it('frees the slot of a step that waited for it, when the step ends', function (): void {
    $other = $this->slots->take('suite', 1);
    $pending = $this->runner->begin([Shell::run('sleep 0.2', id: 'suite')->exclusive('suite')], 'r-test');

    try {
        $other?->release();
        $pending->await(0.0);

        expect($pending->waitingForSlots())->toBeEmpty()
            ->and($this->slots->take('suite', 1))->toBeNull()
            ->and(slotFreesWithin($this->slots, 'suite', 3.0))->toBeTrue();
    } finally {
        $pending->stop();
    }
})->skip(! function_exists('pcntl_signal'), 'needs ext-pcntl');

it('installs no handler for steps that hold no slot', function (): void {
    $before = pcntl_signal_get_handler(SIGCHLD);
    $pending = $this->runner->begin([Shell::run('sleep 0.1', id: 'plain')], 'r-test');

    expect(pcntl_signal_get_handler(SIGCHLD))->toBe($before);

    $pending->await(INF);
})->skip(! function_exists('pcntl_signal'), 'needs ext-pcntl');

it('drops a step still waiting for its slot when the run is abandoned, without starting it', function (): void {
    $other = $this->slots->take('suite', 1);
    $pending = $this->runner->begin([Shell::run('touch '.$this->dir.'/ran', id: 'suite')->exclusive('suite')], 'r-test');

    $pending->stop();
    $other?->release();
    Sleep::usleep(300_000);

    expect($pending->waitingForSlots())
        ->toBeEmpty()
        ->and($this->dir.'/ran')->not->toBeFile()
        ->and($this->slots->take('suite', 1))->toBeInstanceOf(SlotHold::class);
});

it('tells the agent the step waits for a slot, and leaves the in-flight record without a timeout', function (): void {
    $other = $this->slots->take('suite', 1);
    $live = new JsonLiveProgressStore($this->dir.'/live.json');
    $pipeline = Pipeline::configure()->withResponseWindow(0.05)->withSteps(function (Steps $steps): void {
        $steps->in(Tests::class)->append(Shell::run('true', id: 'suite')->exclusive('suite'));
    });
    $run = Run::start($pipeline->walk(), $this->runner, live: $live, responseWindow: 0.05);

    expect($run->resolveCurrent())
        ->toBeEmpty();

    $payload = StepPayload::status($run);

    expect($payload['waiting_for_slot'] ?? null)->toBe(['suite'])
        ->and($payload['pending'] ?? '')->toStartWith('Waiting for a slot: suite waits for a free [suite] slot')
        ->and($live->read()?->timeoutSeconds)->toBeNull();

    $other?->release();
    $results = [];

    for ($attempt = 0; $attempt < 100 && $results === []; $attempt++) {
        $results = $run->resolveCurrent();
    }

    expect($results[0]->toArray())->toHaveKeys(['duration_ms', 'slot_wait_ms']);
});

it('takes the slot of a whole-scope step only when its scope is whole', function (string $scope, bool $waits): void {
    $other = $this->slots->take('suite', 1);
    $step = Shell::run('true', id: 'suite')->inspecting($scope)->exclusive('suite', onlyWhenWhole: true);
    $pending = $this->runner->begin([$step], 'r-test');

    try {
        expect($pending->waitingForSlots())->toBe($waits ? ['suite' => 'suite'] : []);

        if (! $waits) {
            $result = ($pending->await(INF) ?? [])['suite'];

            expect($result->verdict)->toBe(Verdict::Passed)
                ->and($result->slotWaitMs)->toBeNull();
        }
    } finally {
        $pending->stop();
        $other?->release();
    }
})->with([
    'whole' => ['echo "*"', true],
    'the marker among files' => ['printf "a.php\n*\n"', true],
    'a selection' => ['printf "a.php\nb.php\n"', false],
    'empty' => ['true', false],
]);

it('still takes the slot whatever the scope says, without onlyWhenWhole', function (): void {
    $other = $this->slots->take('suite', 1);
    $pending = $this->runner->begin([Shell::run('true', id: 'suite')->inspecting('echo a.php')->exclusive('suite')], 'r-test');

    try {
        expect($pending->waitingForSlots())->toBe(['suite' => 'suite']);
    } finally {
        $pending->stop();
        $other?->release();
    }
});

it('decides the slot from the scope on the blocking path too', function (): void {
    $other = $this->slots->take('suite', 1);
    $selection = $this->runner->run(Shell::run('true', id: 'suite')->inspecting('echo a.php')->exclusive('suite', onlyWhenWhole: true), 'r-test');
    $other?->release();
    $whole = $this->runner->run(Shell::run('true', id: 'suite')->inspecting('echo "*"')->exclusive('suite', onlyWhenWhole: true), 'r-test');

    expect($selection->verdict)->toBe(Verdict::Passed)
        ->and($selection->slotWaitMs)->toBeNull()
        ->and($whole->verdict)->toBe(Verdict::Passed)
        ->and($whole->slotWaitMs)->not->toBeNull();
});

it('takes no slot when the scope command fails', function (): void {
    $step = Shell::run('true', id: 'suite')->inspecting('echo "*"; exit 3')->exclusive('suite', onlyWhenWhole: true);
    $result = ($this->runner->begin([$step], 'r-test')->await(INF) ?? [])['suite'];

    expect($result->verdict)->toBe(Verdict::Error)
        ->and($this->slots->take('suite', 1))->toBeInstanceOf(SlotHold::class);
});

it('removes the scope file of a step stopped while it runs', function (): void {
    mkdir($this->dir);
    $step = Shell::run(sprintf('echo "$BOOST_PIPELINE_SCOPE_FILE" > %s; sleep 10', escapeshellarg($this->dir.'/path')), id: 'suite')
        ->inspecting('echo "*"')
        ->exclusive('suite', onlyWhenWhole: true);
    $pending = $this->runner->begin([$step], 'r-test');

    for ($attempt = 0; $attempt < 250 && ! is_file($this->dir.'/path'); $attempt++) {
        Sleep::usleep(20_000);
    }

    $path = trim((string) @file_get_contents($this->dir.'/path'));

    expect($path)->toBeFile();

    $pending->stop();

    expect($path)->not->toBeFile()
        ->and($this->slots->take('suite', 1))->toBeInstanceOf(SlotHold::class);
})->skipOnWindows();

it('refuses a whole-scope slot on a step without a scope command', function (): void {
    $pipeline = Pipeline::configure()->withResponseWindow(90)->withSteps(function (Steps $steps): void {
        $steps->in(Tests::class)->append(Shell::run('true', id: 'suite')->exclusive('suite', onlyWhenWhole: true));
    });

    expect(fn (): mixed => $pipeline->walk())->toThrow(InvalidPipelineConfigException::class, 'declares no inspecting() command');
});

it('accepts a whole-scope slot on a step with a scope command', function (): void {
    $pipeline = Pipeline::configure()->withResponseWindow(90)->withSteps(function (Steps $steps): void {
        $steps->in(Tests::class)->append(Shell::run('true', id: 'suite')->inspecting('echo "*"')->exclusive('suite', onlyWhenWhole: true));
    });

    expect($pipeline->walk()->steps)->toHaveCount(1);
});

it('refuses a slot in a pipeline without a response window', function (): void {
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Tests::class)->append(Shell::run('true', id: 'suite')->exclusive('suite'));
    });

    expect(fn (): mixed => $pipeline->walk())->toThrow(InvalidPipelineConfigException::class, 'withResponseWindow()');
});

it('refuses a slot name that is not a plain file name, and a capacity below one', function (Closure $declare, string $message): void {
    expect($declare)->toThrow(InvalidPipelineConfigException::class, $message);
})->with([
    'a path' => [fn (): Shell => Shell::run('true', id: 'suite')->exclusive('../suite'), 'names the slot [../suite]'],
    'empty' => [fn (): Shell => Shell::run('true', id: 'suite')->exclusive(''), 'names the slot []'],
    'zero' => [fn (): Shell => Shell::run('true', id: 'suite')->exclusive('suite', 0), 'capacity of 0'],
]);

it('times every step it runs, and says nothing of a slot for a step that takes none', function (): void {
    $result = $this->runner->run(Shell::run('sleep 0.2', id: 'plain'), 'r-test');

    expect($result->durationMs)->toBeGreaterThanOrEqual(200)
        ->and($result->toArray())->toHaveKey('duration_ms')
        ->and($result->toArray())->not->toHaveKey('slot_wait_ms')
        ->and(Result::acknowledged('review', 'done')->toArray())->not->toHaveKey('duration_ms');
});

it('fails a step whose slot cannot exist, rather than waiting for it forever', function (): void {
    mkdir($this->dir);
    file_put_contents($this->dir.'/not-a-directory', '');
    $runner = new ProcessStepRunner(
        workingDirectory: sys_get_temp_dir(),
        logs: new LogWriter($this->dir.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber(sys_get_temp_dir()),
        slots: new MachineSlots($this->dir.'/not-a-directory/slots'),
    );
    $step = Shell::run('true', id: 'suite')->exclusive('suite');
    $pending = $runner->begin([$step], 'r-test');

    expect($pending->waitingForSlots())
        ->toBeEmpty()
        ->and(($pending->await(INF) ?? [])['suite']->summary ?? null)->toStartWith('Could not run: the slot directory')
        ->and($runner->run($step, 'r-test')->verdict)->toBe(Verdict::Error);
});

it('does not open a lock file that is a link, and leaves its target alone', function (): void {
    mkdir($this->dir.'/slots', recursive: true);
    file_put_contents($this->dir.'/target', 'private');
    chmod($this->dir.'/target', 0600);
    symlink($this->dir.'/target', $this->dir.'/slots/suite.1.lock');

    expect(fn (): ?SlotHold => $this->slots->take('suite', 1))->toThrow(SlotUnavailable::class)
        ->and(fileperms($this->dir.'/target') & 0777)->toBe(0600)
        ->and($this->slots->take('suite', 2))->toBeInstanceOf(SlotHold::class);
})->skipOnWindows();

it('frees a slot when its holder dies, even while a process the holder started lives on', function (): void {
    $script = $this->dir.'/holder.php';
    mkdir($this->dir);
    file_put_contents($script, sprintf(<<<'PHP'
        <?php
        require %s;
        $hold = (new SanderMuller\BoostPipeline\Runner\MachineSlots(%s))->take('suite', 1);
        $child = proc_open(['sleep', '30'], [], $pipes);
        echo 'held '.proc_get_status($child)['pid']."\n";
        sleep(30);
        PHP, var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true), var_export($this->dir.'/slots', true)));
    $holder = new Process([PHP_BINARY, $script]);
    $holder->start();

    for ($attempt = 0; $attempt < 250 && ! str_contains($holder->getOutput(), 'held'); $attempt++) {
        Sleep::usleep(20_000);
    }

    expect($this->slots->take('suite', 1))->toBeNull();

    $holder->signal(9);
    $holder->wait();

    try {
        expect($this->slots->take('suite', 1))->toBeInstanceOf(SlotHold::class);
    } finally {
        preg_match('/held (\d+)/', $holder->getOutput(), $child);
        new Process(['kill', $child[1] ?? '0'])->run();
    }
})->skipOnWindows();
