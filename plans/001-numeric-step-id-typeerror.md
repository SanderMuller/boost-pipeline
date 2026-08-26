# Plan 001: Make a numeric step id survive the batch runner and the staleness check

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. Read `.ai/docs/invariants.md` before starting —
> the repo requires it before any change under `src/Run/` or `src/Runner/`.
> When done, update the status row for this plan in `plans/README.md` —
> unless a reviewer dispatched you and told you they maintain the index.
>
> **Drift check (run first)**: `git diff --stat a05b7fa..HEAD -- src/Runner/ProcessStepRunner.php src/Run/Run.php tests/Unit/ProcessStepRunnerTest.php tests/Feature/ParallelExecutionTest.php tests/Unit/RunStalenessTest.php`
> If any in-scope file changed since this plan was written, compare the
> "Current state" excerpts against the live code before proceeding; on a
> mismatch, treat it as a STOP condition.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `a05b7fa`, 2026-08-25

## Why this matters

A step id is any string the pipeline config passes to `Shell::run(id: ...)`. The codebase explicitly declares a numeric string (`'123'`, `'0'`) a legal id: `src/Run/Receipt.php:217-220` casts it back after PHP's array-key coercion, and `tests/Unit/ReceiptStoreTest.php` has a test named "keeps a numeric step id through the round trip". Two other code paths do not apply that cast. Both crash with an uncaught `TypeError` under `declare(strict_types=1)`. The worse of the two fires inside the staleness path, so the run becomes unreadable exactly when it must report that it went stale, and the receipt for that resolution is never written.

## Current state

PHP coerces a numeric-string array key to `int`. Any `foreach` over an array keyed by step id therefore yields an `int` key for an id like `'123'`, and passing that key to a `string`-typed parameter throws under strict types.

**Crash site 1** — `src/Runner/ProcessStepRunner.php:95-105`:

```php
            $pending[$step->id()] = [$process, $step, $scope, $timeout];
        }

        // Everything is already running, so waiting in order costs nothing beyond
        // the slowest step.
        foreach ($pending as $id => [$process, $step, $scope, $timeout]) {
            $timedOut = $this->settle($process, $id, $timeout);
```

`settle` is typed `string` at `src/Runner/ProcessStepRunner.php:226`:

```php
    private function settle(Process $process, string $stepId, float $timeout): ?Result
```

So any parallel group containing a numeric-id step throws `TypeError` when `runBatch` settles it.

**Crash site 2** — `src/Run/Run.php:409-421` (`staleGiven`; excerpt ABRIDGED — the `sprintf` message text is elided, only the loop head and the `isGrouped` call are load-bearing for the drift comparison):

```php
        foreach ($this->measuredAt as $stepId => $measuredAt) {
            if ($measuredAt !== null && $measuredAt !== $now) {
                return sprintf(
                    'Step [%s] measured a different working tree ...',
                    $stepId,
                    ...
                    $this->walk->isGrouped($stepId)
```

`Walk::isGrouped` is typed `string` at `src/Walk/Walk.php:183`:

```php
    public function isGrouped(string $stepId): bool
```

`$this->measuredAt` is written keyed by `$step->id()` at `src/Run/Run.php:499`, so a numeric id becomes an `int` key there too. Its property docblock at `src/Run/Run.php:51` says `@var array<string, string|null>` — which is WRONG at runtime for a numeric id (the key is `int`) and matters for the fix: PHPStan and Rector trust that docblock, infer the key as already-`string`, and Rector's `deadCode` set (`rector.php` line 29) would propose REMOVING a plain `(string)` cast as redundant — failing `composer qa-check`, whose first command is `rector process --dry-run`. The crash fires on the first staleness evaluation after the tree moves past a numeric-id step's measurement — thrown out of `Run::verification()`, which every tool response builds via `StepPayload::envelope()`.

**The repo's own precedent for the fix** — `src/Run/Receipt.php:217-220`:

```php
            // A step id of "123" arrives as an int, because PHP coerces
            // numeric-string array keys. Nothing forbids that id, so cast it back
            // rather than rejecting a legal config.
            $verdicts[(string) $stepId] = $verdict;
```

Match this: cast the key back to `string` where it is consumed. Do not forbid numeric ids — that would contradict `Receipt` and its test.

Reading an int-keyed array with a string key (`$results[$step->id()]`) is not affected — PHP coerces on read the same way — so only the two `foreach` sites need work.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full gate | `composer qa-check` | exit 0 (rector dry-run, pint --test, phpstan, pest all clean) |
| Tests only | `vendor/bin/pest` | all pass |
| One file | `vendor/bin/pest tests/Feature/ParallelExecutionTest.php tests/Unit/RunStalenessTest.php` | all pass |
| Static analysis | `composer phpstan` | 0 errors |

## Scope

**In scope** (the only files you should modify):
- `src/Runner/ProcessStepRunner.php`
- `src/Run/Run.php`
- `tests/Feature/ParallelExecutionTest.php` (the only test file driving a real parallel group through `ProcessStepRunner` — the new batch test goes here)
- `tests/Unit/RunStalenessTest.php`
- `plans/README.md` (status row for this plan only)

**Out of scope** (do NOT touch, even though they look related):
- `src/Run/Receipt.php` — already handles the coercion correctly.
- `src/Config/*` — do not add validation rejecting numeric ids; they are legal.
- `src/Walk/Walk.php` — do not widen `isGrouped` to accept `int`; the cast belongs at the caller, keeping one canonical type inside `Walk`.
- `src/Mcp/StepPayload.php` — its `foreach ($run->results() as $stepId => $result)` (line 72) emits a numeric id as a JSON number (`123`, not `"123"`) in the status payload. Not a crash, and not fixed here; recorded as a known residue in the Maintenance notes.

## Git workflow

- Branch from `main`: `fix/numeric-step-id`
- Commit style: plain imperative sentence, no conventional-commit prefix (see `git log --oneline`, e.g. "Refuse a receipt that recorded no verdicts at all").
- Commit signing is enabled (`commit.gpgsign true`, ssh format). If signing fails, STOP and report — never commit unsigned.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Write the failing tests first

1. In `tests/Feature/ParallelExecutionTest.php` (no test in the repo calls `runBatch` directly — this is the only file driving a real parallel group through `ProcessStepRunner`; use its `runParallel(Closure $group, StepRunner $runner)` helper near line 46 and the `Shell::run('true', id: 'a')` pattern near lines 192-193), add a test: a parallel group containing a step with `id: '123'` settles without throwing and returns a verdict for id `'123'`.
2. In `tests/Unit/RunStalenessTest.php`, add a test: a run whose passed step has `id: '123'` reports a stale reason (not a `TypeError`) after the tree moves. Model the arrangement on the existing stale-after-edit cases in that file.

**Verify**: `vendor/bin/pest tests/Unit/RunStalenessTest.php` → the new test FAILS with `TypeError` (this proves the reproduction). Same for the batch test.

### Step 2: Fix `runBatch`

In `src/Runner/ProcessStepRunner.php`, key `$pending` by list index instead of by step id, and take the id from the tuple's `$step`:

```php
$pending[] = [$process, $step, $scope, $timeout];
...
foreach ($pending as [$process, $step, $scope, $timeout]) {
    $timedOut = $this->settle($process, $step->id(), $timeout);
    $results[$step->id()] = ...
```

The list-index rewrite is the ONLY sanctioned fix here. Do not use `(string) $id` casts in this method: PHPStan infers `$pending`'s keys as `string` (from `Step::id(): string`), so Rector's `deadCode` set proposes removing the cast as redundant and `composer qa-check` fails on its first command.

**Verify**: the batch test from step 1 now passes.

### Step 3: Fix `staleGiven`

Two edits, in this order — the docblock first, or the analysers delete your cast:

1. Widen the property docblock at `src/Run/Run.php:51` from `@var array<string, string|null>` to `@var array<array-key, string|null>`, adding one comment line: a numeric-string id coerces to an `int` key, so the key type is `array-key`, not `string`. (Without this, PHPStan believes the key is already `string` and Rector's `deadCode` set removes the cast below as redundant.)
2. In `staleGiven` at `src/Run/Run.php:409`, cast inside the loop before use, mirroring the `Receipt.php:220` comment style:

```php
foreach ($this->measuredAt as $stepId => $measuredAt) {
    // A step id of "123" arrives as an int, because PHP coerces
    // numeric-string array keys. Cast it back rather than crash on a legal id.
    $stepId = (string) $stepId;
```

**Verify**: `vendor/bin/pest tests/Unit/RunStalenessTest.php` → all pass. Then `vendor/bin/rector process --dry-run` → proposes no change in `src/Run/Run.php` (the cast survives).

### Step 4: Sweep for other id-keyed foreach sites

Run: `grep -n 'as \$stepId\|as \$id' src/ -r`. Confirmed at planning time — the grep hits reconcile as follows: `Receipt.php:207,237` already cast; `VerifyCommand.php:255,399` interpolate the key into a message only (no typed call — int is fine in `sprintf`); `StepPayload.php:72` emits the key into the payload without a typed call — no crash, deliberately out of scope (see Scope and Maintenance notes); the two fix sites are handled in steps 2-3. If the grep shows a site NOT in this list, it was created since `a05b7fa` — apply the same cast there and add it to the commit.

**Verify**: `composer phpstan` → 0 errors.

## Test plan

- New test: parallel group with a numeric-id step settles and reports (in the existing runner/parallel test file).
- New test: stale run with a numeric-id step reports the stale message naming `[123]` (in `RunStalenessTest.php`).
- Pattern to follow: existing cases in `tests/Unit/RunStalenessTest.php`; the JSON-literal trick for numeric keys is shown in `tests/Unit/ReceiptStoreTest.php` ("keeps a numeric step id through the round trip") — for config-built steps you can pass `id: '123'` directly, coercion happens at the array, not the constructor.
- Verification: `vendor/bin/pest` → all pass, including 2 new tests.

## Done criteria

- [ ] `composer qa-check` exits 0
- [ ] The two new tests exist and pass. Proof they pin the bug: `git stash push -- src/ && vendor/bin/pest tests/Feature/ParallelExecutionTest.php tests/Unit/RunStalenessTest.php; git stash pop` → the two new tests fail with `TypeError`, the rest pass
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The excerpts above do not match the live code (drift since `a05b7fa`).
- The staleness test cannot reproduce the `TypeError` — the arrangement may be wrong; report what you observed instead of changing `src/` speculatively.
- The fix appears to require changing `Walk::isGrouped`'s signature or forbidding numeric ids in config — both are out of scope by decision.
- Your change would alter WHERE the cursor advances or WHAT a verdict means (see `.ai/docs/invariants.md`) — this plan is a key-type fix only.
- `rector process --dry-run` still proposes removing the cast after the docblock widening in step 3 — report the proposed diff instead of fighting the tool.

## Maintenance notes

- Any future map keyed by `$step->id()` inherits this trap. The safe patterns are: key by list index and carry the step, or cast the key back at every consumption site with the standard comment.
- Reviewer should scrutinize: that step 2 preserves result ordering (the `$ordered` rebuild after the settle loop keys results by id — reading with a string key on an int key still resolves, but confirm the batch test asserts the verdict is retrievable by `'123'`).
- Known residue, deliberately not fixed here: `src/Mcp/StepPayload.php:72` emits a numeric step id as a JSON number (`123`, not `"123"`) in the status payload. No crash, but a strict client comparing ids as strings would miss it. Fix alongside any future payload-shape work.
