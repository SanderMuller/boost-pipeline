<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Run\ReceiptStoreFactory;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Runner\StepRunnerFactory;

/**
 * Where a receipt lives, and what is deliberately not read.
 *
 * The path is the only place a pipeline's name is recorded. Storing it inside
 * the file as well would create two sources of truth for one fact, which is the
 * shape that produced the scope and coverage defects this package has already
 * had to fix.
 */
beforeEach(function (): void {
    app()->instance(Pipelines::class, Pipelines::fromArray([
        'pr' => Pipeline::configure(),
        'release' => Pipeline::configure(),
        'pre-release-2' => Pipeline::configure(),
    ], '.config/pipeline.php'));

    $this->directory = storage_path('logs/pipeline/receipts');
});

afterEach(function (): void {
    $written = glob($this->directory.'/*.json');

    foreach ($written === false ? [] : $written as $file) {
        @unlink($file);
    }

    @unlink(storage_path('logs/pipeline/receipt.json'));
});

it('writes each pipeline to its own file, named for it', function (): void {
    $factory = resolve(ReceiptStoreFactory::class);

    $factory->for('pr')->write(new Receipt(
        runId: 'r-pr', state: 'complete', allVerified: true, tree: 'tree-a',
        stale: null, verdicts: ['phpstan' => 'passed'], recordedAt: 'now',
        coverage: 'complete', asserted: ['phpstan'],
    ));

    $factory->for('release')->write(new Receipt(
        runId: 'r-release', state: 'complete', allVerified: true, tree: 'tree-a',
        stale: null, verdicts: ['audit' => 'passed'], recordedAt: 'now',
        coverage: 'complete', asserted: ['audit'],
    ));

    expect($this->directory.'/pr.json')
        ->toBeFile()
        ->and($this->directory.'/release.json')
        ->toBeFile()
        // Two answers on disk at once, which one file could never hold.
        ->and($factory->for('pr')->read()?->runId)->toBe('r-pr')
        ->and($factory->for('release')->read()?->runId)->toBe('r-release');
});

it('does not read a receipt written before pipelines had names', function (): void {
    // Unknown is not clean. The first verify after upgrading reports no run
    // recorded, which is the fail-closed direction and consistent with how an
    // absent `coverage` or `asserted` key already reads.
    app()->instance(Pipelines::class, Pipelines::single(Pipeline::configure()));

    $legacy = storage_path('logs/pipeline/receipt.json');

    if (! is_dir(dirname($legacy))) {
        mkdir(dirname($legacy), recursive: true);
    }

    file_put_contents($legacy, json_encode([
        'run' => 'r-legacy', 'state' => 'complete', 'all_verified' => true,
        'tree' => 'tree-a', 'verdicts' => ['phpstan' => 'passed'],
        'recorded_at' => '2026-01-01T00:00:00+00:00',
    ]));

    expect(resolve(ReceiptStoreFactory::class)->for('default')->read())->toBeNull()
        // And it is left alone rather than deleted: removing a file it no longer
        // recognises is not this package's business.
        ->and($legacy)
        ->toBeFile();
});

it('refuses a name the project does not declare, so nothing escapes the directory', function (): void {
    // The loader validates every name it accepts, but this factory is public API
    // and could be called with anything. `JsonReceiptStore` creates parent
    // directories on write, so an unchecked `../../secrets` would write outside
    // the receipts directory entirely.
    expect(fn () => resolve(ReceiptStoreFactory::class)->for('../../escape'))
        ->toThrow(InvalidPipelineConfigException::class)
        ->and(fn () => resolve(ReceiptStoreFactory::class)->for('undeclared'))
        ->toThrow(InvalidPipelineConfigException::class);
});

it('keeps a declared name that could not escape the receipts directory', function (): void {
    // The other half of the rule: every declared name resolves inside it.
    $path = resolve(ReceiptStoreFactory::class)->for('pre-release-2');

    resolve(ReceiptStoreFactory::class)->for('pre-release-2')->write(new Receipt(
        runId: 'r-x', state: 'complete', allVerified: true, tree: 'tree-a',
        stale: null, verdicts: ['a' => 'passed'], recordedAt: 'now',
        coverage: 'complete', asserted: ['a'],
    ));

    expect($path)->toBe(resolve(ReceiptStoreFactory::class)->for('pre-release-2'))
        ->and($this->directory.'/pre-release-2.json')
        ->toBeFile();
});

it('refuses to resolve a single runner or store when several pipelines are declared', function (): void {
    // The aliases that predate names. Returning the first pipeline's would hand
    // back a receipt answering for a walk nobody asked about, silently.
    // `StepRunner` is deliberately not among them: it is a documented seam that
    // must keep resolving, and the factory honours whatever is bound to it.
    expect(fn () => resolve(ReceiptStore::class))
        ->toThrow(InvalidPipelineConfigException::class)
        ->and(fn () => resolve(Pipeline::class))
        ->toThrow(InvalidPipelineConfigException::class);
});

it('keeps the documented runner seam working for every pipeline', function (): void {
    // "Bind your own over the container's and every step the server resolves goes
    // through it" is a README promise. Routing runs through a factory that always
    // built the shipped runner would have broken it silently.
    $mine = new class implements StepRunner
    {
        public function run(Step $step, string $runId): Result
        {
            return Result::passed($step->id(), 'mine');
        }
    };

    app()->instance(StepRunner::class, $mine);

    $factory = resolve(StepRunnerFactory::class);

    expect($factory->for('pr'))->toBe($mine)
        ->and($factory->for('release'))->toBe($mine);
});

it('still varies the shipped runner per pipeline when nothing is bound over it', function (): void {
    expect(resolve(StepRunnerFactory::class)->for('pr'))
        ->toBeInstanceOf(ProcessStepRunner::class);
});

it('resolves the receipt alias to the sole pipeline for a project declaring one', function (): void {
    // Not just "it resolves": the alias has to point at the same receipt the
    // factory writes for that pipeline, or a consumer reading through it would
    // answer from a file nothing writes.
    app()->instance(Pipelines::class, Pipelines::single(Pipeline::configure()));

    resolve(ReceiptStoreFactory::class)->for('default')->write(new Receipt(
        runId: 'r-sole', state: 'complete', allVerified: true, tree: 'tree-a',
        stale: null, verdicts: ['a' => 'passed'], recordedAt: 'now',
        coverage: 'complete', asserted: ['a'],
    ));

    expect(resolve(ReceiptStore::class)->read()?->runId)->toBe('r-sole');
});

it('keeps a custom receipt store for a project with one pipeline', function (): void {
    // Nothing is ambiguous with one pipeline, so an override that worked before
    // names existed keeps working.
    app()->instance(Pipelines::class, Pipelines::single(Pipeline::configure()));

    $mine = new class implements ReceiptStore
    {
        public function write(Receipt $receipt): void {}

        public function read(): ?Receipt
        {
            return null;
        }
    };

    app()->instance(ReceiptStore::class, $mine);

    expect(resolve(ReceiptStoreFactory::class)->for('default'))->toBe($mine);
});

it('ignores a custom receipt store once a project declares several', function (): void {
    // One store cannot serve several pipelines without collapsing every receipt
    // into one, which is the problem named pipelines exist to solve. Such a
    // project binds ReceiptStoreFactory instead, and UPGRADING says so.
    $mine = new class implements ReceiptStore
    {
        public function write(Receipt $receipt): void {}

        public function read(): ?Receipt
        {
            return null;
        }
    };

    app()->instance(ReceiptStore::class, $mine);

    expect(resolve(ReceiptStoreFactory::class)->for('pr'))
        ->toBeInstanceOf(JsonReceiptStore::class)->not->toBe($mine);
});
