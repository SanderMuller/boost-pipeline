<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Runner\CommandPreflight;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;
use SanderMuller\BoostPipeline\Walk\Walk;

function walkOf(string ...$commands): Walk
{
    return Pipeline::configure()
        ->withSteps(function (Steps $steps) use ($commands): void {
            foreach ($commands as $index => $command) {
                $steps->in(Formatting::class)->append(Shell::run($command, id: "step-{$index}"));
            }
        })
        ->walk();
}

beforeEach(function (): void {
    $this->project = sys_get_temp_dir().'/bp-preflight-'.bin2hex(random_bytes(4));
    mkdir($this->project.'/vendor/bin', recursive: true);
    touch($this->project.'/vendor/bin/pint');

    $this->preflight = new CommandPreflight($this->project);
});

afterEach(function (): void {
    @unlink($this->project.'/vendor/bin/pint');
    @rmdir($this->project.'/vendor/bin');
    @rmdir($this->project.'/vendor');
    @rmdir($this->project);
});

it('says nothing when every checkable binary is present', function (): void {
    expect($this->preflight->warnings(walkOf('vendor/bin/pint --test')))
        ->toBeEmpty();
});

it('warns about a missing binary at open time rather than mid-walk', function (): void {
    // The real case: no node_modules, so the third step halted a run that had
    // already paid for two minutes of server-verified work.
    $warnings = $this->preflight->warnings(walkOf(
        'vendor/bin/pint --test',
        'node_modules/.bin/oxlint --type-aware resources/js',
    ));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('node_modules/.bin/oxlint')
        ->and($warnings[0])->toContain('[step-1]');
});

it('stays quiet about commands a filesystem check cannot answer', function (): void {
    // These resolve through PATH or through another tool's dispatch. Warning
    // about them would flag steps that run fine, and a warning nobody trusts is
    // worse than none.
    expect($this->preflight->warnings(walkOf(
        'php artisan test',
        'composer phpstan',
        'yarn lint-all',
        '/usr/local/bin/something',
        'php artisan test $(php artisan affected --plain)',
    )))
        ->toBeEmpty();
});

it('ignores skill steps, which run no binary', function (): void {
    $walk = Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Skill::run('/code-review'));
        })
        ->walk();

    expect($this->preflight->warnings($walk))
        ->toBeEmpty();
});
