<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Run\ReceiptStoreFactory;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Runner\StepRunnerFactory;
use SanderMuller\BoostPipeline\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Tia Engine
|--------------------------------------------------------------------------
|
| Tia re-runs only the tests the last change touched. `locally()` disables it
| for any run with `--ci`, so CI keeps running the full suite — never pass
| `--tia` from a composer script or a workflow step. The first recording run
| needs PCOV or Xdebug; the graph lives in `~/.pest/tia/`, outside the repo.
|
| The version check is not dead code. The Laravel 12 cell in run-tests.yml runs
| `composer require --dev pestphp/pest:^4.6.3` before installing, so in that job
| Pest is 4.x and tia() does not exist — unguarded, this line raises "Call to
| undefined method UsesCall::tia()" and the cell fails before running a single
| test. Remove it only together with that downgrade step. It reads the installed
| version rather than calling method_exists() because PHPStan resolves the
| latter against Pest 5 and calls it always-true.
|
*/

if (version_compare((string) InstalledVersions::getVersion('pestphp/pest'), '5.0', '>=')) {
    pest()->tia()->locally();
}

/*
|--------------------------------------------------------------------------
| Run manager
|--------------------------------------------------------------------------
|
| `RunManager` holds a run per pipeline, so it takes a `Pipelines` map and a
| factory per dependency. Almost every test is about one pipeline, and wrapping
| that by hand at each call site would bury what the test is actually asserting.
| A test that IS about several pipelines builds the map itself.
|
*/

function runManagerFor(
    Pipeline $pipeline,
    StepRunner $runner,
    ?TreeFingerprint $tree = null,
    ?ReceiptStore $receipts = null,
): RunManager {
    return new RunManager(
        Pipelines::single($pipeline),
        new StepRunnerFactory(
            static fn (string $name): StepRunner => $runner,
        ),
        $tree,
        $receipts instanceof ReceiptStore
            ? new ReceiptStoreFactory(
                static fn (string $name): ReceiptStore => $receipts,
            )
            : null,
    );
}
