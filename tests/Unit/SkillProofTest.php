<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Enums\Verdict;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
use SanderMuller\BoostPipeline\Runner\LogWriter;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Steps\Skill;

/**
 * A proof is the only way agent work becomes something the server verified, so
 * these go through a real ProcessStepRunner: a fake would prove nothing about
 * whether the command actually ran.
 */
beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bp-proof-'.bin2hex(random_bytes(4));
    mkdir($this->dir);

    $this->runner = new ProcessStepRunner(
        workingDirectory: $this->dir,
        logs: new LogWriter($this->dir.'/logs'),
        summariser: new OutputSummariser,
        environment: new EnvironmentScrubber($this->dir),
        timeoutSeconds: 20.0,
    );
});

afterEach(function (): void {
    $logs = glob($this->dir.'/logs/*.log');

    foreach ($logs === false ? [] : $logs as $log) {
        unlink($log);
    }

    @rmdir($this->dir.'/logs');
    @unlink($this->dir.'/artifact.txt');
    @rmdir($this->dir);
});

function runWithSkill(Skill $skill, ProcessStepRunner $runner): Run
{
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps) use ($skill): void {
        $steps->in(Agent::class)->append($skill);
    });

    $run = Run::start($pipeline->walk(), $runner, 'r-proof');
    $run->resolveCurrent();

    return $run;
}

it('reports passed, not acknowledged, when the proof holds', function (): void {
    touch($this->dir.'/artifact.txt');

    $run = runWithSkill(Skill::run('/eye-verification')->proving('test -f artifact.txt'), $this->runner);
    $result = $run->acknowledgeCurrentStep('took the screenshots');

    // The whole point: agent work the server could check is no longer merely
    // acknowledged, so a run of these can reach all_verified.
    expect($result->verdict)->toBe(Verdict::Passed)
        ->and($result->summary)->toContain('took the screenshots')
        ->and($run->allVerified())->toBeTrue();
});

it('blocks and returns the same step when the artifact is not there', function (): void {
    $run = runWithSkill(Skill::run('/eye-verification')->proving('test -f artifact.txt'), $this->runner);
    $result = $run->acknowledgeCurrentStep('took the screenshots');

    // "I did it" without the artifact must not be a way past the cursor.
    expect($result->verdict)->toBe(Verdict::Failed)
        ->and($run->currentStep()?->step->id())->toBe('eye-verification')
        ->and($run->allVerified())->toBeFalse();
});

it('names the proof command when it does not hold', function (): void {
    // A silent proof such as `grep -q` produced "Failed with no output", which
    // reads like the skill failed and names nothing the agent can act on — and
    // the agent is handed this step again, so the message is the whole
    // instruction.
    $run = runWithSkill(
        Skill::run('/eye-verification')->proving('ls artifacts/*.png 2>/dev/null | grep -q .'),
        $this->runner,
    );

    $result = $run->acknowledgeCurrentStep('took the screenshots');

    expect($result->summary)->toContain('Proof did not hold')
        ->and($result->summary)->toContain('grep -q')
        ->and($result->summary)->toContain('[eye-verification]');
});

it('still acknowledges a step that declares no proof', function (): void {
    // Work that leaves nothing to find keeps the honest verdict.
    $run = runWithSkill(Skill::run('/code-review'), $this->runner);

    expect($run->acknowledgeCurrentStep('reviewed it')->verdict)->toBe(Verdict::Acknowledged);
});

it('keeps a proof through mutating(), so declaration order does not matter', function (): void {
    $skill = Skill::run('/evaluate')->proving('test -f artifact.txt')->mutating();

    expect($skill->proof())->toBe('test -f artifact.txt')
        ->and($skill->mutates())->toBeTrue()
        ->and(Skill::run('/evaluate')->mutating()->proving('test -f x')->mutates())->toBeTrue();
});
