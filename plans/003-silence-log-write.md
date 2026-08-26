# Plan 003: Silence the log write so a failed write cannot discard a real verdict

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. Read `.ai/docs/invariants.md` before starting —
> `.ai/docs/README.md` requires it before touching `src/Runner/`. When done,
> update the status row for this plan in `plans/README.md` — unless a
> reviewer dispatched you and told you they maintain the index.
>
> **Drift check (run first)**: `git diff --stat a05b7fa..HEAD -- src/Runner/LogWriter.php tests/Unit/LogWriterTest.php`
> On any change to these files since this plan was written, compare the
> "Current state" excerpt against the live code; on a mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `a05b7fa`, 2026-08-25

## Why this matters

`LogWriter::write()` suppresses `mkdir` failure under the comment "Losing the log must not turn a real verdict into an error", but the `file_put_contents` two lines later is NOT suppressed. The call sits in `ProcessStepRunner::verdictFor()`, outside every `try`/`catch` in that class. An existing-but-unwritable log directory (read-only mount, wrong owner after a deploy, full disk) raises a warning that Laravel converts to an `ErrorException`. The step already ran and already has a verdict; that verdict is thrown away and the whole tool call fails — the exact outcome the comment says must not happen. The sibling `JsonReceiptStore::write()` shows the intended pattern: `@file_put_contents`.

## Current state

`src/Runner/LogWriter.php:14-29`:

```php
    public function write(string $runId, string $stepId, string $contents): ?string
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, recursive: true) && ! is_dir($this->directory)) {
            // Losing the log must not turn a real verdict into an error.
            return null;
        }

        $path = sprintf(
            '%s/%s-%s.log',
            rtrim($this->directory, '/'),
            $this->filenameSafe($runId),
            $this->filenameSafe($stepId),
        );

        return file_put_contents($path, $contents) === false ? null : $path;
    }
```

The pattern to match — `src/Run/JsonReceiptStore.php:34`:

```php
            @file_put_contents($this->path, $json.PHP_EOL);
```

Note: verified at planning time — the repo's `phpstan.neon.dist` includes only spaze's dangerous/execution/insecure disallowed-call sets, and none lists `file_put_contents` or the `@` operator; the baseline is empty. The `@` at `JsonReceiptStore.php:34` passes analysis today, so the same form here will too.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full gate | `composer qa-check` | exit 0 |
| One file | `vendor/bin/pest tests/Unit/LogWriterTest.php` | all pass |
| Static analysis | `composer phpstan` | 0 errors |

## Scope

**In scope** (the only files you should modify):
- `src/Runner/LogWriter.php`
- `tests/Unit/LogWriterTest.php`
- `plans/README.md` (status row for this plan only)

**Out of scope** (do NOT touch, even though they look related):
- `src/Runner/ProcessStepRunner.php` — do not wrap `verdictFor()` in a catch; the fix belongs at the write.
- `src/Runner/EnvironmentScrubber.php:57` — its unsilenced `file()` degrades correctly (it runs inside a path that `start()`/`process()` wrap in `catch (Throwable)`, producing an honest error verdict). Leave it.
- `src/Run/JsonReceiptStore.php` — already correct.

## Git workflow

- Branch from `main`: `fix/silence-log-write`
- Commit style: plain imperative sentence (see `git log --oneline`).
- Commit signing is enabled (ssh). If signing fails, STOP — never commit unsigned.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Write the failing test

In `tests/Unit/LogWriterTest.php` (read it first; follow its temp-dir setup), add a test: point a `LogWriter` at a directory that exists but is unwritable (`mkdir` it, then `chmod` it to `0500`), call `write()`, assert it returns `null` and does not throw. Restore with `chmod($dir, 0700)` as the last statement of the test body — the existing `afterEach` needs no change (no `.log` file is created, and removing the empty dir only needs write on its parent). Guard against root (root ignores modes) with exactly:

```php
->skip(fn (): bool => ! function_exists('posix_geteuid') || posix_geteuid() === 0, 'Root ignores directory modes.');
```

(The repo has no existing root-guard precedent; `posix_geteuid` is on no disallowed-call list — verified at planning time.)

**Verify**: `vendor/bin/pest tests/Unit/LogWriterTest.php` → the new test FAILS against the current code. Mechanism: `phpunit.xml` sets `failOnWarning="true"` and `<source restrictWarnings="true">` covering `src/`, so the `file_put_contents` warning fails the test (Unit tests run on bare PHPUnit — no Laravel `ErrorException` is involved).

### Step 2: Silence the write

Change line 28 to:

```php
        return @file_put_contents($path, $contents) === false ? null : $path;
    }
```

Keep the `=== false ? null : $path` shape unchanged.

**Verify**: `vendor/bin/pest tests/Unit/LogWriterTest.php` → all pass. Then `composer phpstan` → 0 errors.

## Test plan

- New test: unwritable directory → `write()` returns `null`, no throw (step 1).
- Existing tests in `LogWriterTest.php` keep passing (the happy path returns the path).
- Verification: `vendor/bin/pest` → all pass, including 1 new test.

## Done criteria

- [ ] `composer qa-check` exits 0
- [ ] The new unwritable-directory test exists; it fails when the `@` is removed
- [ ] `grep -c '@file_put_contents' src/Runner/LogWriter.php` → 1
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The live `LogWriter.php` no longer matches the excerpt.
- Any analyser rejects the `@` despite the verified-clean note in Current state — a rule was added since planning; report the exact rule name rather than adding a baseline entry or ignore comment.
- The chmod-based test proves flaky in this environment (for example, a filesystem that ignores modes) — report; do not ship a test that only passes sometimes.

## Maintenance notes

- The class now has one uniform failure answer: any write problem → `null`, verdict unaffected. Callers already handle `null` (the log path is optional in the verdict message).
- Reviewer should scrutinize: that nothing else in `verdictFor()`'s path can still throw on filesystem trouble.
- Deferred (recorded under "Audited, not planned" in plans/README.md): asserting at run level that a lost receipt write also leaves the verdict intact.
