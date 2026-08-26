<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Runner\LogWriter;

beforeEach(function (): void {
    $this->dir = sys_get_temp_dir().'/bp-logwriter-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    if (! is_dir($this->dir)) {
        return;
    }

    $logs = glob($this->dir.'/*.log');

    foreach ($logs === false ? [] : $logs as $file) {
        unlink($file);
    }

    rmdir($this->dir);
});

it('names the log after the run and step, leaving ordinary ids untouched', function (): void {
    $path = new LogWriter($this->dir)->write('r-4f2a', 'pint', 'output');

    expect(basename((string) $path))->toBe('r-4f2a-pint.log');
});

it('keeps a step id that carries path separators inside the log directory', function (): void {
    // An explicit id from the pipeline config is not slugged the way a derived
    // one is, so it reaches the writer verbatim.
    $path = new LogWriter($this->dir)->write('r-4f2a', '../../escape', 'output');

    expect(realpath(dirname((string) $path)))->toBe(realpath($this->dir))
        ->and(basename((string) $path))->not->toContain('/')
        ->and(basename((string) $path))->toStartWith('r-4f2a-..-..-escape-');
});

it('keeps two ids that reduce to the same safe text in separate files', function (): void {
    $writer = new LogWriter($this->dir);

    // The walk enforces unique step ids on the raw values, so these two pass that
    // check and would collide if sanitising alone decided the filename.
    $slash = $writer->write('r-4f2a', 'lint/all', 'from slash');
    $space = $writer->write('r-4f2a', 'lint all', 'from space');

    expect($slash)->not->toBe($space)
        ->and(file_get_contents((string) $slash))->toBe('from slash')
        ->and(file_get_contents((string) $space))->toBe('from space');
});

it('returns null instead of throwing when the log directory exists but is unwritable', function (): void {
    mkdir($this->dir);
    chmod($this->dir, 0500);

    $path = new LogWriter($this->dir)->write('r-4f2a', 'pint', 'output');

    expect($path)->toBeNull();

    chmod($this->dir, 0700);
})->skip(fn (): bool => ! function_exists('posix_geteuid') || posix_geteuid() === 0, 'Root ignores directory modes.');
