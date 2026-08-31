<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

/*
|--------------------------------------------------------------------------
| LARAVEL_VERSION, before the Laravel extension can fail to define it
|--------------------------------------------------------------------------
|
| Larastan defines this constant in its own bootstrap file, from an
| application it boots there. Two things go wrong with that, and both have
| stopped analysis in this repository:
|
| 1. The boot is allowed to produce no application at all — the packages
|    branch is guarded on a trait existing, and nothing is thrown when no
|    branch matches (larastan/larastan#2077). The constant is then never
|    defined, silently.
| 2. `LarastanStubFilesExtension` reads it without a `defined()` guard, so
|    that silence surfaces as a fatal error thrown from stub collection
|    before any analysis (larastan/larastan#2480, root-caused in #2534).
|    The one-line guard was proposed in #2505 and closed unmerged, so the
|    crash is still present in 3.10.0.
|
| The version does not need an application. It is a constant on the
| framework's own Application class, which is what `$app->version()` returns,
| so reading it here removes the dependency on a boot succeeding. Larastan's
| bootstrap keeps its own `defined()` check, so this never fights it.
|
| Guarded on the class existing because this package requires `illuminate/*`
| rather than `laravel/framework`: the full framework arrives through
| Testbench, and an install without it should not fail here.
|
*/

if (! defined('LARAVEL_VERSION') && class_exists(Application::class)) {
    define('LARAVEL_VERSION', Application::VERSION);
}
