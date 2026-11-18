<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\BoostPipelineServiceProvider;
use SanderMuller\BoostPipeline\Contracts\ServerSupervisor;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Runner\EnvironmentSupervisor;

function requiresInstalledLock(RunManager $runs): bool
{
    return (bool) new ReflectionProperty(RunManager::class, 'requireInstalledLock')->getValue($runs);
}

it('requires a vendor/ that matches the lock by default', function (): void {
    expect(requiresInstalledLock(resolve(RunManager::class)))->toBeTrue();
});

it('reads the opt-out from the published config', function (): void {
    config()->set(BoostPipelineServiceProvider::CONFIG.'.open_run.require_installed_lock', false);

    expect(requiresInstalledLock(resolve(RunManager::class)))->toBeFalse();
});

it('binds the supervisor that reads the process environment', function (): void {
    expect(resolve(ServerSupervisor::class)::class)->toBe(EnvironmentSupervisor::class);
});
