<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Run\Receipt;

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function receiptData(array $extra = []): array
{
    return ['run' => 'r-1', 'state' => 'complete', 'all_verified' => true, 'verdicts' => ['fmt' => 'passed'], ...$extra];
}

it('keeps the installed packages through a write and a read', function (): void {
    $installed = ['digest' => 'abc', 'dev' => false];

    $receipt = Receipt::fromArray(receiptData(['installed' => $installed]));

    expect($receipt?->installed)->toBe($installed)
        ->and($receipt?->toArray()['installed'] ?? null)->toBe($installed);
});

it('reads a receipt without installed packages as not recorded', function (): void {
    $receipt = Receipt::fromArray(receiptData());

    expect($receipt)->not->toBeNull()
        ->and($receipt?->installed)->toBeNull()
        ->and($receipt?->toArray())->not->toHaveKey('installed');
});

it('rejects a receipt whose installed packages are malformed, rather than skipping the check', function (mixed $installed): void {
    expect(Receipt::fromArray(receiptData(['installed' => $installed])))->toBeNull();
})->with([
    'a string' => 'abc',
    'no digest' => [['dev' => true]],
    'dev not a bool' => [['digest' => 'abc', 'dev' => 'yes']],
]);
