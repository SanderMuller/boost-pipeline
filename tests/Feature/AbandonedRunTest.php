<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\RunHistoryStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\Tests;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\JsonRunHistoryStore;
use SanderMuller\BoostPipeline\Run\RunHistoryStoreFactory;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Runner\StepRunnerFactory;
use SanderMuller\BoostPipeline\Steps\Shell;
use Symfony\Component\Process\Process;

/**
 * A run its server replaced will never be walked again, but its server lives on,
 * so nothing else can tell. The replacement marks its history record.
 */
beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bp-abandoned-'.bin2hex(random_bytes(4));
    $this->history = new JsonRunHistoryStore($this->dir);
    $this->runs = new RunManager(
        Pipelines::single(Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt')->tagged('quick'));
            $steps->in(Tests::class)->append(Shell::run('true', id: 'test'));
        })),
        new StepRunnerFactory(static fn (): StepRunner => new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        }),
        history: new RunHistoryStoreFactory(fn (): RunHistoryStore => $this->history),
    );
});

afterEach(function (): void {
    new Process(['rm', '-rf', $this->dir])->run();
});

it('marks the record of a run it replaced before the run finished', function (): void {
    $first = $this->runs->open();
    $first->resolveCurrent();

    expect($this->history->read($first->id)?->shownState())->toBe('running')
        ->and($this->history->read($first->id)?->owner)->toBe(getmypid());

    $this->runs->open(selection: 'quick');

    expect($this->history->read($first->id)?->abandoned)->toBeTrue()
        ->and($this->history->read($first->id)?->shownState())->toBe('abandoned');
});

it('leaves the record of a finished run as it is', function (): void {
    $first = $this->runs->open();
    $first->resolveCurrent();
    $first->resolveCurrent();

    $this->runs->open(selection: 'quick');

    expect($this->history->read($first->id)?->abandoned)->toBeFalse()
        ->and($this->history->read($first->id)?->shownState())->toBe('complete');
});
