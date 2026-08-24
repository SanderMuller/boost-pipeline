<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Steps\Shell;

/**
 * The command is the only thing that lets a consumer act on a run, so its exit
 * code is the contract. Every case below is a reason a gate must NOT pass.
 */
function receiptStoreHolding(?Receipt $receipt): void
{
    app()->instance(ReceiptStore::class, new class($receipt) implements ReceiptStore
    {
        public function __construct(private ?Receipt $receipt) {}

        public function write(Receipt $receipt): void
        {
            $this->receipt = $receipt;
        }

        public function read(): ?Receipt
        {
            return $this->receipt;
        }
    });
}

function treeReporting(?string $digest): void
{
    app()->instance(TreeFingerprint::class, new readonly class($digest) implements TreeFingerprint
    {
        public function __construct(private ?string $digest) {}

        public function capture(): ?string
        {
            return $this->digest;
        }
    });
}

/** @param array<string, string>|null $verdicts */
function receipt(bool $allVerified = true, ?string $tree = 'tree-a', ?string $stale = null, string $state = 'complete', ?string $scope = null, ?array $verdicts = null, ?string $coverage = 'complete'): Receipt
{
    return new Receipt(
        runId: 'r-test',
        state: $state,
        allVerified: $allVerified,
        tree: $tree,
        stale: $stale,
        verdicts: $verdicts ?? ['pint' => 'passed', 'phpstan' => 'passed'],
        recordedAt: '2026-01-01T00:00:00+00:00',
        scope: $scope,
        coverage: $coverage,
    );
}

it('fails when no run has been recorded, which is the case it exists for', function (): void {
    // A gate that treats a missing answer as "nothing to check" passes exactly
    // the case it was added to catch: the run that never happened.
    receiptStoreHolding(null);
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(1)
        ->and(Artisan::output())->toContain('Nothing has been verified');
});

it('fails when the receipt describes a different tree', function (): void {
    receiptStoreHolding(receipt(tree: 'tree-a'));
    treeReporting('tree-b');

    expect(Artisan::call('pipeline:verify'))->toBe(1);
});

it('fails when the run recorded itself stale', function (): void {
    receiptStoreHolding(receipt(stale: 'Step [pint] measured a different working tree.'));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(1);
});

it('fails when the run has not verified every step', function (): void {
    // An acknowledged step reaches complete. Passing here would call agent
    // self-report a verified pass.
    receiptStoreHolding(receipt(allVerified: false));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(1);
});

it('says an acknowledged run is structural, not a shortfall to fix', function (): void {
    // The two reasons a run is unverified used to share one message. A failed
    // step is fixable; an acknowledged one is not, so a consumer reading the
    // generic wording wires up a gate that can never pass and learns to skip it.
    receiptStoreHolding(new Receipt(
        runId: 'r-ack',
        state: 'complete',
        allVerified: false,
        tree: 'tree-a',
        stale: null,
        verdicts: ['pint' => 'passed', 'review' => 'acknowledged', 'audit' => 'acknowledged'],
        recordedAt: '2026-01-01T00:00:00+00:00',
    ));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[review]')
        ->and($output)->toContain('cannot exit 0')
        // Two consumers reported the parenthetical landing between "were" and
        // "only acknowledged". It is the message a gate reader hits most often.
        // It must not tell them to move the steps out: for a sequencing pipeline
        // those steps are the point.
        ->and($output)->toContain('steps ([review], [audit]) were')
        ->and($output)->not->toContain('steps were ([')
        ->and($output)->toContain('expected for a pipeline that sequences agent work')
        ->and($output)->not->toContain('outside the pipeline');
});

it('still reports a plain failure as a failure, not as a design limit', function (): void {
    // A failing step alongside an acknowledged one must not be excused as
    // "structural" — re-running can fix this, and the message has to say so.
    receiptStoreHolding(new Receipt(
        runId: 'r-mixed',
        state: 'blocked',
        allVerified: false,
        tree: 'tree-a',
        stale: null,
        verdicts: ['pint' => 'failed', 'review' => 'acknowledged'],
        recordedAt: '2026-01-01T00:00:00+00:00',
    ));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('has not verified every step')
        ->and($output)->toContain('[pint] failed')
        // A blocked or halted run is retryable — next_step hands the same step
        // back — so calling it finished contradicts the server.
        ->and($output)->not->toContain('finished');
});

it('succeeds only when the recorded run verified this exact tree', function (): void {
    receiptStoreHolding(receipt());
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(0);
});

it('does not fail a run purely because the tree cannot be fingerprinted', function (): void {
    // No git, no digest. The receipt is all there is, and it says verified.
    receiptStoreHolding(receipt(tree: null));
    treeReporting(null);

    expect(Artisan::call('pipeline:verify'))->toBe(0);
});

it('exits 0 for a receipt a real run actually wrote', function (): void {
    // Every case above hands the command a receipt built by hand, so it asserts
    // what a CORRECT receipt does — never what `Run` produces. That is the seam
    // 0.4.0 shipped broken through: the command was right, `Run` wrote
    // `all_verified: false` for a green run, and both halves tested clean. This
    // is the only test that fails when the two disagree.
    $path = sys_get_temp_dir().'/bp-e2e-'.bin2hex(random_bytes(4)).'/receipt.json';
    $store = new JsonReceiptStore($path);

    app()->instance(ReceiptStore::class, $store);
    treeReporting('tree-a');

    $run = Run::start(
        Pipeline::configure()->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
        })->walk(),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-e2e',
        tree: resolve(TreeFingerprint::class),
        receipts: $store,
    );

    $run->resolveCurrent();

    try {
        expect(Artisan::call('pipeline:verify'))->toBe(0)
            ->and(Artisan::output())->toContain('verified this tree');
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
});

/**
 * Coverage, not equality. A run that walked every step answers a question about
 * any one scope; a scoped run answers only its own, and never "is this tree
 * verified?", which is what a bare call asks.
 */
it('answers a scope question from a full run, because a full run covered it', function (): void {
    // The case that reads backwards if you compare scopes for equality: a fully
    // verified tree would start failing subset queries.
    receiptStoreHolding(receipt());
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--only' => 'backend']))->toBe(0);
});

it('refuses to call the tree verified on the strength of a scoped run', function (): void {
    receiptStoreHolding(receipt(scope: 'backend'));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[backend]')
        ->and($output)->toContain('not this whole tree')
        ->and($output)->toContain('--only=backend');
});

it('answers a scoped question from the matching scoped run', function (): void {
    receiptStoreHolding(receipt(scope: 'backend'));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--only' => 'backend']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('scope [backend]');
});

it('refuses a scoped question the run did not answer', function (): void {
    receiptStoreHolding(receipt(scope: 'backend'));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--only' => 'frontend']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[backend]')
        ->and($output)->toContain('[frontend]');
});

it('treats a blank --only as no question about scope at all', function (): void {
    receiptStoreHolding(receipt(scope: 'backend'));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--only' => '  ']))->toBe(1);
});

it('checks the scope before the verdict, so the message names the real problem', function (): void {
    // A scoped run that also failed must not report "not verified" when the
    // caller's question was unanswerable to begin with.
    receiptStoreHolding(receipt(allVerified: false, scope: 'backend'));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(1)
        ->and(Artisan::output())->toContain('not this whole tree');
});

/**
 * `--server-run-only` asks a narrower question than the bare call: did every step
 * the server actually ran pass? It is the honest question for a run that
 * sequences agent work, where an acknowledged step keeps `all_verified` false
 * forever and the aggregate answer never changes.
 */
it('passes a run whose server-run steps all passed, even with an acknowledged step', function (): void {
    receiptStoreHolding(receipt(allVerified: false, verdicts: [
        'pint' => 'passed',
        'phpstan' => 'passed',
        'evaluate' => 'acknowledged',
    ]));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('passed all 2 step(s)')
        // The caller must not read the exit code as covering the whole run.
        ->and($output)->toContain('not a claim that the tree is verified');
});

it('refuses a run the server never ran a single step of', function (): void {
    // The guard the predicate cannot go without. "Every server-run step passed"
    // is vacuously true over an empty set, so a walk of nothing but
    // acknowledgements would exit 0 having verified nothing at all.
    receiptStoreHolding(receipt(allVerified: false, verdicts: [
        'evaluate' => 'acknowledged',
        'code-review' => 'acknowledged',
    ]));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('produced a verdict for none of them')
        ->and($output)->toContain('nothing here it verified');
});

it('names a non-passing server verdict when it reaches that branch', function (): void {
    // A live run cannot produce this: a verdict that is not a pass holds the
    // cursor, so the state would be blocked or halted and the guard above catches
    // it first. Built by hand, because the branch still has to be right for a
    // receipt that came from somewhere else.
    receiptStoreHolding(receipt(allVerified: false, verdicts: [
        'pint' => 'passed',
        'phpstan' => 'failed',
        'oxlint' => 'error',
        'evaluate' => 'acknowledged',
    ]));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[phpstan] failed')
        ->and($output)->toContain('[oxlint] error');
});

it('refuses a blocked run on the state guard, before the verdicts matter', function (): void {
    // The reachable shape of the same thing: a failing step leaves the run
    // blocked, and an unfinished walk is refused for that reason first.
    receiptStoreHolding(receipt(allVerified: false, state: 'blocked', verdicts: [
        'pint' => 'passed',
        'phpstan' => 'failed',
    ]));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('[blocked]');
});

it('still refuses a stale receipt before asking the narrower question', function (): void {
    // The narrower question is about verdicts. A stale receipt's verdicts
    // describe code that is no longer there, so it fails first either way.
    receiptStoreHolding(receipt(stale: 'Step [pint] measured a different working tree.'));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(1);
});

it('still refuses a scoped receipt asked the whole-tree question', function (): void {
    // The flag narrows which verdicts count, never which tree the run covered.
    // A scoped receipt still cannot answer for the whole tree.
    receiptStoreHolding(receipt(scope: 'backend'));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('not this whole tree');
});

it('combines with --only, answering a scope on server-run steps alone', function (): void {
    receiptStoreHolding(receipt(allVerified: false, scope: 'backend', verdicts: [
        'phpstan' => 'passed',
        'evaluate' => 'acknowledged',
    ]));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--only' => 'backend', '--server-verified' => true]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('scope [backend]');
});

it('leaves the bare call exactly as it was', function (): void {
    // The aggregate gate must not loosen by one row. Same receipt, no flag.
    receiptStoreHolding(receipt(allVerified: false, verdicts: [
        'pint' => 'passed',
        'evaluate' => 'acknowledged',
    ]));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(1);
});

/**
 * The two guards that stand before the verdicts. `all_verified` was carrying
 * three questions at once, and this flag drops only the one about
 * acknowledgements. Both of these passed before the guards existed.
 */
it('refuses a walk abandoned before it finished', function (): void {
    // `recordReceipt()` writes after every resolution, so a run abandoned at step
    // one leaves a readable receipt holding one pass. Without the state guard
    // that reads as "every server verdict passed" and exits 0, reporting a
    // formatter while the analyser and the suite never ran.
    receiptStoreHolding(receipt(allVerified: false, state: 'running', verdicts: ['fmt' => 'passed']));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[running]')
        ->and($output)->toContain('did not finish');
});

it('refuses a run that dropped a gate its config declared', function (): void {
    // The finding that forced the receipt to record coverage. `all_verified` is
    // false here for a reason that has nothing to do with acknowledgements, and
    // the verdict map alone cannot show it: the dropped step left no verdict.
    receiptStoreHolding(receipt(allVerified: false, verdicts: [
        'fmt' => 'passed',
        'analyse' => 'passed',
    ], coverage: 'incomplete'));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('did not cover the config');
});

it('refuses a receipt written before coverage was recorded', function (): void {
    // Absent means unknown, never clean. An older receipt did record notices in
    // memory and dropped them on the way to disk.
    receiptStoreHolding(receipt(allVerified: false, verdicts: ['fmt' => 'passed'], coverage: null));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('Unknown coverage is not clean coverage');
});

it('refuses a halted run, because terminal is not finished', function (): void {
    receiptStoreHolding(receipt(allVerified: false, state: 'halted', verdicts: [
        'fmt' => 'passed',
        'oxlint' => 'error',
    ]));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('[halted]');
});
