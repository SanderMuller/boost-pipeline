<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a
| specific PHPUnit test case class. By default, that class is
| `PHPUnit\Framework\TestCase`. For Laravel-aware packages, the bootstrap
| phase replaces this with `Orchestra\Testbench\TestCase` for Feature
| tests, or a project-specific test case.
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| Project-specific custom expectations go here. Pest's documentation has
| examples: https://pestphp.com/docs/custom-expectations
|
*/

// expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| Global helper functions used across multiple test files go here. Prefer
| Pest's higher-order test syntax over loose helpers where possible.
|
*/

// function something(): void { /* ... */ }

/*
|--------------------------------------------------------------------------
| Tia Engine
|--------------------------------------------------------------------------
|
| Tia (test impact analysis) re-runs only the tests that your last change
| touched. `locally()` switches Tia off for any run with `--ci`, so CI keeps
| the full suite. The first recording run needs a coverage driver (PCOV or
| Xdebug); the graph lives in `~/.pest/tia/`, outside the repository.
|
| Tia needs at least one commit in the repository. Before the initial commit,
| run `vendor/bin/pest --no-tia`.
|
*/

pest()->tia()->locally();
