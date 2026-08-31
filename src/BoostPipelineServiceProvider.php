<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use SanderMuller\BoostPipeline\Config\ConfigError;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Console\HistoryCommand;
use SanderMuller\BoostPipeline\Console\VerifyCommand;
use SanderMuller\BoostPipeline\Contracts\LiveProgressStore;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\RunHistoryStore;
use SanderMuller\BoostPipeline\Contracts\ServerProcess;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Http\LoopbackOnly;
use SanderMuller\BoostPipeline\Http\PipelineController;
use SanderMuller\BoostPipeline\Mcp\InvalidConfigServer;
use SanderMuller\BoostPipeline\Mcp\McpSurface;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Run\JsonLiveProgressStore;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\JsonRunHistoryStore;
use SanderMuller\BoostPipeline\Run\LiveProgressStoreFactory;
use SanderMuller\BoostPipeline\Run\PipelineOverview;
use SanderMuller\BoostPipeline\Run\ReceiptStoreFactory;
use SanderMuller\BoostPipeline\Run\RunHistoryStoreFactory;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Run\StepLogReader;
use SanderMuller\BoostPipeline\Runner\CommandPreflight;
use SanderMuller\BoostPipeline\Runner\ConsoleServerProcess;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
use SanderMuller\BoostPipeline\Runner\GitTreeFingerprint;
use SanderMuller\BoostPipeline\Runner\LogWriter;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Runner\StepRunnerFactory;

final class BoostPipelineServiceProvider extends ServiceProvider
{
    public const string HANDLE = 'pipeline';

    public const string CONFIG = 'boost-pipeline';

    /**
     * The middleware the page gets when the config does not say.
     *
     * A fallback rather than a duplicate of the shipped config: these routes serve
     * raw command output, and the loopback gate is the only one of the three that
     * answers who is asking. A partial published config, or a value of the wrong
     * type, must not silently leave them open — so an absent or unusable list
     * takes this, and only a list the consumer actually wrote replaces it.
     *
     * @var list<string>
     */
    public const array DEFAULT_UI_MIDDLEWARE = ['web', LoopbackOnly::class];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/boost-pipeline.php', self::CONFIG);

        $this->app->singleton(PipelineLoader::class, fn (): PipelineLoader => new PipelineLoader($this->app->basePath()));

        $this->app->singleton(Pipelines::class, function (): Pipelines {
            $pipelines = $this->app->make(PipelineLoader::class)->load();

            // Registration is gated on the same check, so an opted-out project
            // never reaches this fallback.
            return $pipelines ?? Pipelines::single(Pipeline::configure());
        });

        // The documented seam: bind your own over this one and every step goes
        // through it. Not routed through the factory, which would be a cycle.
        $this->app->singleton(
            StepRunner::class,
            fn (): StepRunner => $this->processRunner($this->app->make(Pipelines::class)->soleName()),
        );

        $this->app->singleton(StepRunnerFactory::class, fn (): StepRunnerFactory => new StepRunnerFactory(
            function (string $pipeline): StepRunner {
                $bound = $this->app->make(StepRunner::class);

                // A consumer that bound its own runner gets it for every
                // pipeline. Only the shipped runner varies per pipeline, and only
                // because the timeout does — reading that once at boot could be
                // right for one pipeline at most.
                return $bound instanceof ProcessStepRunner ? $this->processRunner($pipeline) : $bound;
            },
        ));

        $this->app->singleton(
            ServerProcess::class,
            fn (): ServerProcess => new ConsoleServerProcess($this->app),
        );

        $this->app->singleton(
            CommandPreflight::class,
            fn (): CommandPreflight => new CommandPreflight($this->app->basePath()),
        );

        $this->app->singleton(
            TreeFingerprint::class,
            fn (): TreeFingerprint => new GitTreeFingerprint($this->app->basePath()),
        );

        $this->app->singleton(
            ReceiptStore::class,
            fn (): ReceiptStore => $this->jsonReceiptStore($this->soleName()),
        );

        $this->app->singleton(ReceiptStoreFactory::class, fn (): ReceiptStoreFactory => new ReceiptStoreFactory(
            function (string $pipeline): ReceiptStore {
                $pipelines = $this->app->make(Pipelines::class);

                // The name becomes a path component. The loader validates every
                // name it accepts, but this factory is public API and could be
                // called with anything — `../../secrets` would resolve outside
                // the receipts directory, and `JsonReceiptStore` creates parent
                // directories on write. Only a declared name is a real pipeline,
                // so that is the check.
                if (! $pipelines->has($pipeline)) {
                    throw InvalidPipelineConfigException::unknownPipeline($pipeline, $pipelines->names());
                }

                // With one pipeline there is nothing ambiguous about a consumer's
                // own store, so it keeps working. With several, one store cannot
                // serve them all without collapsing every receipt into one, which
                // is the problem named pipelines exist to solve — such a project
                // binds `ReceiptStoreFactory` instead. UPGRADING says so.
                if ($pipelines->soleName() !== null) {
                    $bound = $this->app->make(ReceiptStore::class);

                    if ($bound::class !== JsonReceiptStore::class) {
                        return $bound;
                    }
                }

                return $this->jsonReceiptStore($pipeline);
            },
        ));

        // Ungated on purpose: every run records history and in-flight state
        // whether or not a consumer ever serves the page. Enabling the page then
        // shows real history at once rather than an empty list.
        $this->app->singleton(RunHistoryStoreFactory::class, fn (): RunHistoryStoreFactory => new RunHistoryStoreFactory(
            fn (string $pipeline): RunHistoryStore => new JsonRunHistoryStore(
                $this->app->storagePath("logs/pipeline/history/{$this->declaredName($pipeline)}"),
            ),
        ));

        $this->app->singleton(LiveProgressStoreFactory::class, fn (): LiveProgressStoreFactory => new LiveProgressStoreFactory(
            fn (string $pipeline): LiveProgressStore => new JsonLiveProgressStore(
                $this->app->storagePath("logs/pipeline/live/{$this->declaredName($pipeline)}.json"),
            ),
        ));

        // Bound whatever the UI is set to. Only route registration is gated: a
        // console command reads the same projection, and it has to work with the
        // page disabled and outside a local environment.
        $this->app->singleton(PipelineOverview::class, fn (): PipelineOverview => new PipelineOverview(
            $this->app->make(Pipelines::class),
            $this->app->make(ReceiptStoreFactory::class),
            $this->app->make(RunHistoryStoreFactory::class),
            $this->app->make(LiveProgressStoreFactory::class),
            $this->app->make(TreeFingerprint::class),
        ));

        $this->app->singleton(StepLogReader::class, fn (): StepLogReader => new StepLogReader(
            $this->app->make(RunHistoryStoreFactory::class),
            new OutputSummariser,
            $this->app->storagePath('logs/pipeline'),
        ));

        $this->registerSolePipelineAliases();

        $this->app->singleton(RunManager::class, fn (): RunManager => new RunManager(
            $this->app->make(Pipelines::class),
            $this->app->make(StepRunnerFactory::class),
            $this->app->make(TreeFingerprint::class),
            $this->app->make(ReceiptStoreFactory::class),
            $this->app->make(RunHistoryStoreFactory::class),
            $this->app->make(LiveProgressStoreFactory::class),
        ));
    }

    /**
     * The binding that predates pipeline names.
     *
     * Resolves for the sole pipeline and refuses to pick one otherwise: "the
     * pipeline" has no answer in a project declaring three, and a silently chosen
     * one is a walk nobody asked about.
     *
     * `StepRunner` and `ReceiptStore` are bound separately, because a consumer
     * binds over both and the factories have to honour that.
     */
    private function registerSolePipelineAliases(): void
    {
        $this->app->singleton(
            Pipeline::class,
            fn (): Pipeline => $this->app->make(Pipelines::class)->sole(),
        );

    }

    /**
     * The shipped runner, carrying one pipeline's timeout.
     *
     * A null name means there is no single pipeline to read a ceiling from, so it
     * falls back to the package default rather than picking one.
     */
    private function processRunner(?string $pipeline): ProcessStepRunner
    {
        $declared = $pipeline === null
            ? null
            : $this->app->make(Pipelines::class)->get($pipeline)?->timeoutSeconds();

        return new ProcessStepRunner(
            workingDirectory: $this->app->basePath(),
            logs: new LogWriter($this->app->storagePath('logs/pipeline')),
            summariser: new OutputSummariser,
            environment: new EnvironmentScrubber($this->app->basePath()),
            timeoutSeconds: $declared ?? ProcessStepRunner::DEFAULT_TIMEOUT_SECONDS,
        );
    }

    /**
     * A pipeline name that is safe to use as a path component.
     *
     * Same check, and the same reason, as the receipt factory below: a name
     * reaches a directory these stores create, and only a declared name is a real
     * pipeline.
     *
     * @throws InvalidPipelineConfigException
     */
    private function declaredName(string $pipeline): string
    {
        $pipelines = $this->app->make(Pipelines::class);

        if (! $pipelines->has($pipeline)) {
            throw InvalidPipelineConfigException::unknownPipeline($pipeline, $pipelines->names());
        }

        return $pipeline;
    }

    private function jsonReceiptStore(string $pipeline): JsonReceiptStore
    {
        return new JsonReceiptStore($this->app->storagePath("logs/pipeline/receipts/{$pipeline}.json"));
    }

    /** @throws InvalidPipelineConfigException */
    private function soleName(): string
    {
        $pipelines = $this->app->make(Pipelines::class);

        return $pipelines->soleName()
            ?? throw InvalidPipelineConfigException::noSolePipeline($pipelines->names());
    }

    public function boot(): void
    {
        // Registered whether or not the project opted in: a gate calling it on a
        // project with no pipeline should get a clear "nothing has been verified",
        // not "command not found".
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyCommand::class, HistoryCommand::class]);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/boost-pipeline.php' => $this->app->configPath('boost-pipeline.php'),
            ], 'boost-pipeline-config');
        }

        // Registered in the package's own provider rather than a published
        // routes/ai.php, so a consuming app needs no extra file.
        if (! $this->app->make(PipelineLoader::class)->exists()) {
            return;
        }

        $this->registerUiRoutes();

        // Narrower than runningInConsole() on purpose: validating on every artisan
        // command would execute the consumer's config during unrelated work,
        // before provider boot has finished, and again in the child process
        // whenever an artisan command IS a pipeline step — `php artisan test` is
        // one. Only the server process has a protocol stream to protect.
        if ($this->app->make(ServerProcess::class)->isStarting()) {
            $missing = McpSurface::firstMissingProduction();

            if ($missing !== null) {
                // Cannot register even the invalid-config fallback: it needs the same
                // MCP surface. Stderr, never stdout — stdout is the JSON-RPC channel.
                $this->writeToStderr('[boost-pipeline] laravel/mcp is missing ['.$missing.']; the pipeline server was not registered. Check the installed laravel/mcp version.');

                return;
            }

            $reason = $this->configError();

            if ($reason !== null) {
                // Register something, rather than nothing. Declining left
                // `mcp:start` writing "server not found" to stdout — the JSON-RPC
                // channel for a stdio server — which is unparseable to a client,
                // reads as a registration mistake rather than a config error, and
                // is indistinguishable from a project that never opted in. One
                // driver hung waiting for the response that line was not.
                $this->app->instance(ConfigError::class, new ConfigError($reason));

                Mcp::local(self::HANDLE, InvalidConfigServer::class);

                return;
            }
        }

        Mcp::local(self::HANDLE, PipelineServer::class);
    }

    /**
     * The page, when a consumer asked for it and the environment allows it.
     *
     * Both conditions, never either: a flag committed by mistake must not open a
     * page that serves raw command output in production. Neither is access
     * control — `LoopbackOnly` in the default middleware answers who is asking.
     */
    private function registerUiRoutes(): void
    {
        $config = $this->app->make(Repository::class);

        if ($config->get(self::CONFIG.'.ui.enabled') !== true || ! $this->app->environment('local')) {
            return;
        }

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'boost-pipeline');

        // Published config is consumer-owned, so neither value is assumed to be
        // the shape this package shipped.
        $configuredPath = $config->get(self::CONFIG.'.ui.path');
        $path = is_string($configuredPath) && trim($configuredPath, '/') !== ''
            ? trim($configuredPath, '/')
            : 'boost-pipelines';

        $middleware = $config->get(self::CONFIG.'.ui.middleware');

        Route::middleware(is_array($middleware) && $middleware !== [] ? $middleware : self::DEFAULT_UI_MIDDLEWARE)
            ->prefix($path)
            ->group(function (): void {
                Route::get('/', [PipelineController::class, 'page'])->name('boost-pipeline.page');
                Route::get('/data', [PipelineController::class, 'data'])->name('boost-pipeline.data');

                // The route that serves untrusted output, so it takes the same
                // gates and the same middleware as the two above.
                Route::get('/log/{pipeline}/{run}/{step}', [PipelineController::class, 'log'])
                    ->name('boost-pipeline.log');
            });
    }

    /**
     * Load the config now and return why it failed, or null when it loaded.
     *
     * The message also goes to stderr, for the operator: stdout is the protocol
     * channel, and the agent gets the same reason through the degraded server.
     *
     * Only this package's own validation errors are caught. A syntax error, a
     * TypeError, a missing class: those are defects in the consumer's code, and
     * swallowing them here would hide a real bug behind a tidy message.
     */
    private function configError(): ?string
    {
        try {
            // `Pipelines`, never `Pipeline`: the latter now throws whenever the
            // project declares several, which would turn every multi-pipeline
            // project into a config error reported by the one path whose job is
            // to report config errors.
            $pipelines = $this->app->make(Pipelines::class);

            // Loading runs every pipeline's config closure, so a bad phase
            // anchor, an empty tag or a parallel-group violation already threw
            // above. A duplicate step id does not: it throws from `Walk::resolve`,
            // which nothing else calls at start, so today it surfaces at open_run
            // instead. Building the walks here closes that — and closes it for
            // every pipeline, not just whichever one a session happens to open.
            foreach ($pipelines->names() as $name) {
                $pipelines->get($name)?->walk();
            }

            return null;
        } catch (InvalidPipelineConfigException $invalidPipelineConfigException) {
            $this->writeToStderr('[boost-pipeline] '.$invalidPipelineConfigException->getMessage());

            return $invalidPipelineConfigException->getMessage();
        }
    }

    private function writeToStderr(string $message): void
    {
        // STDERR is absent outside the CLI SAPI, and `runningInConsole()` can be
        // true without it (APP_RUNNING_IN_CONSOLE), so the fallback open is
        // silenced rather than allowed to warn onto the stream it is protecting.
        $stream = defined('STDERR') ? STDERR : @fopen('php://stderr', 'w');

        if (! is_resource($stream)) {
            return;
        }

        @fwrite($stream, $message.PHP_EOL);

        if (! defined('STDERR')) {
            fclose($stream);
        }
    }
}
