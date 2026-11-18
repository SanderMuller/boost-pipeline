<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Runner\EnvironmentSupervisor;
use Symfony\Component\Process\Process;

/**
 * The message a server older than 0.24 gets: it calls vendorChanged() without arguments.
 *
 * @param  array<string, string|false>  $environment
 */
function messageForOldServer(array $environment): string
{
    $process = new Process(
        [PHP_BINARY, '-r', 'require $argv[1]; echo '.InvalidPipelineConfigException::class.'::vendorChanged()->getMessage();', dirname(__DIR__, 2).'/vendor/autoload.php'],
        env: $environment,
    );
    $process->mustRun();

    return $process->getOutput();
}

it('tells a supervised server too old to restart to reconnect once, not to start the supervisor', function (): void {
    $message = messageForOldServer([EnvironmentSupervisor::SUPERVISED => EnvironmentSupervisor::PROTOCOL]);

    expect($message)->toContain('runs under vendor/bin/boost-pipeline-mcp, but its version cannot restart itself')
        ->not->toContain('start the server through');
});

it('names the supervisor to an old server that runs without one', function (): void {
    expect(messageForOldServer([EnvironmentSupervisor::SUPERVISED => false]))
        ->toContain('start the server through vendor/bin/boost-pipeline-mcp');
});
