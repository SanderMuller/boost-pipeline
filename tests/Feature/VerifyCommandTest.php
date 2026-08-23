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

function receipt(bool $allVerified = true, ?string $tree = 'tree-a', ?string $stale = null, string $state = 'complete'): Receipt
{
    return new Receipt(
        runId: 'r-test',
        state: $state,
        allVerified: $allVerified,
        tree: $tree,
        stale: $stale,
        verdicts: ['pint' => 'passed', 'phpstan' => 'passed'],
        recordedAt: '2026-01-01T00:00:00+00:00',
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

    $run->resolveCurrentStep();

    try {
        expect(Artisan::call('pipeline:verify'))->toBe(0)
            ->and(Artisan::output())->toContain('verified this tree');
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
});
