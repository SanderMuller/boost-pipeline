<?php

declare(strict_types=1);

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
*/

pest()->tia()->locally();
