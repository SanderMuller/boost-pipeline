<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Runner\ConsoleServerProcess;

/**
 * `$_SERVER['argv']` is shared with the agent output formatter, which reads
 * and rewrites it — see the docblock on `ServerProcess`. Every case that
 * touches the global saves it first and restores it in `finally`, so this
 * suite never corrupts state unrelated to this question.
 */
it('does not start when argv is absent', function (): void {
    $hadArgv = array_key_exists('argv', $_SERVER);
    $original = $_SERVER['argv'] ?? null;
    unset($_SERVER['argv']);

    try {
        $process = new ConsoleServerProcess(app());

        expect($process->isStarting())->toBeFalse();
    } finally {
        if ($hadArgv) {
            $_SERVER['argv'] = $original;
        } else {
            unset($_SERVER['argv']);
        }
    }
});

it('starts when argv[1] is mcp:start in a console app', function (): void {
    $hadArgv = array_key_exists('argv', $_SERVER);
    $original = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['artisan', 'mcp:start'];

    try {
        $process = new ConsoleServerProcess(app());

        expect($process->isStarting())->toBeTrue();
    } finally {
        if ($hadArgv) {
            $_SERVER['argv'] = $original;
        } else {
            unset($_SERVER['argv']);
        }
    }
});

it('does not start for another artisan command', function (): void {
    $hadArgv = array_key_exists('argv', $_SERVER);
    $original = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = ['artisan', 'test'];

    try {
        $process = new ConsoleServerProcess(app());

        expect($process->isStarting())->toBeFalse();
    } finally {
        if ($hadArgv) {
            $_SERVER['argv'] = $original;
        } else {
            unset($_SERVER['argv']);
        }
    }
});

it('does not start when argv is present but not an array', function (): void {
    $hadArgv = array_key_exists('argv', $_SERVER);
    $original = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = 'not-an-array';

    try {
        $process = new ConsoleServerProcess(app());

        expect($process->isStarting())->toBeFalse();
    } finally {
        if ($hadArgv) {
            $_SERVER['argv'] = $original;
        } else {
            unset($_SERVER['argv']);
        }
    }
});
