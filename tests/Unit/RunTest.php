<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\AcknowledgementNotAllowed;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Run\RunState;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;

/** A phase no pipeline registers, so steps declared into it are always dropped. */
final class UnregisteredPhase implements Phase
{
    public function id(): string
    {
        return 'unregistered';
    }

    public function name(): string
    {
        return 'Unregistered';
    }
}

/** A runner whose verdicts the test dictates, keyed by step id. */
final class FakeRunner implements StepRunner
{
    /** @param array<string, Verdict> $verdicts */
    public function __construct(private array $verdicts = [], public int $calls = 0) {}

    public function run(Step $step, string $runId): Result
    {
        $this->calls++;

        return match ($this->verdicts[$step->id()] ?? Verdict::Passed) {
            Verdict::Passed => Result::passed($step->id(), 'ok'),
            Verdict::Failed => Result::failed($step->id(), 'problems', 1),
            Verdict::Error => Result::error($step->id(), 'binary missing'),
            Verdict::Acknowledged => Result::acknowledged($step->id(), 'ack'),
        };
    }

    public function fail(string $stepId): self
    {
        $this->verdicts[$stepId] = Verdict::Failed;

        return $this;
    }

    public function pass(string $stepId): self
    {
        $this->verdicts[$stepId] = Verdict::Passed;

        return $this;
    }
}

/** @param Closure(Steps): void $callback */
function pipelineWith(Closure $callback): Pipeline
{
    return Pipeline::configure()->withSteps($callback);
}

function threeStepRun(FakeRunner $runner): Run
{
    return Run::start(
        pipelineWith(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
            $steps->in(StaticAnalysis::class)->append(Shell::run('composer phpstan'));
            $steps->in(Agent::class)->append(Skill::run('/evaluate'));
        })->walk(),
        $runner,
        'r-test',
    );
}

it('starts open on the first step and reveals nothing beyond it', function (): void {
    $run = threeStepRun(new FakeRunner);

    expect($run->state())->toBe(RunState::Open)
        ->and($run->currentStep()?->step->id())->toBe('pint')
        ->and($run->position())->toBe('1/3');
});

it('advances by exactly one on a pass', function (): void {
    $run = threeStepRun(new FakeRunner);
    $run->resolveCurrent();

    expect($run->currentStep()?->step->id())->toBe('phpstan')
        ->and($run->state())->toBe(RunState::Running);
});

it('holds the cursor on a failure and returns the same step however often it is called', function (): void {
    $runner = (new FakeRunner)->fail('phpstan');
    $run = threeStepRun($runner);
    $run->resolveCurrent();

    foreach (range(1, 4) as $ignored) {
        $run->resolveCurrent();
        expect($run->currentStep()?->step->id())->toBe('phpstan')
            ->and($run->state())->toBe(RunState::Blocked);
    }

    // Fixing it lets the walk continue from exactly where it stopped.
    $runner->pass('phpstan');
    $run->resolveCurrent();

    expect($run->currentStep()?->step->id())->toBe('evaluate');
});

it('halts on an error, distinctly from blocking on a failure', function (): void {
    $run = threeStepRun(new FakeRunner(['pint' => Verdict::Error]));
    $run->resolveCurrent();

    expect($run->state())->toBe(RunState::Halted)
        ->and($run->state())->not->toBe(RunState::Blocked)
        ->and($run->currentStep()?->step->id())->toBe('pint');
});

it('enters awaiting the moment the cursor lands on a skill step', function (): void {
    $runner = new FakeRunner;
    $run = threeStepRun($runner);
    $run->resolveCurrent();
    $run->resolveCurrent();

    expect($run->state())->toBe(RunState::Awaiting)
        ->and($run->currentStep()?->step->id())->toBe('evaluate');
});

it('never executes a skill step, however often next_step is called', function (): void {
    $runner = new FakeRunner;
    $run = threeStepRun($runner);
    $run->resolveCurrent();
    $run->resolveCurrent();

    $callsBefore = $runner->calls;

    expect($run->resolveCurrent())->toBeEmpty()
        ->and($run->resolveCurrent())->toBeEmpty()
        ->and($run->state())->toBe(RunState::Awaiting)
        ->and($runner->calls)->toBe($callsBefore);
});

it('advances a skill step only on acknowledgement', function (): void {
    $run = threeStepRun(new FakeRunner);
    $run->resolveCurrent();
    $run->resolveCurrent();

    $result = $run->acknowledgeCurrentStep('ran /evaluate, fixed 2 issues');

    expect($result->verdict)->toBe(Verdict::Acknowledged)
        ->and($result->verdict->isVerified())->toBeFalse()
        ->and($run->state())->toBe(RunState::Complete);
});

it('refuses an acknowledgement for a shell step', function (): void {
    $run = threeStepRun(new FakeRunner);

    $run->acknowledgeCurrentStep('I ran pint myself');
})->throws(AcknowledgementNotAllowed::class, 'resolved by the server');

it('reports complete but NOT all_verified when a run ends on acknowledgements', function (): void {
    $run = threeStepRun(new FakeRunner);
    $run->resolveCurrent();
    $run->resolveCurrent();
    $run->acknowledgeCurrentStep('done');

    expect($run->state())->toBe(RunState::Complete)
        ->and($run->allVerified())->toBeFalse()
        ->and($run->acknowledgedCount())->toBe(1)
        ->and($run->serverRunTally())->toBe(['passed' => 2, 'failed' => 0, 'error' => 0]);
});

it('reports all_verified only when every step was a server-verified pass', function (): void {
    $run = Run::start(
        pipelineWith(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
        })->walk(),
        new FakeRunner,
        'r-test',
    );

    $run->resolveCurrent();

    expect($run->state())->toBe(RunState::Complete)
        ->and($run->allVerified())->toBeTrue();
});

it('counts a failed step under server_run, not under acknowledged', function (): void {
    $run = threeStepRun(new FakeRunner(['pint' => Verdict::Failed]));
    $run->resolveCurrent();

    expect($run->serverRunTally())->toBe(['passed' => 0, 'failed' => 1, 'error' => 0])
        ->and($run->acknowledgedCount())->toBe(0);
});

it('is complete immediately when the pipeline has no steps', function (): void {
    $run = Run::start(Pipeline::configure()->walk(), new FakeRunner, 'r-empty');

    expect($run->state())->toBe(RunState::Complete)
        ->and($run->currentStep())->toBeNull()
        ->and($run->allVerified())->toBeFalse();
});

it('refuses to claim all_verified when a declared step was dropped before the walk began', function (): void {
    // The dangerous shape: a step is declared into a phase that is not registered,
    // so it is dropped, every remaining step passes, and the run would otherwise
    // report a fully verified pass while a gate the config declared never ran.
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test'));
        $steps->in(UnregisteredPhase::class)->append(Shell::run('true', id: 'never-ran'));
    });

    $run = Run::start($pipeline->walk(), new FakeRunner, 'r-dropped');
    $run->resolveCurrent();

    expect($run->state())->toBe(RunState::Complete)
        ->and($run->walk->notices)->toHaveCount(1)
        ->and($run->walk->notices[0])->toContain('never-ran')
        ->and($run->allVerified())->toBeFalse();
});
