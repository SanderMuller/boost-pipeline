<?php

declare(strict_types=1);

use Pest\Rector\Set\PestSetList;
use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Carbon\Rector\FuncCall\DateFuncCallToCarbonRector;
use Rector\Carbon\Rector\FuncCall\TimeFuncCallToCarbonRector;
use Rector\Carbon\Rector\New_\DateTimeInstanceToCarbonRector;
use Rector\CodeQuality\Rector\ClassMethod\InlineArrayReturnAssignRector;
use Rector\CodingStyle\Rector\PostInc\PostIncDecToPreIncDecRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessParamTagRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUselessReturnTagRector;
use Rector\Privatization\Rector\ClassMethod\PrivatizeFinalClassMethodRector;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use RectorLaravel\Rector\ArrayDimFetch\ServerVariableToRequestFacadeRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withCache(
        cacheDirectory: './.cache/rector',
        cacheClass: FileCacheStorage::class,
    )
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/workbench',
        __DIR__.'/.config',
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        typeDeclarationDocblocks: true,
        privatization: true,
        instanceOf: true,
        earlyReturn: true,
        carbon: true,
        rectorPreset: true,
        phpunitCodeQuality: true,
    )
    ->withAttributesSets()
    ->withImportNames()
    ->withFluentCallNewLine()
    ->withParallel(300, 15, 15)
    ->withMemoryLimit('3G')
    ->withPhpSets(php84: true)
    ->withSets(array_merge(
        [
            LaravelSetList::LARAVEL_CODE_QUALITY,
            LaravelSetList::LARAVEL_ARRAYACCESS_TO_METHOD_CALL,
            LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
            LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        ],
        class_exists(PestSetList::class) ? [
            PestSetList::CODING_STYLE,
        ] : [],
    ))
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
        AddArrowFunctionReturnTypeRector::class,
        InlineArrayReturnAssignRector::class,
        PrivatizeFinalClassMethodRector::class,
        RemoveUselessParamTagRector::class,
        RemoveUselessReturnTagRector::class,
        PostIncDecToPreIncDecRector::class,
    ]);
