<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Mcp\McpSurface;

it('finds nothing missing when given no symbols to check', function (): void {
    expect(McpSurface::firstMissing([], []))->toBeNull();
});

it('finds nothing missing for a real class and a real method on it', function (): void {
    // Not inlined: a literal 'firstMissing' here lets Rector's
    // ArrayToFirstClassCallableRector collapse the pair into a callable,
    // which is not the [class, method] shape firstMissing() takes.
    $realMethod = 'firstMissing';

    expect(McpSurface::firstMissing(
        [McpSurface::class],
        [[McpSurface::class, $realMethod]],
    ))->toBeNull();
});

it('returns the fake class name when a class does not exist', function (): void {
    expect(McpSurface::firstMissing(['Definitely\\Not\\A\\Real\\Class'], []))
        ->toBe('Definitely\\Not\\A\\Real\\Class');
});

it('returns Class::method when the class exists but the method does not', function (): void {
    expect(McpSurface::firstMissing([], [[McpSurface::class, 'noSuchMethod']]))
        ->toBe(McpSurface::class.'::noSuchMethod');
});

it('finds the production surface intact against the installed laravel/mcp', function (): void {
    // The living pin: this fails on the next laravel/mcp bump that moves a
    // symbol out from under the boot-time gate. That is the point.
    expect(McpSurface::firstMissingProduction())->toBeNull();
});
