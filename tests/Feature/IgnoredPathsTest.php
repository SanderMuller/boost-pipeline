<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Phases\Defaults\Tests;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\PipelineOverview;
use SanderMuller\BoostPipeline\Run\ReceiptStoreFactory;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Runner\GitTreeFingerprint;
use SanderMuller\BoostPipeline\Runner\StepRunnerFactory;
use SanderMuller\BoostPipeline\Steps\Shell;
use Symfony\Component\Process\Process;

/**
 * A run writes its receipt through `RunManager` and `pipeline:verify` reads it
 * through its own capture. Both have to leave out the same paths: a receipt
 * measured without them and compared to a capture taken with them would never
 * match, and every gate would fail with nothing wrong.
 */
function inRepo(string $repo, string ...$arguments): void
{
    new Process(['git', ...$arguments], $repo)->mustRun();
}

/** @param list<string> $ignoring */
function walkIgnoring(string $repo, array $ignoring): void
{
    $pipeline = Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(Tests::class)->append(Shell::run('vendor/bin/pest', id: 'tests'));
        })
        ->ignoring($ignoring);

    $pipelines = Pipelines::fromArray(['default' => $pipeline], '.config/pipeline.php');
    $store = new JsonReceiptStore($repo.'/.receipts/default.json');
    $tree = new GitTreeFingerprint($repo);

    app()->instance(Pipelines::class, $pipelines);
    app()->instance(TreeFingerprint::class, $tree);
    app()->instance(ReceiptStoreFactory::class, new ReceiptStoreFactory(static fn (): ReceiptStore => $store));

    $runs = new RunManager(
        $pipelines,
        new StepRunnerFactory(static fn (): StepRunner => new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        }),
        $tree,
        new ReceiptStoreFactory(static fn (): ReceiptStore => $store),
    );

    $runs->open('default')->resolveCurrent();
}

beforeEach(function (): void {
    $this->repo = sys_get_temp_dir().'/bp-ignored-'.bin2hex(random_bytes(4));
    mkdir($this->repo.'/specs', recursive: true);

    inRepo($this->repo, 'init', '--quiet');
    inRepo($this->repo, 'config', 'user.email', 'test@example.com');
    inRepo($this->repo, 'config', 'user.name', 'Test');

    file_put_contents($this->repo.'/.gitignore', ".receipts/\n");
    file_put_contents($this->repo.'/app.php', "<?php // one\n");
    file_put_contents($this->repo.'/specs/plan.md', "- [ ] first\n");
    inRepo($this->repo, 'add', '-A');
    inRepo($this->repo, 'commit', '--quiet', '-m', 'initial');
});

afterEach(function (): void {
    new Process(['rm', '-rf', $this->repo])->run();
});

it('keeps a run verified after an edit to an ignored path, and says what it left out', function (): void {
    walkIgnoring($this->repo, ['specs', '*.md']);

    file_put_contents($this->repo.'/specs/plan.md', "- [x] first\n");

    expect(Artisan::call('pipeline:verify'))->toBe(0)
        ->and(Artisan::output())->toContain('specs, *.md')->toContain('verified this tree');
});

it('still refuses after an edit to a path the pipeline did not ignore', function (): void {
    walkIgnoring($this->repo, ['specs']);

    file_put_contents($this->repo.'/app.php', "<?php // two\n");

    expect(Artisan::call('pipeline:verify'))->toBe(1)
        ->and(Artisan::output())->toContain('verified different code');
});

it('refuses the same spec edit when the pipeline ignores nothing', function (): void {
    walkIgnoring($this->repo, []);

    file_put_contents($this->repo.'/specs/plan.md', "- [x] first\n");

    expect(Artisan::call('pipeline:verify'))->toBe(1)
        ->and(Artisan::output())->not->toContain('leaves out paths');
});

it('lets a commit of the code through while an ignored file stays uncommitted', function (): void {
    file_put_contents($this->repo.'/app.php', "<?php // two\n");
    file_put_contents($this->repo.'/specs/plan.md', "- [x] first\n");

    walkIgnoring($this->repo, ['specs']);

    inRepo($this->repo, 'add', 'app.php');
    inRepo($this->repo, 'commit', '--quiet', '-m', 'code');

    expect(Artisan::call('pipeline:verify'))->toBe(0);
});

it('shows the run as matching the tree on the overview after an edit to an ignored path', function (): void {
    walkIgnoring($this->repo, ['specs']);

    file_put_contents($this->repo.'/specs/plan.md', "- [x] first\n");

    expect(data_get(resolve(PipelineOverview::class)->forPipeline('default'), 'current.tree_matches'))->toBeTrue();

    file_put_contents($this->repo.'/app.php', "<?php // two\n");

    expect(data_get(resolve(PipelineOverview::class)->forPipeline('default'), 'current.tree_matches'))->toBeFalse();
});

it('names no excluded paths when a custom fingerprint measured the tree', function (): void {
    walkIgnoring($this->repo, ['specs']);

    app()->instance(TreeFingerprint::class, new readonly class implements TreeFingerprint
    {
        public function capture(): ?string
        {
            return null;
        }
    });

    Artisan::call('pipeline:verify');

    expect(Artisan::output())->not->toContain('leaves out paths');
});
