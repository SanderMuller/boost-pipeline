<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
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

/**
 * An application that is serving HTTP rather than running a command.
 *
 * Testbench's app always reports running-in-console, so no case above can reach
 * the console guard — they all prove the argv guard while leaving the one that
 * fixes a web request unproven.
 */
final class WebApplication extends Application
{
    public function runningInConsole(): bool
    {
        return false;
    }
}

/**
 * @param  list<string>|null  $argv  null unsets the global entirely
 * @param  Closure(): void  $assert
 */
function withArgv(?array $argv, Closure $assert): void
{
    $had = array_key_exists('argv', $_SERVER);
    $original = $_SERVER['argv'] ?? null;

    if ($argv === null) {
        unset($_SERVER['argv']);
    } else {
        $_SERVER['argv'] = $argv;
    }

    try {
        $assert();
    } finally {
        if ($had) {
            $_SERVER['argv'] = $original;
        } else {
            unset($_SERVER['argv']);
        }
    }
}

it('stays inert in a web request, even with argv saying mcp:start', function (): void {
    // Deliberately the value that WOULD start the server in a console app, so a
    // pass here cannot come from the argv guard standing in for the console one.
    withArgv(['artisan', 'mcp:start'], function (): void {
        expect(new ConsoleServerProcess(new WebApplication)->isStarting())->toBeFalse();
    });
});

it('stays inert in a web request when argv is absent too', function (): void {
    // Both guards facing the same call: the reported production shape, a
    // dev-dependency install serving HTTP under an ini with register_argc_argv
    // off. Neither guard is asked to carry it alone.
    withArgv(null, function (): void {
        expect(new ConsoleServerProcess(new WebApplication)->isStarting())->toBeFalse();
    });
});
