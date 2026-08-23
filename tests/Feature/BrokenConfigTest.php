<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Registrar;
use RuntimeException;
use SanderMuller\BoostPipeline\BoostPipelineServiceProvider;
use SanderMuller\BoostPipeline\Config\Pipeline;

/**
 * For a stdio MCP server STDOUT is the protocol channel, so an unhandled config
 * error means the framework's exception renderer prints a boxed trace onto it —
 * malformed frames the client has to guess at. Whether the operator then sees the
 * real cause depends on their client, which is the wrong place for that to be
 * decided.
 */
function bootWithConfig(string $php): Registrar
{
    $path = app()->basePath('.config/pipeline.php');

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), recursive: true);
    }

    file_put_contents($path, $php);

    // argv is what tells the provider this process is the server; nothing else
    // pays for the eager check.
    $original = $_SERVER['argv'];
    $_SERVER['argv'] = ['artisan', 'mcp:start', 'pipeline'];

    $registrar = new Registrar;
    app()->instance(Registrar::class, $registrar);

    try {
        new BoostPipelineServiceProvider(app())->boot();
    } finally {
        $_SERVER['argv'] = $original;
        @unlink($path);
    }

    return $registrar;
}

it('registers the server when the config is valid', function (): void {
    $registrar = bootWithConfig('<?php return '.Pipeline::class.'::configure();');

    expect($registrar->servers())->toHaveKey('pipeline');
});

it('registers nothing when the config does not return a pipeline', function (): void {
    // Registering anyway would expose a tool surface with no pipeline behind it,
    // and the failure would surface at call time instead of at startup.
    $registrar = bootWithConfig('<?php return "not a pipeline";');

    expect($registrar->servers())->not->toHaveKey('pipeline');
});

it('registers nothing when the config throws while it is being built', function (): void {
    $registrar = bootWithConfig('<?php return '.Pipeline::class.'::configure()->withTimeout(0);');

    expect($registrar->servers())->not->toHaveKey('pipeline');
});

it('lets a defect that is not a config error fail loudly', function (): void {
    // A syntax error, a TypeError, a missing class: those are bugs in the
    // consumer's own code. Catching them here would hide a real defect behind a
    // tidy message about configuration.
    expect(fn (): Registrar => bootWithConfig('<?php throw new RuntimeException("something else entirely");'))
        ->toThrow(RuntimeException::class, 'something else entirely');
});
