<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineFingerprint;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\Phase;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Contracts\TreeFingerprint;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\StepCollection;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Run\ReceiptStoreFactory;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Steps\Shell;

/**
 * A phase no pipeline registers, so a step declared into it is dropped from the
 * walk with a notice. Declared here rather than reused from another test file:
 * this file has to run on its own.
 */
final class VerifyOrphanPhase implements Phase
{
    public function id(): string
    {
        return 'verify-orphan';
    }

    public function name(): string
    {
        return 'Verify Orphan';
    }
}

/**
 * The command is the only thing that lets a consumer act on a run, so its exit
 * code is the contract. Every case below is a reason a gate must NOT pass.
 */
function receiptStoreHolding(?Receipt $receipt): void
{
    $store = new class($receipt) implements ReceiptStore
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
    };

    app()->instance(ReceiptStore::class, $store);

    // The command reaches a receipt through the factory now, because which
    // receipt it should read depends on which pipeline was asked about.
    app()->instance(ReceiptStoreFactory::class, new ReceiptStoreFactory(
        static fn (string $pipeline): ReceiptStore => $store,
    ));
}

/**
 * Bind one store for every pipeline name.
 *
 * Every test here is about one pipeline; the ones that are about several build
 * their own map with `projectDeclaring()`.
 */
function useReceiptStore(ReceiptStore $store): void
{
    app()->instance(ReceiptStore::class, $store);
    app()->instance(ReceiptStoreFactory::class, new ReceiptStoreFactory(
        static fn (string $pipeline): ReceiptStore => $store,
    ));
}

function receiptStoreOf(?Receipt $receipt): ReceiptStore
{
    return new class($receipt) implements ReceiptStore
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
    };
}

/** @param list<string> $names */
function projectDeclaring(array $names): void
{
    app()->instance(Pipelines::class, Pipelines::fromArray(
        array_combine($names, array_map(Pipeline::configure(...), $names)),
        '.config/pipeline.php',
    ));
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

/**
 * The digest of whichever pipeline is currently bound.
 *
 * `receipt()` defaults to this for the same reason it defaults `coverage` to
 * 'complete': almost every test here is about some other guard, and a fixture that
 * looks like a receipt from before the field would trip the declaration guard
 * before reaching the one under test. Tests about the absent case pass
 * `omitConfig: true`, and tests about a mismatch pass a digest of their own.
 */
function ambientConfigDigest(): ?string
{
    $pipelines = resolve(Pipelines::class);
    $names = $pipelines->names();
    // The first, not the sole one: a multi-pipeline test has no sole name, and the
    // pipelines those tests declare are identical empty declarations, so any of
    // them yields the digest every receipt in that test needs.
    $pipeline = $names === [] ? null : $pipelines->get($names[0]);

    return $pipeline instanceof Pipeline ? PipelineFingerprint::for($pipeline) : null;
}

/**
 * @param  array<string, string>|null  $verdicts
 * @param  list<string>|null  $asserted  defaults to every passing step, as a walk of plain checks records
 */
function receipt(bool $allVerified = true, ?string $tree = 'tree-a', ?string $stale = null, string $state = 'complete', ?string $scope = null, ?array $verdicts = null, ?string $coverage = 'complete', ?array $asserted = null, bool $omitAsserted = false, ?string $config = null, bool $omitConfig = false): Receipt
{
    $verdicts ??= ['pint' => 'passed', 'phpstan' => 'passed'];

    return new Receipt(
        runId: 'r-test',
        state: $state,
        allVerified: $allVerified,
        tree: $tree,
        stale: $stale,
        verdicts: $verdicts,
        recordedAt: '2026-01-01T00:00:00+00:00',
        scope: $scope,
        coverage: $coverage,
        asserted: $omitAsserted ? null : ($asserted ?? array_keys(array_filter(
            $verdicts,
            static fn (string $verdict): bool => $verdict === 'passed',
        ))),
        config: $omitConfig ? null : ($config ?? ambientConfigDigest()),
    );
}

/**
 * Bind a project whose sole pipeline declares these steps, each tagged as given.
 *
 * The ambient test config declares one pipeline holding no steps at all, so
 * every other test in this file is silent on the declared-vs-recorded question.
 * These are the tests that are about it.
 *
 * @param  array<string, string|null>  $steps  step id => tag, or null for untagged
 */
function projectDeclaringSteps(array $steps): void
{
    app()->instance(Pipelines::class, Pipelines::fromArray(
        ['default' => Pipeline::configure()->withSteps(function (Steps $collection) use ($steps): void {
            $phase = $collection->in(Formatting::class);

            foreach ($steps as $id => $tag) {
                $step = Shell::run('true', id: $id);
                $phase->append($tag === null ? $step : $step->tagged($tag));
            }
        })],
        '.config/pipeline.php',
    ));
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

    useReceiptStore($store);
    treeReporting('tree-a');

    // Bound as well as run. The command reads the config from the container, and
    // the run records a digest of the pipeline it walked — a test that ran one
    // pipeline while the container held another was only ever passing because
    // nothing compared the two.
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt'));
    });

    app()->instance(Pipelines::class, Pipelines::fromArray(['default' => $pipeline], '.config/pipeline.php'));

    $run = Run::start(
        $pipeline->walk(),
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

it('names the scope as covering nothing when the tag no step carries', function (): void {
    // A mistyped tag walks only the untagged steps, which pass, so the verdict
    // guard would name no step: the scope asked about was never checked at all.
    projectDeclaringSteps(['pint' => null]);
    receiptStoreHolding(receipt(allVerified: false, scope: 'bakend', verdicts: ['pint' => 'passed'], coverage: 'incomplete'));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--only' => 'bakend']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('no step carries the tag [bakend]')
        ->and($output)->not->toContain('has not verified every step');
});

it('gives --server-verified the same answer for a tag no step carries', function (): void {
    projectDeclaringSteps(['pint' => null]);
    receiptStoreHolding(receipt(allVerified: false, scope: 'bakend', verdicts: ['pint' => 'passed'], coverage: 'incomplete'));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--only' => 'bakend', '--server-verified' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('no step carries the tag [bakend]');
});

it('keeps the generic coverage answer when the scope tag is real', function (): void {
    // The tag exists, so coverage broke for another reason, and claiming the
    // scope covered nothing would send the reader to fix a spelling that is right.
    projectDeclaringSteps(['pint' => 'backend']);
    receiptStoreHolding(receipt(allVerified: false, scope: 'backend', verdicts: ['pint' => 'passed'], coverage: 'incomplete'));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--only' => 'backend']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('did not cover the config')
        ->and($output)->not->toContain('covered nothing');
});

it('refuses incomplete coverage on the bare call before blaming a step', function (): void {
    // Every recorded step passed, so only coverage can explain the refusal.
    receiptStoreHolding(receipt(allVerified: false, verdicts: ['fmt' => 'passed'], coverage: 'incomplete'));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('did not cover the config')
        ->and($output)->not->toContain('has not verified every step');
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

it('refuses a run still awaiting a skill step', function (): void {
    // The state guard covers every unfinished state, and `awaiting` is the one a
    // sequencing pipeline actually sits in: the cursor has not passed the skill
    // step, so nothing behind it has run.
    receiptStoreHolding(receipt(allVerified: false, state: 'awaiting', verdicts: ['fmt' => 'passed']));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('[awaiting]');
});

it('refuses a complete receipt holding no verdicts at all', function (): void {
    // A live run cannot write this: an empty walk reaches complete in the
    // constructor and records no receipt. Built by hand, because the predicate
    // must not read an empty verdict map as "everything passed".
    //
    // Both calls refuse it, and the shared guard answers first, so the message is
    // about the empty receipt rather than about this flag. The flag's own empty
    // guard still has work to do: a walk of nothing but acknowledgements holds
    // verdicts, and reaches it.
    receiptStoreHolding(receipt(allVerified: false, verdicts: []));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('recorded no step verdicts at all');
});

it('refuses to answer when the working tree cannot be fingerprinted', function (): void {
    // The bare call tolerates this and answers from the receipt alone, which is a
    // deliberate decision recorded above. This flag cannot: it exists so a caller
    // can skip work because the tree still matches, and with nothing to compare
    // there is no "still".
    receiptStoreHolding(receipt(allVerified: false));
    treeReporting(null);

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('cannot be fingerprinted here');
});

it('refuses to answer when the run recorded no tree fingerprint', function (): void {
    receiptStoreHolding(receipt(allVerified: false, tree: null));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('recorded no tree fingerprint');
});

it('refuses a run whose only passing step rewrites the tree', function (): void {
    // A formatter reports that it ran, never that the result is correct. Counting
    // it as verification exits 0 for a walk that checked nothing at all.
    receiptStoreHolding(receipt(
        allVerified: true,
        verdicts: ['pint' => 'passed'],
        asserted: [],
    ));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('rewrites the tree rather than checking it');
});

it('refuses a receipt written before a rewrite could be told from a check', function (): void {
    receiptStoreHolding(receipt(allVerified: false, omitAsserted: true));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('rewrote it');
});

it('names the steps it counted, so a caller knows which checks it may skip', function (): void {
    // Exit 0 alone never said WHICH checks ran, so a caller skipping work on the
    // strength of it could be skipping a check this pipeline does not hold.
    receiptStoreHolding(receipt(
        allVerified: false,
        verdicts: ['pint' => 'passed', 'phpstan' => 'passed', 'evaluate' => 'acknowledged'],
        asserted: ['phpstan'],
    ));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('passed all 1 step(s)')
        ->and($output)->toContain('[phpstan]')
        // Counted separately and never folded into the total.
        ->and($output)->toContain('1 step(s) rewrote the tree')
        ->and($output)->toContain('1 step(s) were only acknowledged');
});

it('refuses a real mutating-only run end to end, receipt and command agreeing', function (): void {
    // The same seam the 0.4.0 test above guards, for the rewrite rule. Both halves
    // can be individually right and still disagree: a hand-built receipt proves
    // what the command does with `asserted`, never that `Run` fills it in.
    $path = sys_get_temp_dir().'/bp-e2e-'.bin2hex(random_bytes(4)).'/receipt.json';
    $store = new JsonReceiptStore($path);

    useReceiptStore($store);
    treeReporting('tree-a');

    // Bound as well as run. The command reads the config from the container, and
    // the run records a digest of the pipeline it walked — a test that ran one
    // pipeline while the container held another was only ever passing because
    // nothing compared the two.
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'fmt')->mutating());
    });

    app()->instance(Pipelines::class, Pipelines::fromArray(['default' => $pipeline], '.config/pipeline.php'));

    $run = Run::start(
        $pipeline->walk(),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-e2e-mutating',
        tree: resolve(TreeFingerprint::class),
        receipts: $store,
    );

    $run->resolveCurrent();

    try {
        // The bare call still exits 0: `all_verified` asks whether every step
        // passed, and every step did. That contract is unchanged.
        expect(Artisan::call('pipeline:verify'))->toBe(0);

        expect(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(1)
            ->and(Artisan::output())->toContain('rewrites the tree rather than checking it');
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
});

it('will not count an acknowledged step even when the receipt lists it as asserting', function (): void {
    // The intersection is with the steps the SERVER produced a verdict for, not
    // with the verdict map. A receipt naming an acknowledged step as having
    // asserted the tree is the shape that would launder judgement into a check.
    receiptStoreHolding(receipt(
        allVerified: false,
        verdicts: ['evaluate' => 'acknowledged'],
        asserted: ['evaluate'],
    ));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);

    expect($exit)->toBe(1)
        ->and(Artisan::output())->toContain('produced a verdict for none of them');
});

it('refuses a receipt holding no verdicts, however it came to hold none', function (array $data): void {
    // `all_verified` is a claim the receipt makes about itself. Over an empty
    // verdict map it is vacuous, and the bare call used to answer "verified this
    // tree: 0 step(s)". Guarding the predicate rather than the JSON shape closes
    // an absent key, an explicit null and an empty map in one place.
    $path = sys_get_temp_dir().'/bp-empty-'.bin2hex(random_bytes(4)).'/receipt.json';
    mkdir(dirname($path), recursive: true);
    file_put_contents($path, json_encode([
        'run' => 'r-empty', 'state' => 'complete', 'all_verified' => true,
        'tree' => 'tree-a', 'recorded_at' => '2026-01-01T00:00:00+00:00',
        'coverage' => 'complete', 'asserted' => [],
    ] + $data));

    useReceiptStore(new JsonReceiptStore($path));
    treeReporting('tree-a');

    try {
        expect(Artisan::call('pipeline:verify'))->toBe(1)
            ->and(Artisan::output())->toContain('recorded no step verdicts at all');
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
})->with([
    'the key is absent' => [[]],
    'the key is explicitly null' => [['verdicts' => null]],
    'the map is empty' => [['verdicts' => []]],
]);

it('names the empty receipt rather than the tree it does not describe', function (): void {
    // The tree check would otherwise answer first and report that this receipt
    // "verified a different working tree". It verified no tree.
    receiptStoreHolding(receipt(verdicts: []));
    treeReporting('tree-b');

    expect(Artisan::call('pipeline:verify'))->toBe(1)
        ->and(Artisan::output())->toContain('recorded no step verdicts at all');
});

/**
 * Which pipeline the question is about.
 *
 * A project asking its code more than one question has no single answer to "is
 * this tree verified". The refusal is the same rule a scoped receipt already
 * follows, one level up — and naming the pipelines is the useful half of it.
 */
it('answers without a name when the project declares one pipeline', function (): void {
    projectDeclaring(['pr']);
    receiptStoreHolding(receipt());
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(0);
});

it('answers when the name given is the only pipeline there is', function (): void {
    projectDeclaring(['pr']);
    receiptStoreHolding(receipt());
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--pipeline' => 'pr']))->toBe(0);
});

it('refuses a bare question when the project declares several, and names them', function (): void {
    // There is deliberately no aggregate "every pipeline is green" answer: a
    // project that routinely runs only its PR pipeline could never reach exit 0
    // through it, and a gate that cannot pass is one people learn to skip.
    projectDeclaring(['pr', 'release', 'evaluate']);
    receiptStoreHolding(receipt());
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('has no single answer')
        ->and($output)->toContain('[pr]')
        ->and($output)->toContain('[release]')
        ->and($output)->toContain('[evaluate]')
        // And it says what to do about it.
        ->and($output)->toContain('--pipeline=');
});

it('refuses a name the project does not declare, and names what it does', function (): void {
    projectDeclaring(['pr', 'release']);
    receiptStoreHolding(receipt());
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--pipeline' => 'staging']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('No pipeline named [staging] is configured')
        ->and($output)->toContain('[pr]');
});

it('refuses a name a project on the legacy single-pipeline file does not have', function (): void {
    // That file is loaded as one pipeline called `default`, so `release` is not a
    // name it declares.
    projectDeclaring(['default']);
    receiptStoreHolding(receipt());
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--pipeline' => 'release']))->toBe(1)
        ->and(Artisan::output())->toContain('[default]');
});

it('treats a blank --pipeline as no name at all', function (): void {
    projectDeclaring(['pr']);
    receiptStoreHolding(receipt());
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--pipeline' => '  ']))->toBe(0);
});

it('reads the receipt belonging to the pipeline it was asked about', function (): void {
    // Two receipts, two answers, both true at once — the thing one receipt file
    // could never do.
    projectDeclaring(['pr', 'release']);
    treeReporting('tree-a');

    $stores = [
        'pr' => receiptStoreOf(receipt(verdicts: ['phpstan' => 'passed'], asserted: ['phpstan'])),
        'release' => receiptStoreOf(receipt(allVerified: false, verdicts: ['audit' => 'failed'], asserted: [])),
    ];

    app()->instance(ReceiptStoreFactory::class, new ReceiptStoreFactory(
        static fn (string $pipeline): ReceiptStore => $stores[$pipeline],
    ));

    expect(Artisan::call('pipeline:verify', ['--pipeline' => 'pr']))->toBe(0)
        ->and(Artisan::call('pipeline:verify', ['--pipeline' => 'release']))
        ->toBe(1);
});

it('lets one pipeline go stale without touching the other', function (): void {
    // Each receipt records its own tree, so a release run from before an edit
    // fails on staleness while a PR run made after it passes.
    projectDeclaring(['pr', 'release']);
    treeReporting('tree-b');

    $stores = [
        'pr' => receiptStoreOf(receipt(tree: 'tree-b')),
        'release' => receiptStoreOf(receipt(tree: 'tree-a')),
    ];

    app()->instance(ReceiptStoreFactory::class, new ReceiptStoreFactory(
        static fn (string $pipeline): ReceiptStore => $stores[$pipeline],
    ));

    expect(Artisan::call('pipeline:verify', ['--pipeline' => 'pr']))->toBe(0)
        ->and(Artisan::call('pipeline:verify', ['--pipeline' => 'release']))
        ->toBe(1)
        ->and(Artisan::output())
        ->toContain('verified a different working tree');
});

it('composes a pipeline, a scope and the server-verified question at once', function (): void {
    projectDeclaring(['pr', 'release']);
    treeReporting('tree-a');

    $stores = [
        'pr' => receiptStoreOf(receipt(
            allVerified: false,
            scope: 'backend',
            verdicts: ['phpstan' => 'passed', 'evaluate' => 'acknowledged'],
            asserted: ['phpstan'],
        )),
        'release' => receiptStoreOf(null),
    ];

    app()->instance(ReceiptStoreFactory::class, new ReceiptStoreFactory(
        static fn (string $pipeline): ReceiptStore => $stores[$pipeline],
    ));

    $exit = Artisan::call('pipeline:verify', [
        '--pipeline' => 'pr',
        '--only' => 'backend',
        '--server-verified' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('scope [backend]');

    // The name narrows which receipt is read; it never widens what exit 0 claims.
    expect(Artisan::call('pipeline:verify', ['--pipeline' => 'pr', '--server-verified' => true]))->toBe(1);
});

it('reports no run for a pipeline that has never been walked', function (): void {
    projectDeclaring(['pr', 'release']);
    treeReporting('tree-a');

    $stores = [
        'pr' => receiptStoreOf(receipt()),
        'release' => receiptStoreOf(null),
    ];

    app()->instance(ReceiptStoreFactory::class, new ReceiptStoreFactory(
        static fn (string $pipeline): ReceiptStore => $stores[$pipeline],
    ));

    expect(Artisan::call('pipeline:verify', ['--pipeline' => 'release']))->toBe(1)
        ->and(Artisan::output())->toContain('No pipeline run has been recorded');
});

it('tells a moved receipt apart from a run that never happened', function (): void {
    // "Nothing has been verified" is true for both and useless for one: after
    // upgrading it reads as a broken gate, and the reader diagnoses a move this
    // command already knows about.
    projectDeclaring(['pr']);
    receiptStoreHolding(null);
    treeReporting('tree-a');

    $legacy = storage_path(JsonReceiptStore::LEGACY_PATH);

    if (! is_dir(dirname($legacy))) {
        mkdir(dirname($legacy), recursive: true);
    }

    file_put_contents($legacy, '{"run":"r-old","state":"complete"}');

    try {
        $exit = Artisan::call('pipeline:verify');
        $output = Artisan::output();

        expect($exit)->toBe(1)
            ->and($output)->toContain('written before 0.10.0')
            ->and($output)->toContain('safe to delete')
            // The path, as the console shows it: Laravel's output components
            // rewrite an absolute path under the base path to a relative one, so
            // asserting the absolute form would test the framework's formatter
            // rather than this message.
            ->and($output)->toContain(JsonReceiptStore::LEGACY_PATH);
    } finally {
        @unlink($legacy);
    }
});

it('says nothing about a legacy receipt when there is none', function (): void {
    projectDeclaring(['pr']);
    receiptStoreHolding(null);
    treeReporting('tree-a');

    @unlink(storage_path(JsonReceiptStore::LEGACY_PATH));

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Nothing has been verified')
        ->and($output)->not->toContain('0.10.0');
});

it('names the moved commit at the gate, not only in the receipt', function (): void {
    // The two messages reach different readers. `stale` reaches an agent mid-walk;
    // this one reaches a person running the gate, typically right after merging a
    // base branch in — the ordinary way to arrive here having changed no file.
    // Reading "a different working tree" then sends them hunting for an edit that
    // does not exist.
    receiptStoreHolding(receipt(tree: 'tree-a'));
    treeReporting('tree-b');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('verified a different working tree')
        ->and($output)->toContain('rebase')
        ->and($output)->toContain('nothing to undo');
});

/**
 * A step the server never loaded.
 *
 * The MCP server resolves the config once when its process starts, so a step
 * declared after that is invisible to every run until the client reconnects. The
 * run then walks the steps it knew about, finds nothing wrong, and records itself
 * complete — and `coverage` cannot contradict it, because coverage is written
 * from the walk's own notices and an unloaded step raises none.
 *
 * The tree fingerprint does not catch it either: the run ran against the tree
 * that already held the new step, so the fingerprints match. This command runs
 * in its own process against the config as it stands now, which makes it the only
 * reader that can answer at all.
 */
it('fails when the config declares a step the recorded run never held', function (): void {
    projectDeclaringSteps(['pint' => null, 'phpstan' => null, 'affected-tests' => null]);
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed', 'phpstan' => 'passed']));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[affected-tests]')
        // The reader has to be told why a run calling itself complete is not,
        // or the only available diagnosis is "the gate is broken".
        ->and($output)->toContain('reconnect the client');
});

it('fails the same way with --server-verified, which is the flag a skill reads', function (): void {
    // The narrower question was equally exposed: every verdict the server
    // produced can be a pass while a declared gate was never among them.
    projectDeclaringSteps(['pint' => null, 'phpstan' => null, 'affected-tests' => null]);
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed', 'phpstan' => 'passed']));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('[affected-tests]');
});

/**
 * The false-failure guard, and the most important test here.
 *
 * A scoped run leaves its out-of-scope steps out deliberately. Comparing against
 * the whole walk rather than the walk for the receipt's own scope would fail
 * every scoped run — turning a fix for a false green into a gate nobody can pass.
 */
it('does not fail a scoped run for steps its scope deliberately left out', function (): void {
    projectDeclaringSteps([
        'pint' => 'backend',
        'phpstan' => 'backend',
        'oxlint' => 'frontend',
        'tsc' => 'frontend',
    ]);
    receiptStoreHolding(receipt(
        scope: 'backend',
        verdicts: ['pint' => 'passed', 'phpstan' => 'passed'],
    ));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--only' => 'backend']))->toBe(0);
});

it('still fails a scoped run for a step missing from its own scope', function (): void {
    projectDeclaringSteps([
        'pint' => 'backend',
        'phpstan' => 'backend',
        'oxlint' => 'frontend',
    ]);
    receiptStoreHolding(receipt(scope: 'backend', verdicts: ['pint' => 'passed']));
    treeReporting('tree-a');

    // One read. `Artisan::output()` drains the buffer, so a second call returns
    // an empty string and every `not->toContain` after it passes vacuously.
    $exit = Artisan::call('pipeline:verify', ['--only' => 'backend']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[phpstan]')
        ->and($output)->toContain('scope [backend]')
        // The frontend step is out of scope, not missing. Naming it here would
        // send the reader hunting for a step this run was never about.
        ->and($output)->not->toContain('[oxlint]');
});

it('fails a scoped --server-verified call, the shape a skill actually invokes', function (): void {
    // Both dimensions at once, because this is the invocation a gate runs: a
    // scope and the narrower question together. Each is pinned above on its own.
    projectDeclaringSteps([
        'pint' => 'backend',
        'phpstan' => 'backend',
        'affected-tests' => 'backend',
        'tsc' => 'frontend',
    ]);
    receiptStoreHolding(receipt(
        scope: 'backend',
        verdicts: ['pint' => 'passed', 'phpstan' => 'passed'],
    ));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--only' => 'backend', '--server-verified' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[affected-tests]')
        ->and($output)->not->toContain('[tsc]');
});

it('reports a config too broken to compare against, rather than throwing', function (): void {
    // Resolving the walk is new work for this command, and the walk is where a
    // duplicate step id is detected — `Pipelines` validates names and types, not
    // step ids. Left uncaught, a consumer with a broken config got a stack trace
    // from their gate instead of an answer.
    //
    // It fails rather than passes. The old behaviour here was exit 0, which is
    // worse than either: the config cannot be walked, so no run can be checked
    // against it, and the server would refuse to run it at all.
    app()->instance(Pipelines::class, Pipelines::fromArray(
        ['default' => Pipeline::configure()->withSteps(function (Steps $collection): void {
            $collection->in(Formatting::class)->append(Shell::run('true', id: 'dup'));
            $collection->in(Formatting::class)->append(Shell::run('true', id: 'dup'));
        })],
        '.config/pipeline.php',
    ));
    receiptStoreHolding(receipt(verdicts: ['dup' => 'passed']));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Duplicate step id [dup]');
});

it('does not fail a run whose steps resolved as one parallel position', function (): void {
    // A real run through the real recorder, not a hand-built receipt: the guard
    // compares declared step ids against recorded verdict keys, so it breaks
    // outright if a parallel position records one verdict for the batch instead
    // of one per step. Every declared step here resolves in a single position.
    $path = sys_get_temp_dir().'/bp-parallel-verify-'.bin2hex(random_bytes(4)).'/receipt.json';
    $store = new JsonReceiptStore($path);

    $pipeline = Pipeline::configure()->withSteps(function (Steps $collection): void {
        $collection->in(Formatting::class)->parallel(function (StepCollection $group): void {
            $group->append(Shell::run('true', id: 'left'));
            $group->append(Shell::run('true', id: 'right'));
        });
    });

    app()->instance(Pipelines::class, Pipelines::fromArray(
        ['default' => $pipeline],
        '.config/pipeline.php',
    ));
    useReceiptStore($store);
    treeReporting('tree-a');

    $run = Run::start(
        $pipeline->walk(),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-par',
        tree: resolve(TreeFingerprint::class),
        receipts: $store,
    );

    $run->resolveCurrent();

    try {
        // Both halves stated: the recorder keys a batched step by its own id, and
        // the gate accepts the result. Asserting only the exit code would still
        // pass if the guard stopped comparing anything at all.
        expect($store->read()?->verdicts)->toBe(['left' => 'passed', 'right' => 'passed'])
            ->and(Artisan::call('pipeline:verify'))->toBe(0);
    } finally {
        @unlink($path);
        @rmdir(dirname($path));
    }
});

it('fails when the config declares a step into a phase nothing registered', function (): void {
    // A step dropped before the walk is missing from the comparison as well as
    // from the run, so counting step ids finds nothing wrong. Without this the
    // gate passes a config that declares a gate nothing can reach — the same
    // false green one layer down.
    app()->instance(Pipelines::class, Pipelines::fromArray(
        ['default' => Pipeline::configure()->withSteps(function (Steps $collection): void {
            $collection->in(Formatting::class)->append(Shell::run('true', id: 'pint'));
            $collection->in(VerifyOrphanPhase::class)->append(Shell::run('true', id: 'orphan'));
        })],
        '.config/pipeline.php',
    ));
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed']));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('no phase registers')
        ->and($output)->toContain('[orphan]');
});

it('answers a scoped question from a full run even when another scope gained a step', function (): void {
    // An unscoped run walked everything, so it answers a question about any one
    // scope. Comparing it against the WHOLE current walk would fail this on the
    // strength of a frontend step, which says nothing about backend — a false
    // failure introduced by the guard rather than caught by it.
    projectDeclaringSteps([
        'pint' => 'backend',
        'phpstan' => 'backend',
        'tsc' => 'frontend',
    ]);
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed', 'phpstan' => 'passed']));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--only' => 'backend']))->toBe(0);
});

it('still fails a full run asked about a scope it left a step out of', function (): void {
    // The other half: reading the asked scope must not stop the guard working
    // inside it.
    projectDeclaringSteps([
        'pint' => 'backend',
        'phpstan' => 'backend',
        'tsc' => 'frontend',
    ]);
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed']));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--only' => 'backend']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[phpstan]')
        ->and($output)->not->toContain('[tsc]');
});

it('does not fail a scoped question because another scope holds a broken step', function (): void {
    // The dropped-step check reads the walk for the question being answered, and
    // `noticesForUnregisteredPhases()` ignores the selection — so reading it for a
    // scoped question would fail a backend answer over a frontend step declared
    // into a phase nothing registers. The frontend step is not part of the backend
    // question, and a gate that fails on it is a gate nobody can pass.
    app()->instance(Pipelines::class, Pipelines::fromArray(
        ['default' => Pipeline::configure()->withSteps(function (Steps $collection): void {
            $collection->in(Formatting::class)->append(Shell::run('true', id: 'pint')->tagged('backend'));
            $collection->in(VerifyOrphanPhase::class)->append(Shell::run('true', id: 'orphan')->tagged('frontend'));
        })],
        '.config/pipeline.php',
    ));
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed']));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--only' => 'backend']))->toBe(0);
});

/**
 * A server that loaded the config before it changed runs an older definition of
 * the same step id and records it as a pass. The verdicts are keyed by id, so the
 * declared-vs-recorded check sees nothing missing, and the tree fingerprint
 * matches because the run ran against the tree that already held the new config.
 * The recorded digest is the only thing that can catch it.
 */
function pipelineDeclaring(string $command): Pipeline
{
    return Pipeline::configure()->withSteps(function (Steps $collection) use ($command): void {
        $collection->in(Formatting::class)->append(Shell::run($command, id: 'pint'));
    });
}

function projectRunning(Pipeline $pipeline): void
{
    app()->instance(Pipelines::class, Pipelines::fromArray(['default' => $pipeline], '.config/pipeline.php'));
}

it('passes when the run walked the declaration the config still produces', function (): void {
    $pipeline = pipelineDeclaring('pint');
    projectRunning($pipeline);
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed'], config: PipelineFingerprint::for($pipeline)));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(0);
});

it('fails when the run walked a different declaration of the same step', function (): void {
    // The motivating case. `pint` has a verdict, so nothing about the step list is
    // wrong — only its definition changed under a server that never reloaded.
    projectRunning(pipelineDeclaring('pint --dirty'));
    receiptStoreHolding(receipt(
        verdicts: ['pint' => 'passed'],
        config: PipelineFingerprint::for(pipelineDeclaring('pint')),
    ));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('not the declaration')
        // It must not accuse the server: a config git cannot see, or one that
        // computes part of itself at load time, produces the same mismatch.
        ->and($output)->toContain('computes');
});

it('fails a scoped call and --server-verified on the same mismatch', function (): void {
    projectRunning(pipelineDeclaring('pint --dirty'));
    $stale = PipelineFingerprint::for(pipelineDeclaring('pint'));

    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed'], config: $stale));
    treeReporting('tree-a');
    expect(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(1);

    receiptStoreHolding(receipt(scope: 'backend', verdicts: ['pint' => 'passed'], config: $stale));
    treeReporting('tree-a');
    expect(Artisan::call('pipeline:verify', ['--only' => 'backend']))->toBe(1);
});

it('reports the stale declaration ahead of a step the config gained', function (): void {
    // Order of causes. A stale server explains BOTH the changed definition and the
    // missing step, so naming the missing step first would send the reader hunting
    // a symptom while the root cause goes unmentioned.
    projectRunning(Pipeline::configure()->withSteps(function (Steps $collection): void {
        $collection->in(Formatting::class)->append(Shell::run('pint', id: 'pint'));
        $collection->in(Formatting::class)->append(Shell::run('phpstan', id: 'phpstan'));
    }));
    receiptStoreHolding(receipt(
        verdicts: ['pint' => 'passed'],
        config: PipelineFingerprint::for(pipelineDeclaring('pint')),
    ));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('not the declaration')
        ->and($output)->not->toContain('never held');
});

it('lets the tree message win when both the tree and the declaration moved', function (): void {
    // The tree check returns first, deliberately. "Open a new run" is the same
    // advice, and a receipt describing another tree says nothing about this code
    // whatever config produced it.
    projectRunning(pipelineDeclaring('pint --dirty'));
    receiptStoreHolding(receipt(
        tree: 'tree-a',
        verdicts: ['pint' => 'passed'],
        config: PipelineFingerprint::for(pipelineDeclaring('pint')),
    ));
    treeReporting('tree-b');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('different working tree')
        ->and($output)->not->toContain('not the declaration');
});

it('ignores an absent digest on the bare call and refuses it under --server-verified', function (): void {
    // A receipt from before this field is otherwise sound. Failing every one of
    // them on upgrade day would be a false failure for every consumer, to close a
    // case the next run closes by itself. The strict flag still refuses unknown,
    // which is exactly where `coverage` already refuses it.
    $pipeline = pipelineDeclaring('pint');
    projectRunning($pipeline);
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed'], omitConfig: true));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(0);

    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed'], omitConfig: true));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('recorded before');
});

it('skips the comparison when the consumer turned it off', function (): void {
    // The escape for a config that computes part of its declaration at load time.
    // Without it, such a project has a gate that can never pass, and a gate that
    // cannot pass is one people switch off entirely.
    config()->set('boost-pipeline.verify.config_fingerprint', false);

    projectRunning(pipelineDeclaring('pint --dirty'));
    receiptStoreHolding(receipt(
        verdicts: ['pint' => 'passed'],
        config: PipelineFingerprint::for(pipelineDeclaring('pint')),
    ));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(0);
});

it('ignores a declaration it cannot read on the bare call, and refuses it under --server-verified', function (): void {
    // A format this build cannot reproduce says nothing about the declaration, so
    // it must travel the absent-digest path and never the mismatch path. Turning
    // "I cannot tell" into "you changed it" is the failure the format tag exists to
    // prevent, and it would arrive the day the algorithm next changes.
    $pipeline = pipelineDeclaring('pint');
    projectRunning($pipeline);
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed'], config: 'v2:0123456789abcdef'));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(0);

    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed'], config: 'v2:0123456789abcdef'));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        // Its own sentence. One receipt predates the field, this one was written by
        // a version whose digests this build cannot reproduce — telling a reader
        // the second is the first sends them to redo an upgrade they already did.
        ->and($output)->toContain('cannot reproduce')
        ->and($output)->not->toContain('recorded before');
});

it('bounds the unreadable value it echoes back', function (): void {
    // The only message that prints a value BECAUSE it failed validation. A real
    // digest is short; anything reaching here is by definition not one, so a
    // receipt holding a megabyte would flood the terminal it is trying to inform.
    projectRunning(pipelineDeclaring('pint'));
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed'], config: str_repeat('z', 5000)));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and(mb_strlen($output))->toBeLessThan(1000)
        ->and($output)->toContain('cannot reproduce');
});

it('keeps the recorded-before message for a receipt that predates the field', function (): void {
    projectRunning(pipelineDeclaring('pint'));
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed'], omitConfig: true));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--server-verified' => true]);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('recorded before')
        ->and($output)->not->toContain('cannot reproduce');
});

it('accepts an untagged digest written by the release before tagging', function (): void {
    // Every receipt on disk today is untagged and was written by the algorithm
    // still in use. Reading those as unreadable would refuse all of them at once,
    // which is the false failure this change exists to avoid.
    $pipeline = pipelineDeclaring('pint');
    projectRunning($pipeline);

    $untagged = substr(PipelineFingerprint::for($pipeline), strlen('v1:'));
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed'], config: $untagged));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(0)
        ->and(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(0);
});

it('still refuses a real declaration change once digests are tagged', function (): void {
    // The tag must not swallow the comparison it was added to protect.
    projectRunning(pipelineDeclaring('pint --dirty'));
    receiptStoreHolding(receipt(
        verdicts: ['pint' => 'passed'],
        config: PipelineFingerprint::for(pipelineDeclaring('pint')),
    ));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(1)
        ->and(Artisan::output())->toContain('not the declaration');
});

it('does not refuse an absent declaration when the comparison is switched off', function (): void {
    // A behaviour change from the release that added the digest, where this refusal
    // was unconditional. The toggle governs the whole declaration question, not
    // only the comparison: a consumer who switched it off is not asking, so
    // refusing because the receipt cannot answer would reintroduce the check by
    // another door. One switch, one concept.
    config()->set('boost-pipeline.verify.config_fingerprint', false);

    projectRunning(pipelineDeclaring('pint'));
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed'], omitConfig: true));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(0);
});

it('does not refuse an unreadable declaration when the comparison is switched off', function (): void {
    // The opt-out covers the strict flag too. A consumer who turned the comparison
    // off has said this gate is not for them, and refusing on a format question
    // would reintroduce it by another door.
    config()->set('boost-pipeline.verify.config_fingerprint', false);

    projectRunning(pipelineDeclaring('pint'));
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed'], config: 'v2:0123456789abcdef'));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--server-verified' => true]))->toBe(0);
});

it('refuses a scoped call when the config drops a step inside that scope', function (): void {
    // The gap this closes. The notice was prose and could not say which scope the
    // dropped step belonged to, so a scoped call was exempted from the check
    // entirely — and a declared gate that can never run went unmentioned.
    app()->instance(Pipelines::class, Pipelines::fromArray(
        ['default' => Pipeline::configure()->withSteps(function (Steps $collection): void {
            $collection->in(Formatting::class)->append(Shell::run('true', id: 'pint')->tagged('backend'));
            $collection->in(VerifyOrphanPhase::class)->append(Shell::run('true', id: 'orphan')->tagged('backend'));
        })],
        '.config/pipeline.php',
    ));
    receiptStoreHolding(receipt(scope: 'backend', verdicts: ['pint' => 'passed']));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify', ['--only' => 'backend']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('no phase registers')
        // Named, which the prose notice could only manage by being quoted whole —
        // and the phase too, since that is what has to be registered to fix it.
        ->and($output)->toContain('[orphan] in phase [VerifyOrphanPhase]');
});

it('still passes a scoped call when the dropped step belongs to another scope', function (): void {
    // The false failure the exemption existed to avoid, and it has to survive: a
    // frontend step declared into an unregistered phase says nothing about a
    // backend answer.
    //
    // This pins the new guard IN ISOLATION, on a receipt built by hand. A real
    // scoped run in this situation records `all_verified: false`, because
    // `Run::verifiedGiven()` reads the unfiltered `notices` and a drop anywhere
    // makes them non-empty — so the bare call still refuses it, through a different
    // guard with a different message. Measured, not assumed. Until that is settled
    // (the spec's one open question), this guard's out-of-scope tolerance is real in
    // the command and unreachable end to end.
    app()->instance(Pipelines::class, Pipelines::fromArray(
        ['default' => Pipeline::configure()->withSteps(function (Steps $collection): void {
            $collection->in(Formatting::class)->append(Shell::run('true', id: 'pint')->tagged('backend'));
            $collection->in(VerifyOrphanPhase::class)->append(Shell::run('true', id: 'orphan')->tagged('frontend'));
        })],
        '.config/pipeline.php',
    ));
    receiptStoreHolding(receipt(scope: 'backend', verdicts: ['pint' => 'passed']));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--only' => 'backend']))->toBe(0);
});

it('refuses a scoped call for an untagged dropped step, which is in every scope', function (): void {
    app()->instance(Pipelines::class, Pipelines::fromArray(
        ['default' => Pipeline::configure()->withSteps(function (Steps $collection): void {
            $collection->in(Formatting::class)->append(Shell::run('true', id: 'pint')->tagged('backend'));
            $collection->in(VerifyOrphanPhase::class)->append(Shell::run('true', id: 'orphan'));
        })],
        '.config/pipeline.php',
    ));
    receiptStoreHolding(receipt(scope: 'backend', verdicts: ['pint' => 'passed']));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify', ['--only' => 'backend']))->toBe(1);
});

it('counts an acknowledged step as held, leaving that question to all_verified', function (): void {
    // Presence, not verdict. An acknowledged step did reach the cursor, and
    // whether an acknowledgement is good enough is a separate question the
    // acknowledgement guards already own — with a message written for it.
    projectDeclaringSteps(['pint' => null, 'review' => null]);
    receiptStoreHolding(receipt(
        allVerified: false,
        verdicts: ['pint' => 'passed', 'review' => 'acknowledged'],
    ));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('cannot exit 0')
        ->and($output)->not->toContain('reconnect the client');
});

it('passes a run holding a verdict for a step the config has since removed', function (): void {
    // Declared-now must be a subset of recorded, not equal to it. The question is
    // whether the run covers what the config asks for today; a step since removed
    // asks for nothing. The page shows it under `undeclared` rather than dropping
    // it, and that is the right place for it — it is not a reason to fail a gate.
    projectDeclaringSteps(['pint' => null]);
    receiptStoreHolding(receipt(verdicts: ['pint' => 'passed', 'retired' => 'passed']));
    treeReporting('tree-a');

    expect(Artisan::call('pipeline:verify'))->toBe(0);
});

it('does not blame a stale server for a run that simply never finished', function (): void {
    // An unfinished run is missing verdicts because the cursor never reached
    // them. Reporting that as a config-loading problem would send the reader to
    // reconnect a client over a run they stopped themselves.
    projectDeclaringSteps(['pint' => null, 'phpstan' => null]);
    receiptStoreHolding(receipt(
        allVerified: false,
        state: 'blocked',
        verdicts: ['pint' => 'failed'],
    ));
    treeReporting('tree-a');

    $exit = Artisan::call('pipeline:verify');
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('[pint] failed')
        ->and($output)->not->toContain('reconnect the client');
});
