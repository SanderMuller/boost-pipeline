<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Tests;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use SanderMuller\BoostPipeline\BoostPipelineServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BoostPipelineServiceProvider::class];
    }
}
