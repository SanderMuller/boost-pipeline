<?php

declare(strict_types=1);

use Composer\InstalledVersions;
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
