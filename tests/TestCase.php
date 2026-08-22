<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use SanderMuller\BoostPipeline\BoostPipelineServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [BoostPipelineServiceProvider::class];
    }
}
