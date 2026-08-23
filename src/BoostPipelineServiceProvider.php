<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Runner\CommandPreflight;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
use SanderMuller\BoostPipeline\Runner\GitTreeFingerprint;
use SanderMuller\BoostPipeline\Runner\LogWriter;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;

final class BoostPipelineServiceProvider extends ServiceProvider
{
    public const string HANDLE = 'pipeline';

    public function register(): void
    {
        $this->app->singleton(PipelineLoader::class, fn (): PipelineLoader => new PipelineLoader($this->app->basePath()));

        $this->app->singleton(Pipeline::class, function (): Pipeline {
            $pipeline = $this->app->make(PipelineLoader::class)->load();

            // Registration is gated on the same check, so an opted-out project
            // never reaches this fallback.
            return $pipeline ?? Pipeline::configure();
        });

        $this->app->singleton(StepRunner::class, fn (): StepRunner => new ProcessStepRunner(
            workingDirectory: $this->app->basePath(),
            logs: new LogWriter($this->app->storagePath('logs/pipeline')),
            summariser: new OutputSummariser,
            environment: new EnvironmentScrubber($this->app->basePath()),
            timeoutSeconds: $this->app->make(Pipeline::class)->timeoutSeconds()
                ?? ProcessStepRunner::DEFAULT_TIMEOUT_SECONDS,
        ));

        $this->app->singleton(
            CommandPreflight::class,
            fn (): CommandPreflight => new CommandPreflight($this->app->basePath()),
        );

        $this->app->singleton(
            TreeFingerprint::class,
            fn (): TreeFingerprint => new GitTreeFingerprint($this->app->basePath()),
        );

        $this->app->singleton(RunManager::class, fn (): RunManager => new RunManager(
            $this->app->make(Pipeline::class),
            $this->app->make(StepRunner::class),
            $this->app->make(TreeFingerprint::class),
        ));
    }

    public function boot(): void
    {
        // Registered in the package's own provider rather than a published
        // routes/ai.php, so a consuming app needs no extra file.
        if (! $this->app->make(PipelineLoader::class)->exists()) {
            return;
        }

        if ($this->app->runningInConsole() && ! $this->configLoads()) {
            return;
        }

        Mcp::local(self::HANDLE, PipelineServer::class);
    }

    /**
     * Load the config now, on the console, and report a failure on stderr.
     *
     * For a stdio MCP server STDOUT *is* the protocol channel. Letting the
     * framework's exception renderer print a boxed trace there hands the client a
     * run of malformed frames after the handshake, and what the operator then
     * sees depends entirely on whether their client shows unparseable stdout or
     * discards it — one reports the real error, another just says the server
     * failed to start.
     *
     * Worth the eager load precisely because a bad config is the likeliest
     * failure a new adopter hits: `.config/pipeline.php` is the first file they
     * write. Resolving the singleton rather than calling the loader keeps it to
     * one execution, and web requests stay lazy.
     */
    private function configLoads(): bool
    {
        try {
            $this->app->make(Pipeline::class);

            return true;
        } catch (InvalidPipelineConfigException $invalidPipelineConfigException) {
            $stderr = fopen('php://stderr', 'w');

            if ($stderr !== false) {
                fwrite($stderr, '[boost-pipeline] '.$invalidPipelineConfigException->getMessage().PHP_EOL);
                fclose($stderr);
            }

            return false;
        }
    }
}
