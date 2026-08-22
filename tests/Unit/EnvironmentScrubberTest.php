<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/bp-env-'.bin2hex(random_bytes(4));
    mkdir($this->base);
});

afterEach(function (): void {
    if (is_file($this->base.'/.env')) {
        unlink($this->base.'/.env');
    }

    if (is_dir($this->base)) {
        rmdir($this->base);
    }
});

it('returns nothing to scrub when the app has no .env', function (): void {
    expect((new EnvironmentScrubber($this->base))->forStep())->toBe([]);
});

it('removes every key the app .env defines, so the child re-reads it', function (): void {
    file_put_contents($this->base.'/.env', <<<'ENV'
    # a comment
    APP_ENV=local
    DB_DATABASE=my_tp

    DB_HOST=127.0.0.1
    ENV);

    $env = (new EnvironmentScrubber($this->base))->forStep();

    expect($env)->toHaveKeys(['APP_ENV', 'DB_DATABASE', 'DB_HOST'])
        ->and($env['DB_DATABASE'])->toBeFalse()
        ->and($env)->not->toHaveKey('# a comment');
});

it('lets an override win, which is how a step pins its own database', function (): void {
    file_put_contents($this->base.'/.env', "DB_DATABASE=my_tp_shared\n");

    $env = (new EnvironmentScrubber($this->base))->forStep(['DB_DATABASE' => 'my_tp_phpunit_iso']);

    expect($env['DB_DATABASE'])->toBe('my_tp_phpunit_iso');
});

it('ignores malformed lines rather than producing bogus keys', function (): void {
    // A line of only '=' makes strtok return false rather than a token, which
    // trim() rejects outright under strict_types.
    file_put_contents($this->base.'/.env', "not a valid line\n1BAD=x\n=\n===\nGOOD=y\n");

    expect(array_keys((new EnvironmentScrubber($this->base))->forStep()))->toBe(['GOOD']);
});
