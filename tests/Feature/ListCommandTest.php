<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Defaults\Tests;
use SanderMuller\BoostPipeline\Phases\Phases;
use SanderMuller\BoostPipeline\Phases\StepCollection;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Steps\Shell;

/**
 * `pipeline:list` describes; it never gates.
 *
 * It is the only one of the three commands that reads no record, which is the
 * whole point: a pipeline that has never run is invisible to `pipeline:verify`
 * and `pipeline:history`, and it is exactly the pipeline someone choosing
 * between several needs described.
 */
beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/boost-pipeline-list-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    removeListStorage($this->base);
});

/**
 * A base path holding a `.config/pipeline.php`, so the loader reports the
 * project as opted in. The file's contents never matter: the command asks the
 * loader whether one exists and reads the declaration from `Pipelines`.
 */
function declareConfigFileExists(string $base): void
{
    mkdir($base.'/'.dirname(PipelineLoader::CONFIG_PATH), recursive: true);
    file_put_contents($base.'/'.PipelineLoader::CONFIG_PATH, '<?php return null;');

    app()->instance(PipelineLoader::class, new PipelineLoader($base));
}

function removeListStorage(string $path): void
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
        is_dir($child) ? removeListStorage($child) : unlink($child);
    }

    rmdir($path);
}

/**
 * @param  array<string, string>  $arguments
 * @return array{code: int, output: string}
 */
function runList(array $arguments = []): array
{
    $code = Artisan::call('pipeline:list', $arguments);

    return ['code' => $code, 'output' => Artisan::output()];
}

it('reports the question each pipeline answers', function (): void {
    declareConfigFileExists($this->base);

    app()->instance(Pipelines::class, Pipelines::fromArray([
        'change' => Pipeline::configure()
            ->withPurpose('Check the work in progress.')
            ->withSteps(function (Steps $steps): void {
                $steps->in(Formatting::class)->append(Shell::run('true', id: 'format'));
            }),
        'closeout' => Pipeline::configure()
            ->withPurpose('Confirm the branch is ready to review.')
            ->withSteps(function (Steps $steps): void {
                $steps->in(Tests::class)->append(Shell::run('true', id: 'suite'));
            }),
    ], 'config'));

    $result = runList();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('change')
        ->toContain('Check the work in progress.')
        ->toContain('closeout')
        ->toContain('Confirm the branch is ready to review.');
});

it('describes a pipeline that has never run', function (): void {
    // The reason this command exists. No receipt, no history, no live record —
    // the other two commands have nothing to say here.
    declareConfigFileExists($this->base);

    app()->instance(Pipelines::class, Pipelines::single(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'analyse'));
        }),
    ));

    $result = runList();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('analyse')
        ->toContain('Static analysis');
});

it('names the scopes a run can be narrowed to', function (): void {
    declareConfigFileExists($this->base);

    app()->instance(Pipelines::class, Pipelines::single(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'pint')->tagged('backend'));
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'lint')->tagged('frontend'));
            $steps->in(Tests::class)->append(Shell::run('true', id: 'suite'));
        }),
    ));

    $result = runList();

    expect($result['output'])->toContain('--only accepts')
        ->toContain('backend, frontend');
});

it('says an untagged step runs in every scope rather than leaving it blank', function (): void {
    // `Walk::selected()` returns true for an empty tag list whatever the
    // selection, so `--only=frontend` runs `suite` too. Rendering nothing invited
    // the opposite reading: no tag as no scope.
    declareConfigFileExists($this->base);

    app()->instance(Pipelines::class, Pipelines::single(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'lint')->tagged('frontend'));
            $steps->in(Tests::class)->append(Shell::run('true', id: 'suite'));
        }),
    ));

    $output = runList()['output'];

    expect($output)->toContain('Tests · every scope')
        ->and($output)->toContain('Formatting · frontend');
});

it('does not qualify steps by scope in a pipeline nobody tagged', function (): void {
    // With no tag anywhere there is no `--only` to narrow, so the note would be on
    // every row about a flag that selects nothing.
    declareConfigFileExists($this->base);

    app()->instance(Pipelines::class, Pipelines::single(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'format'));
            $steps->in(Tests::class)->append(Shell::run('true', id: 'suite'));
        }),
    ));

    $output = runList()['output'];

    expect($output)->not->toContain('every scope')
        ->and($output)->not->toContain('--only accepts');
});

it('says so plainly when a pipeline declares no purpose', function (): void {
    // A blank column would read as a rendering fault rather than as a config that
    // never said which question it answers.
    declareConfigFileExists($this->base);

    app()->instance(Pipelines::class, Pipelines::single(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'format'));
        }),
    ));

    expect(runList()['output'])->toContain('no purpose declared');
});

it('marks a parallel group as one position', function (): void {
    declareConfigFileExists($this->base);

    app()->instance(Pipelines::class, Pipelines::single(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->parallel(function (StepCollection $steps): void {
                $steps->append(Shell::run('true', id: 'pint'));
                $steps->append(Shell::run('true', id: 'lint'));
            });
        }),
    ));

    expect(runList()['output'])->toContain('parallel');
});

it('reports a step that is declared into an unregistered phase', function (): void {
    // It reads as part of the pipeline and it never runs. A listing that omitted
    // it would describe a pipeline doing more than it does.
    declareConfigFileExists($this->base);

    app()->instance(Pipelines::class, Pipelines::single(
        Pipeline::configure()
            ->withPhases(function (Phases $phases): void {
                $phases->remove(Tests::class);
            })
            ->withSteps(function (Steps $steps): void {
                $steps->in(Formatting::class)->append(Shell::run('true', id: 'format'));
                $steps->in(Tests::class)->append(Shell::run('true', id: 'suite'));
            }),
    ));

    $result = runList();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('suite')
        ->toContain('never runs');
});

it('warns about a pipeline that declares no steps', function (): void {
    declareConfigFileExists($this->base);

    app()->instance(Pipelines::class, Pipelines::single(Pipeline::configure()));

    $result = runList();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('Walks no steps');
});

it('does not tell a pipeline whose every step was dropped that it declared none', function (): void {
    // It declared two. Both sit in a phase nobody registered, so the walk is empty
    // and they are reported as dropped. Saying it declares no steps would send the
    // reader looking for the wrong mistake.
    declareConfigFileExists($this->base);

    app()->instance(Pipelines::class, Pipelines::single(
        Pipeline::configure()
            ->withPhases(function (Phases $phases): void {
                $phases->remove(Tests::class);
            })
            ->withSteps(function (Steps $steps): void {
                $steps->in(Tests::class)->append(Shell::run('true', id: 'suite'));
            }),
    ));

    $output = runList()['output'];

    expect($output)->toContain('Walks no steps')
        ->and($output)->toContain('never runs')
        ->and($output)->not->toContain('Declares no steps');
});

it('says no pipeline is declared rather than showing an empty one', function (): void {
    // With no config file the container falls back to an empty `default`
    // pipeline. Printing that would send a reader looking for the mistake in a
    // file they never wrote.
    // Never created, so the loader finds nothing where the others find a file.
    app()->instance(PipelineLoader::class, new PipelineLoader($this->base));

    $result = runList();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('No pipeline is declared')
        ->toContain(PipelineLoader::CONFIG_PATH);
});
