<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\Run;
use Symfony\Component\Process\Process;

/**
 * The digest exists because TWO processes read the config.
 *
 * The server hashes a declaration when it records a run; the command hashes it
 * again, later, in a process of its own. Every other test here binds one
 * `Pipelines` into one container and asks one process — so the property the whole
 * feature rests on is the one nothing else can fail on.
 *
 * Two inputs have already had to leave the digest for exactly this reason: env
 * VALUES, because `withEnv()` resolves its array when the config loads, and float
 * precision, because `serialize()` renders floats per an ini setting. Both were
 * found by reading the code. Neither would have been caught by any test that
 * existed, and a third of its kind is likelier than not.
 *
 * The tree fingerprint is deliberately left out of these runs. A receipt that
 * records no tree skips the staleness check in the subprocess, which removes the
 * one variable this file is not about — the package's own working tree, which
 * other tests are free to move underneath it.
 */
function packageRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Whether a subprocess can be started here at all.
 *
 * A missing local toolchain is not a defect in the package, and a suite that
 * fails for one teaches people to ignore failures — so every test in this file
 * skips on it rather than failing.
 */
function cannotRunSubprocess(): bool
{
    return ! is_file(packageRoot().'/vendor/bin/testbench');
}

/**
 * Run `pipeline:verify` in a process of its own.
 *
 * @param  list<string>  $arguments
 * @return array{code: int|null, output: string}
 */
function verifyInSubprocess(array $arguments = []): array
{
    $process = new Process(
        [PHP_BINARY, 'vendor/bin/testbench', 'pipeline:verify', ...$arguments],
        packageRoot(),
    );

    // Explicit, so a subprocess that hangs surfaces as a thrown timeout rather
    // than a suite that never finishes. Well under any runner's patience.
    $process->setTimeout(60.0);
    $process->run();

    return [
        'code' => $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
    ];
}

/**
 * Declare one shell step, so a command change is a declaration change.
 *
 * The optional timeout is there to prove the gate answers "is this the
 * declaration you ran" and not "does the difference matter" — a timeout change
 * alters nothing a run would do, and is inside the digest all the same.
 */
function writeCrossProcessConfig(string $path, string $command, ?float $timeout = null): void
{
    $step = $timeout === null
        ? "Shell::run('{$command}', id: 'fmt')"
        : "Shell::run('{$command}', id: 'fmt')->timeout({$timeout})";

    $config = <<<PHP
        <?php

        declare(strict_types=1);

        use SanderMuller\BoostPipeline\Config\Pipeline;
        use SanderMuller\BoostPipeline\Phases\Defaults\Formatting;
        use SanderMuller\BoostPipeline\Phases\Steps;
        use SanderMuller\BoostPipeline\Steps\Shell;

        return Pipeline::configure()->withSteps(function (Steps \$steps): void {
            \$steps->in(Formatting::class)->append({$step});
        });
        PHP;

    file_put_contents($path, $config);
}

/**
 * Record a finished run against the config currently on disk.
 *
 * The pipeline comes from the file rather than from a literal, so the test
 * process and the subprocess are demonstrably hashing the same declaration —
 * which is the point, not an implementation detail.
 *
 * Paths are passed in rather than read from the test case: a plain function
 * reaching into `test()` cannot be typed, and an explicit parameter says what the
 * helper needs.
 */
function recordRunFromConfigFile(string $configPath, string $receiptPath): void
{
    /** @var Pipeline $pipeline */
    $pipeline = require $configPath;

    $run = Run::start(
        $pipeline->walk(),
        new class implements StepRunner
        {
            public function run(Step $step, string $runId): Result
            {
                return Result::passed($step->id(), 'ok');
            }
        },
        'r-xproc',
        receipts: new JsonReceiptStore($receiptPath),
    );

    $run->resolveCurrent();
}

beforeEach(function (): void {
    $this->configPath = app()->basePath(PipelineLoader::CONFIG_PATH);
    $this->receiptPath = storage_path('logs/pipeline/receipts/default.json');

    if (! is_dir(dirname($this->configPath))) {
        mkdir(dirname($this->configPath), recursive: true);
    }

    if (! is_dir(dirname($this->receiptPath))) {
        mkdir(dirname($this->receiptPath), recursive: true);
    }
});

afterEach(function (): void {
    // Both are shared, single paths that other suites also write. Leaving either
    // behind changes what an unrelated test resolves.
    foreach ([$this->configPath, $this->receiptPath] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('accepts a run in another process when the declaration has not moved', function (): void {
    writeCrossProcessConfig($this->configPath, 'true');
    recordRunFromConfigFile($this->configPath, $this->receiptPath);

    // Asserted before the subprocess runs, so a pass cannot come from an empty
    // receipt: the digest has to be there for the comparison to mean anything.
    $recorded = new JsonReceiptStore($this->receiptPath)->read();

    expect($recorded?->config)->not->toBeNull()
        ->and($recorded?->allVerified)->toBeTrue()
        ->and($recorded?->state)->toBe('complete');

    $result = verifyInSubprocess();

    // The subprocess loaded the config itself, hashed it in its own PHP, and
    // agreed. Nothing else in the suite can fail on that.
    expect($result['code'])->toBe(0);
})->skip(cannotRunSubprocess(...), 'vendor/bin/testbench is not installed, so no second process can be started');

it('refuses in another process when the declaration moved under the run', function (): void {
    writeCrossProcessConfig($this->configPath, 'true');
    recordRunFromConfigFile($this->configPath, $this->receiptPath);

    // The step keeps its id, so nothing about the step LIST is wrong. Only its
    // definition changed — the shape a server holding an older config produces.
    writeCrossProcessConfig($this->configPath, 'true --verbose');

    $result = verifyInSubprocess();

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('not the declaration')
        // It must not accuse the server. A config git cannot see, and a config that
        // computes part of itself at load time, produce the same mismatch — and the
        // last cannot be fixed by reconnecting anything.
        ->and($result['output'])->toContain('computes');
})->skip(cannotRunSubprocess(...), 'vendor/bin/testbench is not installed, so no second process can be started');

it('refuses an inert change too, because the question is which declaration ran', function (): void {
    writeCrossProcessConfig($this->configPath, 'true', 900.0);
    recordRunFromConfigFile($this->configPath, $this->receiptPath);

    // A timeout of 901 instead of 900 changes nothing a run would do. It is inside
    // the digest, so the run did not walk this declaration, and that is the whole
    // question the gate answers.
    writeCrossProcessConfig($this->configPath, 'true', 901.0);

    expect(verifyInSubprocess()['code'])->toBe(1);
})->skip(cannotRunSubprocess(...), 'vendor/bin/testbench is not installed, so no second process can be started');

it('passes again once the declaration is put back, so the refusal is not sticky', function (): void {
    writeCrossProcessConfig($this->configPath, 'true');
    recordRunFromConfigFile($this->configPath, $this->receiptPath);

    writeCrossProcessConfig($this->configPath, 'true --verbose');
    expect(verifyInSubprocess()['code'])->toBe(1);

    // Symmetric rather than sticky: nothing about the refusal is recorded, so
    // restoring the declaration restores the answer without a new run.
    writeCrossProcessConfig($this->configPath, 'true');
    expect(verifyInSubprocess()['code'])->toBe(0);
})->skip(cannotRunSubprocess(...), 'vendor/bin/testbench is not installed, so no second process can be started');

it('reports success from its own process, not merely exit 0', function (): void {
    writeCrossProcessConfig($this->configPath, 'true');
    recordRunFromConfigFile($this->configPath, $this->receiptPath);

    $result = verifyInSubprocess();

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('verified this tree');

    // `--server-verified` would name the step ids it counted, which would pin the
    // config identity more directly — but it refuses a receipt that records no tree
    // fingerprint, and these runs deliberately record none. Not a gap: exit 0
    // already carries the identity proof. It requires the digest the subprocess
    // computed from the file to equal the digest the test process computed from the
    // same file, and two different declarations do not produce one digest.
})->skip(cannotRunSubprocess(...), 'vendor/bin/testbench is not installed, so no second process can be started');
