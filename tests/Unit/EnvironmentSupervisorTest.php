<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Runner\EnvironmentSupervisor;

it('is not supervised without the flag', function (): void {
    $supervisor = new EnvironmentSupervisor([]);

    expect($supervisor->isSupervised())->toBeFalse()
        ->and($supervisor->isRestart())->toBeFalse();
});

it('is supervised when the flag carries the protocol it speaks', function (): void {
    expect(new EnvironmentSupervisor([EnvironmentSupervisor::SUPERVISED => EnvironmentSupervisor::PROTOCOL])->isSupervised())->toBeTrue();
});

it('counts a protocol version it does not know as no supervisor, so it refuses instead of exiting', function (): void {
    $supervisor = new EnvironmentSupervisor([EnvironmentSupervisor::SUPERVISED => '2', EnvironmentSupervisor::RESTARTED => '1']);

    expect($supervisor->isSupervised())->toBeFalse()
        ->and($supervisor->isRestart())->toBeFalse();
});

it('knows when it replaces a server that asked for a restart', function (): void {
    expect(new EnvironmentSupervisor([EnvironmentSupervisor::SUPERVISED => EnvironmentSupervisor::PROTOCOL, EnvironmentSupervisor::RESTARTED => '1'])->isRestart())->toBeTrue();
});
