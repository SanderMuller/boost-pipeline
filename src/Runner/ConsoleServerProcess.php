<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use Illuminate\Contracts\Foundation\Application;
use SanderMuller\BoostPipeline\Contracts\ServerProcess;

final readonly class ConsoleServerProcess implements ServerProcess
{
    public function __construct(private Application $app) {}

    public function isStarting(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? null;

        return is_array($argv)
            && ($argv[1] ?? null) === 'mcp:start';
    }
}
