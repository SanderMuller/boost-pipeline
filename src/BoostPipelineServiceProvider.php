<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use SanderMuller\BoostPipeline\Config\ConfigError;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Console\VerifyCommand;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\ServerProcess;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Mcp\InvalidConfigServer;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
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

        $this->app->singleton(
            ReceiptStore::class,
            fn (): ReceiptStore => new JsonReceiptStore($this->app->storagePath('logs/pipeline/receipt.json')),
        );

        $this->app->singleton(RunManager::class, fn (): RunManager => new RunManager(
            $this->app->make(Pipeline::class),
            $this->app->make(StepRunner::class),
            $this->app->make(TreeFingerprint::class),
            $this->app->make(ReceiptStore::class),
        ));
    }

    public function boot(): void
    {
        // Registered whether or not the project opted in: a gate calling it on a
        // project with no pipeline should get a clear "nothing has been verified",
        // not "command not found".
        if ($this->app->runningInConsole()) {
            $this->commands([VerifyCommand::class]);
        }

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
        if ($this->app->make(ServerProcess::class)->isStarting()) {
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
            $this->app->make(Pipeline::class);

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
