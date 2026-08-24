<?php

declare(strict_types=1);

use Laravel\Mcp\Server\Registrar;
use SanderMuller\BoostPipeline\BoostPipelineServiceProvider;
use SanderMuller\BoostPipeline\Config\ConfigError;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\ServerProcess;
use SanderMuller\BoostPipeline\Mcp\InvalidConfigServer;
use SanderMuller\BoostPipeline\Mcp\Tools\ExplainInvalidConfig;

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

    $registrar = new Registrar;
    app()->instance(Registrar::class, $registrar);

    // Stands in for `argv[1] === 'mcp:start'`, so the suite never touches that
    // global — the output formatter reads and rewrites it.
    app()->instance(ServerProcess::class, new class implements ServerProcess
    {
        public function isStarting(): bool
        {
            return true;
        }
    });

    try {
        new BoostPipelineServiceProvider(app())->boot();
    } finally {
        @unlink($path);
    }

    return $registrar;
}

it('registers the server when the config is valid', function (): void {
    $registrar = bootWithConfig('<?php return '.Pipeline::class.'::configure();');

    // ConfigError is bound only on the degraded path, so its absence is what
    // says the real server was registered.
    expect($registrar->servers())->toHaveKey('pipeline')
        ->and(app()->bound(ConfigError::class))->toBeFalse();
});

it('registers a degraded server when the config does not return a pipeline', function (): void {
    // Registering nothing left mcp:start writing "server not found" to stdout —
    // the protocol channel — which is unparseable, reads as a registration
    // mistake, and looks the same as a project that never opted in.
    $registrar = bootWithConfig('<?php return "not a pipeline";');

    expect($registrar->servers())->toHaveKey('pipeline')
        ->and(app()->bound(ConfigError::class))->toBeTrue()
        ->and(resolve(ConfigError::class)->message)->toContain('must return a Pipeline instance');
});

it('registers a degraded server when the config throws while it is being built', function (): void {
    $registrar = bootWithConfig('<?php return '.Pipeline::class.'::configure()->withTimeout(0);');

    expect($registrar->servers())->toHaveKey('pipeline')
        ->and(resolve(ConfigError::class)->message)->toContain('must be greater than zero');
});

it('lets a defect that is not a config error fail loudly', function (): void {
    // A syntax error, a TypeError, a missing class: those are bugs in the
    // consumer's own code. Catching them here would hide a real defect behind a
    // tidy message about configuration.
    expect(fn (): Registrar => bootWithConfig('<?php throw new RuntimeException("something else entirely");'))
        ->toThrow(RuntimeException::class, 'something else entirely');
});

it('hands the agent the reason through the protocol, not just the log', function (): void {
    // The operator may never see stderr. The agent is the party that can act, so
    // the reason has to arrive as a tool error on the call the instructions tell
    // it to make first.
    bootWithConfig('<?php return '.Pipeline::class.'::configure()->withTimeout(0);');

    InvalidConfigServer::tool(ExplainInvalidConfig::class)
        ->assertHasErrors()
        ->assertSee('must be greater than zero');
});

it('fails at server start when any pipeline in a map is broken', function (): void {
    // A session that only ever opens `pr` would otherwise never learn that
    // `release` cannot walk at all. Validation covers every pipeline the file
    // declares, not the one a session happens to reach for.
    $registrar = bootWithConfig(<<<'PHP'
        <?php

        use SanderMuller\BoostPipeline\Config\Pipeline;
        use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
        use SanderMuller\BoostPipeline\Phases\Steps;
        use SanderMuller\BoostPipeline\Steps\Shell;

        return [
            'pr' => Pipeline::configure(),
            'release' => Pipeline::configure()->withSteps(function (Steps $steps): void {
                $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'audit'));
                $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'audit'));
            }),
        ];
        PHP);

    expect(app()->bound(ConfigError::class))->toBeTrue()
        ->and(app()->make(ConfigError::class)->message)->toContain('Duplicate step id [audit]');
});

it('fails at server start on a duplicate step id in a single pipeline too', function (): void {
    // This used to surface at open_run, because nothing built a walk at boot.
    // Building them closes it for every project, not only multi-pipeline ones.
    bootWithConfig(<<<'PHP'
        <?php

        use SanderMuller\BoostPipeline\Config\Pipeline;
        use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
        use SanderMuller\BoostPipeline\Phases\Steps;
        use SanderMuller\BoostPipeline\Steps\Shell;

        return Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'phpstan'));
            $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'phpstan'));
        });
        PHP);

    expect(app()->make(ConfigError::class)->message)->toContain('Duplicate step id [phpstan]');
});

it('names the pipeline key when a map value is not a Pipeline', function (): void {
    bootWithConfig('<?php return ["pr" => '.Pipeline::class.'::configure(), "release" => "nope"];');

    expect(app()->make(ConfigError::class)->message)->toContain('declares pipeline [release] as string');
});
