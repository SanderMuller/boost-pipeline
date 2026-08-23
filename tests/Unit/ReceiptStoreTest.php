<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\Receipt;

beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/bp-receipt-'.bin2hex(random_bytes(4)).'/receipt.json';
    $this->store = new JsonReceiptStore($this->path);
});

afterEach(function (): void {
    if (is_file($this->path)) {
        unlink($this->path);
        rmdir(dirname($this->path));
    }
});

it('reads back what it wrote', function (): void {
    $this->store->write(new Receipt(
        runId: 'r-4f2a',
        state: 'complete',
        allVerified: true,
        tree: 'tree-a',
        stale: null,
        verdicts: ['pint' => 'passed'],
        recordedAt: '2026-01-01T00:00:00+00:00',
    ));

    $receipt = $this->store->read();

    expect($receipt?->runId)->toBe('r-4f2a')
        ->and($receipt?->allVerified)->toBeTrue()
        ->and($receipt?->tree)->toBe('tree-a')
        ->and($receipt?->verdicts)->toBe(['pint' => 'passed']);
});

it('reports no receipt rather than an error when none was written', function (): void {
    expect($this->store->read())->toBeNull();
});

it('treats an unreadable file as no receipt at all', function (): void {
    // "Is there a pass for this tree" answers no either way, and a consumer
    // acting on that is correct. Raising here would turn a gate into a crash.
    mkdir(dirname($this->path), recursive: true);
    file_put_contents($this->path, 'not json {');

    expect($this->store->read())->toBeNull();
});

it('treats JSON missing the fields that identify a run as no receipt', function (): void {
    mkdir(dirname($this->path), recursive: true);
    file_put_contents($this->path, '{"all_verified": true}');

    // Otherwise a file that happens to be JSON, with the one key a forger would
    // think to set, reads as a verified run.
    expect($this->store->read())->toBeNull();
});
