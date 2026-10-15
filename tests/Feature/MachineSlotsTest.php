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

it('holds the slot while the step runs, and frees it once the verdict is in', function (): void {
    $step = Shell::run('sleep 0.3', id: 'suite')->exclusive('suite');
    $pending = $this->runner->begin([$step], 'r-test');

    expect($this->slots->take('suite', 1))->toBeNull();

    $pending->await(INF);

    expect($this->slots->take('suite', 1))->toBeInstanceOf(SlotHold::class);
});

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
