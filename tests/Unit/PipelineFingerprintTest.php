<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineFingerprint;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Phases\Defaults\Agent;
use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
use SanderMuller\BoostPipeline\Phases\StepCollection;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\Receipt;
use SanderMuller\BoostPipeline\Run\Run;
use SanderMuller\BoostPipeline\Steps\Shell;
use SanderMuller\BoostPipeline\Steps\Skill;

/**
 * The digest answers one question: is this the declaration the run used?
 *
 * Every input below is something a stale server could be holding an older version
 * of, so each gets a test that changing it alone moves the digest. The inverse
 * matters just as much — anything that moves the digest without being a
 * declaration change is a gate failing with nothing wrong, which is worse than
 * the false green this closes, because a gate that cannot pass gets switched off.
 */
function fingerprintOf(Closure $declare): string
{
    return PipelineFingerprint::for(Pipeline::configure()->withSteps($declare));
}

/** The baseline every mutation below is compared against. */
function baselineDeclaration(): Closure
{
    return function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->mutating());
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan'));
    };
}

it('gives the same digest for the same declaration built twice', function (): void {
    // Two independently constructed pipelines. If this ever depends on object
    // identity or construction order, every comparison across processes breaks.
    expect(fingerprintOf(baselineDeclaration()))->toBe(fingerprintOf(baselineDeclaration()));
});

it('describes a declaration rather than an identity', function (): void {
    // The pipeline name is not an input. Two pipelines declaring the same steps
    // would run the same thing, which is the only question the digest answers.
    expect(PipelineFingerprint::for(Pipeline::configure()->withSteps(baselineDeclaration())))
        ->toBe(PipelineFingerprint::for(Pipeline::configure()->withSteps(baselineDeclaration())));
});

it('is stable for a pipeline declaring nothing at all', function (): void {
    expect(PipelineFingerprint::for(Pipeline::configure()))
        ->toBe(PipelineFingerprint::for(Pipeline::configure()))
        ->and(PipelineFingerprint::for(Pipeline::configure()))->not->toBe(fingerprintOf(baselineDeclaration()));
});

it('changes when a single declaration input changes', function (string $case, Closure $declare): void {
    // One dataset per thing a stale server could hold an older version of. The
    // case name is the input, so a failure names what stopped being covered.
    expect(fingerprintOf($declare))->not->toBe(fingerprintOf(baselineDeclaration()));
})->with([
    'command' => ['command', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint --dirty', id: 'pint')->mutating());
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan'));
    }],
    'step id' => ['step id', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'formatter')->mutating());
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan'));
    }],
    'mutating flag' => ['mutating flag', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint'));
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan'));
    }],
    'tags' => ['tags', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->mutating()->tagged('backend'));
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan'));
    }],
    'scope command' => ['scope command', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->mutating());
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan')->inspecting('git diff --name-only'));
    }],
    'step timeout' => ['step timeout', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->mutating());
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan')->timeout(900.0));
    }],
    'an env key' => ['an env key', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->mutating());
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan')->withEnv(['MEMORY' => '2G']));
    }],
    'phase a step is declared into' => ['phase a step is declared into', function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->mutating());
        $steps->in(Formatting::class)->append(Shell::run('phpstan', id: 'phpstan'));
    }],
    'step order within a phase' => ['step order within a phase', function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan'));
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->mutating());
    }],
]);

it('changes when the same steps are regrouped into one parallel position', function (): void {
    // Grouping is declaration: ungrouped, these resolve one after another.
    $sequential = fingerprintOf(function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan'));
        $steps->in(StaticAnalysis::class)->append(Shell::run('psalm', id: 'psalm'));
    });

    $grouped = fingerprintOf(function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->parallel(function (StepCollection $group): void {
            $group->append(Shell::run('phpstan', id: 'phpstan'));
            $group->append(Shell::run('psalm', id: 'psalm'));
        });
    });

    expect($grouped)->not->toBe($sequential);
});

it('changes when a skill step changes what it asks for or what proves it', function (string $case, Closure $declare): void {
    // A skill step is the case where the server is the only thing checking
    // anything, so an old proof or an old instruction is the worst thing to miss.
    $baseline = function (Steps $steps): void {
        $steps->in(Agent::class)->append(Skill::run('/evaluate', id: 'review'));
    };

    expect(fingerprintOf($declare))->not->toBe(fingerprintOf($baseline));
})->with([
    'invocation' => ['invocation', function (Steps $steps): void {
        $steps->in(Agent::class)->append(Skill::run('/code-review', id: 'review'));
    }],
    'instruction' => ['instruction', function (Steps $steps): void {
        $steps->in(Agent::class)->append(Skill::run('/evaluate', id: 'review', instruction: 'Only the error handling.'));
    }],
    'proof' => ['proof', function (Steps $steps): void {
        $steps->in(Agent::class)->append(Skill::run('/evaluate', id: 'review')->proving('test -f report.txt'));
    }],
]);

it('changes when the pipeline timeout changes', function (): void {
    $base = Pipeline::configure()->withSteps(baselineDeclaration());
    $slower = Pipeline::configure()->withSteps(baselineDeclaration())->withTimeout(1200.0);

    expect(PipelineFingerprint::for($slower))->not->toBe(PipelineFingerprint::for($base));
});

it('does NOT change when only an env VALUE changes', function (): void {
    // The false-failure guard, and the reason env values are out. `withEnv()`
    // resolves its array when the config loads, so a consumer writing
    // `->withEnv(['TOKEN' => getenv('TOKEN')])` bakes a process-specific value
    // into the declaration. Hashing it would make two shells disagree about an
    // identical config, failing a gate with nothing wrong.
    $one = fingerprintOf(function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan')->withEnv(['TOKEN' => 'aaa']));
    });

    $two = fingerprintOf(function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan')->withEnv(['TOKEN' => 'bbb']));
    });

    expect($one)->toBe($two);
});

it('does NOT change when a Shell description is set without touching the command', function (): void {
    // A description is a label. The command is what runs, and it is read directly
    // so that a custom description cannot hide a change to it.
    $labelled = fingerprintOf(function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan', description: 'Static analysis'));
    });

    $bare = fingerprintOf(function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan'));
    });

    expect($labelled)->toBe($bare);
});

it('reaches the walk, and every scope of one pipeline carries the same digest', function (): void {
    // The walk carries the digest but must never compute it. A digest derived from
    // the walk's own steps would describe the SELECTED scope, so a scoped run would
    // record something no unscoped comparison could ever match.
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->tagged('backend'));
        $steps->in(StaticAnalysis::class)->append(Shell::run('tsc', id: 'tsc')->tagged('frontend'));
    });

    $whole = $pipeline->walk();
    $backend = $pipeline->walk('backend');

    expect($backend->steps)->toHaveCount(1)
        ->and($whole->steps)->toHaveCount(2)
        ->and($backend->configDigest)->toBe($whole->configDigest)
        ->and($whole->configDigest)->toBe(PipelineFingerprint::for($pipeline));
});

it('records the digest on the receipt, for a scoped run as much as a whole one', function (): void {
    $pipeline = Pipeline::configure()->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('true', id: 'pint')->tagged('backend'));
        $steps->in(StaticAnalysis::class)->append(Shell::run('true', id: 'tsc')->tagged('frontend'));
    });

    $runner = new class implements StepRunner
    {
        public function run(Step $step, string $runId): Result
        {
            return Result::passed($step->id(), 'ok');
        }
    };

    $store = new class implements ReceiptStore
    {
        public ?Receipt $receipt = null;

        public function write(Receipt $receipt): void
        {
            $this->receipt = $receipt;
        }

        public function read(): ?Receipt
        {
            return $this->receipt;
        }
    };

    $run = Run::start($pipeline->walk('backend'), $runner, 'r-scoped', receipts: $store, scope: 'backend');
    $run->resolveCurrent();

    expect($store->read()?->config)->toBe(PipelineFingerprint::for($pipeline));
});

it('round-trips the digest, and reads a receipt written before it existed as unknown', function (): void {
    $receipt = new Receipt(
        runId: 'r-round',
        state: 'complete',
        allVerified: true,
        tree: 'tree-a',
        stale: null,
        verdicts: ['pint' => 'passed'],
        recordedAt: '2026-01-01T00:00:00+00:00',
        config: 'digest-abc',
    );

    $written = $receipt->toArray();

    expect($written['config'])->toBe('digest-abc')
        ->and(Receipt::fromArray($written)?->config)->toBe('digest-abc');

    // A receipt from before the field. Absent must parse as null rather than
    // failing the whole receipt, or upgrading would make every past run
    // unreadable rather than merely unknown.
    $legacy = $written;
    unset($legacy['config']);

    expect(Receipt::fromArray($legacy))->not->toBeNull()
        ->and(Receipt::fromArray($legacy)?->config)->toBeNull();
});

it('refuses a receipt whose digest is not a string', function (): void {
    // `fieldsAreWellFormed()` validates from an explicit key list, so a new key
    // that is not added to it would be silently dropped instead of refused — and a
    // corrupt digest would then read as "unknown", which this design treats as
    // benign.
    $receipt = new Receipt(
        runId: 'r-bad',
        state: 'complete',
        allVerified: true,
        tree: 'tree-a',
        stale: null,
        verdicts: ['pint' => 'passed'],
        recordedAt: '2026-01-01T00:00:00+00:00',
    );

    $corrupt = $receipt->toArray();
    $corrupt['config'] = ['not', 'a', 'string'];

    expect(Receipt::fromArray($corrupt))->toBeNull();
});

it('does not depend on serialize_precision, so two php.ini settings agree', function (): void {
    // `serialize()` renders a float per `serialize_precision`, an ini setting. The
    // server process and the process that compares digests need not share a
    // php.ini, so hashing a raw float would let an identical static config produce
    // two digests and fail a gate with nothing wrong.
    // 0.1, not 0.5. A value that is exactly representable in binary serialises
    // identically at any precision, so it cannot expose this — the first version of
    // this test used 0.5 and passed against the very bug it was written for.
    $declare = function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan')->timeout(0.1));
    };

    $original = ini_get('serialize_precision');

    try {
        ini_set('serialize_precision', '17');
        $high = fingerprintOf($declare);

        ini_set('serialize_precision', '6');
        $low = fingerprintOf($declare);

        expect($high)->toBe($low);
    } finally {
        ini_set('serialize_precision', $original === false ? '-1' : $original);
    }
});

it('still notices a changed fractional timeout', function (): void {
    // The guard against fixing the above by dropping precision altogether.
    $half = fingerprintOf(function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan')->timeout(0.5));
    });

    $quarter = fingerprintOf(function (Steps $steps): void {
        $steps->in(StaticAnalysis::class)->append(Shell::run('phpstan', id: 'phpstan')->timeout(0.25));
    });

    expect($half)->not->toBe($quarter);
});

it('treats reordered tags as the same declaration', function (): void {
    // Selection tests membership, so tag order changes nothing a run would do.
    // Reporting a mismatch for it would be a false failure.
    $one = fingerprintOf(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->tagged('backend', 'fast'));
    });

    $two = fingerprintOf(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->tagged('fast', 'backend'));
    });

    expect($one)->toBe($two);
});

it('still notices a tag added or removed', function (): void {
    $both = fingerprintOf(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->tagged('backend', 'fast'));
    });

    $one = fingerprintOf(function (Steps $steps): void {
        $steps->in(Formatting::class)->append(Shell::run('pint', id: 'pint')->tagged('backend'));
    });

    expect($both)->not->toBe($one);
});
