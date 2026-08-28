<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Run\ReceiptStoreFactory;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Runner\StepRunnerFactory;
use SanderMuller\BoostPipeline\Steps\Shell;

/**
 * A run per pipeline, each with its own cursor and its own receipt.
 *
 * The receipts are what make two answers true at once, which is the thing tags
 * could never do: one file meant a second run replaced the first.
 */
final class AlwaysPassing implements StepRunner
{
    public function run(Step $step, string $runId): Result
    {
        return Result::passed($step->id(), 'ok');
    }
}

final class InMemoryReceipts implements ReceiptStore
{
    public ?Receipt $receipt = null;

    public function write(Receipt $receipt): void
    {
        $this->receipt = $receipt;
    }

    public function read(): ?Receipt
    {
        return $this->receipt;
    }
}

final class MovableTree implements TreeFingerprint
{
    public function __construct(public string $digest = 'tree-a') {}

    public function capture(): string
    {
        return $this->digest;
    }
}

/** @param array<string, int> $stepCounts pipeline name => how many steps it declares */
function pipelinesOf(array $stepCounts): Pipelines
{
    $declared = [];

    foreach ($stepCounts as $name => $count) {
        $declared[$name] = Pipeline::configure()->withSteps(function (Steps $steps) use ($name, $count): void {
            for ($i = 1; $i <= $count; $i++) {
                $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: "{$name}-{$i}"));
            }
        });
    }

    return Pipelines::fromArray($declared, '.config/pipeline.php');
}

/** @param array<string, InMemoryReceipts> $stores */
function managerOver(Pipelines $pipelines, array &$stores = [], ?TreeFingerprint $tree = null): RunManager
{
    return new RunManager(
        $pipelines,
        new StepRunnerFactory(static fn (string $name): StepRunner => new AlwaysPassing),
        $tree,
        new ReceiptStoreFactory(function (string $name) use (&$stores): ReceiptStore {
            return $stores[$name] ??= new InMemoryReceipts;
        }),
    );
}

it('holds a separate run per pipeline, each with its own cursor', function (): void {
    $stores = [];
    $manager = managerOver(pipelinesOf(['pr' => 2, 'release' => 3]), $stores);

    $pr = $manager->open('pr');
    $pr->resolveCurrent();

    $release = $manager->open('release');

    expect($pr->id)->not->toBe($release->id)
        ->and($pr->position())->toBe('2/2')
        ->and($release->position())->toBe('1/3');
});

it('resumes a pipeline where it was left, after walking another', function (): void {
    // The whole reason for holding several: an agent blocked in one pipeline can
    // work in another without re-walking the first from the start.
    $stores = [];
    $manager = managerOver(pipelinesOf(['pr' => 3, 'release' => 2]), $stores);

    $manager->open('pr')->resolveCurrent();
    $manager->open('release')->resolveCurrent();

    expect($manager->open('pr')->position())->toBe('2/3')
        ->and($manager->for('pr')?->position())->toBe('2/3');
});

it('writes one receipt per pipeline, so two answers are true at once', function (): void {
    $stores = [];
    $manager = managerOver(pipelinesOf(['pr' => 1, 'release' => 1]), $stores);

    $manager->open('pr')->resolveCurrent();
    $manager->open('release')->resolveCurrent();

    expect($stores['pr']->receipt?->verdicts)->toBe(['pr-1' => 'passed'])
        ->and($stores['release']->receipt?->verdicts)->toBe(['release-1' => 'passed'])
        ->and($stores['pr']->receipt?->allVerified)->toBeTrue()
        ->and($stores['release']->receipt?->allVerified)->toBeTrue();
});

it('discards only the pipeline whose tree moved', function (): void {
    // A run measured before an edit cannot describe the tree after one, so it is
    // not resumed. That rule already existed; it now applies per entry rather
    // than to the single run.
    $stores = [];
    $tree = new MovableTree;
    $manager = managerOver(pipelinesOf(['pr' => 3, 'release' => 3]), $stores, $tree);

    $first = $manager->open('pr');
    $first->resolveCurrent();

    $release = $manager->open('release');
    $release->resolveCurrent();

    $tree->digest = 'tree-b';

    $reopened = $manager->open('pr');

    expect($reopened->id)->not->toBe($first->id)
        ->and($reopened->position())->toBe('1/3');
});

it('rebaselines a pipeline that had recorded nothing when the tree moved', function (): void {
    $stores = [];
    $tree = new MovableTree;
    $manager = managerOver(pipelinesOf(['pr' => 2]), $stores, $tree);

    $first = $manager->open('pr');

    $tree->digest = 'tree-b';

    expect($manager->open('pr')->id)->toBe($first->id);
});

it('replaces only its own entry when a scope changes', function (): void {
    $stores = [];
    $manager = managerOver(pipelinesOf(['pr' => 2, 'release' => 2]), $stores);

    $release = $manager->open('release');
    $first = $manager->open('pr');
    $rescoped = $manager->open('pr', 'backend');

    expect($rescoped->id)->not->toBe($first->id)
        ->and($manager->for('release')?->id)->toBe($release->id);
});

it('returns the same run for the same pipeline and scope on an unmoved tree', function (): void {
    $stores = [];
    $manager = managerOver(pipelinesOf(['pr' => 2]), $stores);

    expect($manager->open('pr')->id)->toBe($manager->open('pr')->id);
});

it('refuses to guess which pipeline was meant', function (): void {
    // Never the most recently opened. An agent that omitted the name and got
    // that would advance the wrong cursor and write into the wrong receipt,
    // silently.
    $stores = [];
    $manager = managerOver(pipelinesOf(['pr' => 1, 'release' => 1]), $stores);

    $manager->open();
})->throws(InvalidPipelineConfigException::class, 'so one has to be named');

it('names what is configured when asked for a pipeline that is not', function (): void {
    $stores = [];
    $manager = managerOver(pipelinesOf(['pr' => 1, 'release' => 1]), $stores);

    $manager->open('staging');
})->throws(InvalidPipelineConfigException::class, 'No pipeline named [staging] is configured');

it('needs no name when the config returned a bare Pipeline', function (): void {
    $stores = [];
    $manager = managerOver(Pipelines::single(Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'a'));
        $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'b'));
    })), $stores);

    expect($manager->open()->position())->toBe('1/2')
        ->and($manager->open()->pipeline)->toBeNull();
});

it('requires a name from a map even when the map holds one pipeline', function (): void {
    // The friction is the point: adding a second pipeline later must not break
    // the call sites that were already there.
    $stores = [];
    $manager = managerOver(pipelinesOf(['pr' => 1]), $stores);

    $manager->open();
})->throws(InvalidPipelineConfigException::class, 'names its pipelines');

it('labels a run with its pipeline whenever the config names them', function (): void {
    // The envelope reports it, and a payload that never had a name to report
    // should not grow one.
    $stores = [];

    expect(managerOver(pipelinesOf(['pr' => 1, 'release' => 1]), $stores)->open('pr')->pipeline)->toBe('pr');

    $one = [];
    expect(managerOver(pipelinesOf(['pr' => 1]), $one)->open('pr')->pipeline)->toBe('pr');

    $bare = [];
    expect(managerOver(Pipelines::single(Pipeline::configure()), $bare)->open()->pipeline)->toBeNull();
});

it('gives each pipeline a runner carrying its own timeout', function (): void {
    // One runner built at boot could only ever be right for one pipeline. The
    // factory is what lets the ceiling be read per pipeline instead.
    $pipelines = Pipelines::fromArray([
        'quick' => Pipeline::configure()->withTimeout(5.0),
        'slow' => Pipeline::configure()->withTimeout(600.0),
    ], '.config/pipeline.php');

    $given = [];

    $factory = new StepRunnerFactory(function (string $name) use ($pipelines, &$given): StepRunner {
        $given[$name] = $pipelines->get($name)?->timeoutSeconds();

        return new AlwaysPassing;
    });

    $factory->for('quick');
    $factory->for('slow');

    expect($given)->toBe(['quick' => 5.0, 'slow' => 600.0])
        // Same name, same instance: built once and reused.
        ->and($factory->for('quick'))->toBe($factory->for('quick'));
});

it('gives each pipeline its own receipt store, and reuses it', function (): void {
    $factory = new ReceiptStoreFactory(static fn (string $name): ReceiptStore => new InMemoryReceipts);

    expect($factory->for('pr'))->not->toBe($factory->for('release'))
        ->and($factory->for('pr'))->toBe($factory->for('pr'));
});

it('keeps two pipelines apart even when they declare the same step id', function (): void {
    $shared = function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'phpstan'));
    };

    $stores = [];
    $manager = managerOver(Pipelines::fromArray([
        'pr' => Pipeline::configure()->withSteps($shared),
        'release' => Pipeline::configure()->withSteps($shared),
    ], '.config/pipeline.php'), $stores);

    $manager->open('pr')->resolveCurrent();
    $manager->open('release')->resolveCurrent();

    expect($stores['pr']->receipt?->verdicts)->toBe(['phpstan' => 'passed'])
        ->and($stores['release']->receipt?->verdicts)->toBe(['phpstan' => 'passed'])
        ->and($stores['pr']->receipt?->runId)->not->toBe($stores['release']->receipt?->runId);
});

it('reopens a stale run rather than handing the same unverifiable one back', function (): void {
    // The recovery path after taking the wrong branch of the fix loop. Calling
    // next_step after an edit resolves the step against the new tree, which
    // becomes the last tree the run saw — so `treeHasMoved()` is false from then
    // on while the earlier passes stay pinned to the tree before the edit. The
    // run is stale forever, and without this the obvious recovery — notice the
    // stale note, call open_run — returns that same run and does nothing.
    $stores = [];
    $tree = new MovableTree;
    $manager = managerOver(pipelinesOf(['pr' => 2]), $stores, $tree);

    $first = $manager->open('pr');
    $first->resolveCurrent();

    // The fix an agent makes for the failing step.
    $tree->digest = 'tree-b';

    // Taking the wrong branch: next_step rather than open_run.
    $first->resolveCurrent();

    expect($first->staleReason())->not->toBeNull()
        ->and($first->treeHasMoved())->toBeFalse()
        ->and($manager->open('pr')->id)->not->toBe($first->id);
});

it('still hands back the same run when it is neither stale nor moved', function (): void {
    // The idempotency the reopen rule must not cost: a healthy run is returned as
    // it stands, so open_run remains safe to call at any point in a walk.
    $stores = [];
    $manager = managerOver(pipelinesOf(['pr' => 3]), $stores, new MovableTree);

    $run = $manager->open('pr');
    $run->resolveCurrent();

    expect($run->staleReason())->toBeNull()
        ->and($manager->open('pr')->id)->toBe($run->id);
});
