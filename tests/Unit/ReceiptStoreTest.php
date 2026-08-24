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

it('round-trips the scope, because a scoped pass must not read as a full one', function (): void {
    $this->store->write(new Receipt(
        runId: 'r-scoped',
        state: 'complete',
        allVerified: true,
        tree: 'tree-a',
        stale: null,
        verdicts: ['phpstan' => 'passed'],
        recordedAt: '2026-01-01T00:00:00+00:00',
        scope: 'backend',
    ));

    expect($this->store->read()?->scope)->toBe('backend');
});

it('reads a receipt written before scopes existed as unscoped', function (): void {
    // Absent means the run walked everything, which is what every receipt written
    // before this feature meant.
    mkdir(dirname($this->path), recursive: true);
    file_put_contents($this->path, json_encode([
        'run' => 'r-old',
        'state' => 'complete',
        'all_verified' => true,
        'tree' => 'tree-a',
        'verdicts' => ['pint' => 'passed'],
        'recorded_at' => '2026-01-01T00:00:00+00:00',
    ]));

    $receipt = $this->store->read();

    expect($receipt?->scope)->toBeNull()
        ->and($receipt?->allVerified)->toBeTrue();
});

it('round-trips coverage, the signal that tells a dropped gate from an acknowledgement', function (): void {
    $this->store->write(new Receipt(
        runId: 'r-cov', state: 'complete', allVerified: false, tree: 'tree-a',
        stale: null, verdicts: ['fmt' => 'passed'], recordedAt: '2026-01-01T00:00:00+00:00',
        coverage: 'incomplete',
    ));

    expect($this->store->read()?->coverage)->toBe('incomplete');
});

it('reads a receipt written before coverage existed as unknown, not clean', function (): void {
    mkdir(dirname($this->path), recursive: true);
    file_put_contents($this->path, json_encode([
        'run' => 'r-old', 'state' => 'complete', 'all_verified' => true,
        'tree' => 'tree-a', 'verdicts' => ['pint' => 'passed'],
        'recorded_at' => '2026-01-01T00:00:00+00:00',
    ]));

    expect($this->store->read()?->coverage)->toBeNull();
});

it('treats a malformed verdict map as no receipt rather than dropping entries', function (): void {
    // Dropping the bad entry would hand back a receipt holding only what survived,
    // and a predicate reading it would pass a run whose broken half went missing
    // on the way in.
    mkdir(dirname($this->path), recursive: true);
    file_put_contents($this->path, json_encode([
        'run' => 'r-bad', 'state' => 'complete', 'all_verified' => true,
        'tree' => 'tree-a', 'verdicts' => ['pint' => 'passed', 'phpstan' => ['not', 'a', 'verdict']],
        'recorded_at' => '2026-01-01T00:00:00+00:00',
    ]));

    expect($this->store->read())->toBeNull();
});

it('keeps a numeric step id through the round trip', function (): void {
    // A step id of "123" cannot even be written as a PHP array literal: the key
    // becomes an int. Nothing forbids that id, so the parser has to cast it back
    // rather than reject or drop it. Written as JSON for that reason.
    mkdir(dirname($this->path), recursive: true);
    file_put_contents($this->path, json_encode([
        'run' => 'r-numeric', 'state' => 'complete', 'all_verified' => true,
        'tree' => 'tree-a', 'verdicts' => ['123' => 'passed'],
        'recorded_at' => '2026-01-01T00:00:00+00:00', 'coverage' => 'complete',
    ]));

    expect($this->store->read()?->verdicts)->toBe(['123' => 'passed']);
});
