<?php

declare(strict_types=1);

use Rector\Carbon\Rector\FuncCall\DateFuncCallToCarbonRector;
use Rector\Carbon\Rector\FuncCall\TimeFuncCallToCarbonRector;
use Rector\Carbon\Rector\New_\DateTimeInstanceToCarbonRector;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use SanderMuller\RectorPreset\Preset;

return Preset::package()
    // The preset scans the folders that exist. List them here to keep .config in and config/ out.
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/workbench',
        __DIR__.'/.config',
    ])
    ->withPhpSets(php84: true)
    ->withSkip([
        // `Request::server()` reads the captured request bag; `$_SERVER` is the
        // live superglobal. For `argv` — which only exists in console, where there
        // is no meaningful request — those are different values, and the rewrite
        // silently made a console check always false.
        ServerVariableToRequestFacadeRector::class,
        // `strtotime()` returns false for a value it cannot read; `Date::parse()`
        // throws. Both call sites read a timestamp out of a file on disk, where
        // truncated or hand-edited input is ordinary, and both deliberately
        // degrade rather than fail — the same posture the stores take for a file
        // they cannot parse. The rewrite turned that into an exception and left
        // the `=== false` guard as dead code.
        //
        // The New_ and facade rules go with them: rewriting the guard's input to
        // Carbon reintroduces the throw by another route, and a value object
        // reading a facade needs a container it should not need.
        DateFuncCallToCarbonRector::class,
        TimeFuncCallToCarbonRector::class,
        DateTimeInstanceToCarbonRector::class,
        CarbonToDateFacadeRector::class,
    ]);
