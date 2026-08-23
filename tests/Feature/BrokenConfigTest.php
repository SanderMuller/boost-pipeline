<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\BoostPipelineServiceProvider;
use SanderMuller\BoostPipeline\Config\Pipeline;

/**
 * For a stdio MCP server STDOUT is the protocol channel, so an unhandled config
 * error means the framework's exception renderer prints a boxed trace onto it —
 * malformed frames the client has to guess at. Whether the operator sees the real
 * cause then depends on their client, which is the wrong place for that to be
 * decided.
 */
beforeEach(function (): void {
    $this->configPath = app()->basePath('.config/pipeline.php');

    if (! is_dir(dirname($this->configPath))) {
        mkdir(dirname($this->configPath), recursive: true);
    }
});

afterEach(function (): void {
    if (is_file($this->configPath)) {
        unlink($this->configPath);
    }
});

it('does not let a broken config reach the renderer', function (): void {
    file_put_contents($this->configPath, '<?php return "not a pipeline";');

    $boot = function (): void {
        new BoostPipelineServiceProvider(app())->boot();
    };

    expect($boot)->not->toThrow(Throwable::class);
});

it('does not let a config that throws while loading reach the renderer', function (): void {
    // A timeout of zero is rejected as the file executes, which is the shape of
    // error a first-time adopter is most likely to write.
    file_put_contents(
        $this->configPath,
        '<?php return '.Pipeline::class.'::configure()->withTimeout(0);',
    );

    $boot = function (): void {
        new BoostPipelineServiceProvider(app())->boot();
    };

    expect($boot)->not->toThrow(Throwable::class);
});

it('still registers the server when the config is valid', function (): void {
    file_put_contents(
        $this->configPath,
        '<?php return '.Pipeline::class.'::configure();',
    );

    $boot = function (): void {
        new BoostPipelineServiceProvider(app())->boot();
    };

    expect($boot)->not->toThrow(Throwable::class);
});
