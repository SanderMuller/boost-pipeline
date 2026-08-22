<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline;

use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
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
            runId: 'r-'.substr(bin2hex(random_bytes(4)), 0, 6),
        ));

        $this->app->singleton(RunManager::class, fn (): RunManager => new RunManager(
            $this->app->make(Pipeline::class),
            $this->app->make(StepRunner::class),
        ));
    }

    public function boot(): void
    {
        // Registered in the package's own provider rather than a published
        // routes/ai.php, so a consuming app needs no extra file.
        if ($this->app->make(PipelineLoader::class)->exists()) {
            Mcp::local(self::HANDLE, PipelineServer::class);
        }
    }
}
