<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Contracts\ServerProcess;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Runner\CommandPreflight;
use SanderMuller\BoostPipeline\Runner\ConsoleServerProcess;
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

        // Narrower than runningInConsole() on purpose: validating on every artisan
        // command would execute the consumer's config during unrelated work,
        // before provider boot has finished, and again in the child process
        // whenever an artisan command IS a pipeline step — `php artisan test` is
        // one. Only the server process has a protocol stream to protect.
        if ($this->app->make(ServerProcess::class)->isStarting() && ! $this->configLoads()) {
            return;
        }

        Mcp::local(self::HANDLE, PipelineServer::class);
    }

    /**
     * Load the config now and report a failure on stderr rather than stdout.
     *
     * For a stdio MCP server STDOUT *is* the protocol channel. Letting the
     * framework's exception renderer print a boxed trace there hands the client a
     * run of malformed frames after the handshake, and what the operator then
     * sees depends entirely on whether their client shows unparseable stdout or
     * discards it — one reports the real error, another just says the server
     * failed to start.
     *
     * Only this package's own validation errors are caught. A syntax error, a
     * TypeError, a missing class: those are defects in the consumer's code, and
     * swallowing them here would hide a real bug behind a tidy message.
     */
    private function configLoads(): bool
    {
        try {
            $this->app->make(Pipeline::class);

            return true;
        } catch (InvalidPipelineConfigException $invalidPipelineConfigException) {
            $this->writeToStderr('[boost-pipeline] '.$invalidPipelineConfigException->getMessage());

            return false;
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
