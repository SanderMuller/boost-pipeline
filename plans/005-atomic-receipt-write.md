# Plan 005: Write the receipt atomically so a concurrent reader cannot see a torn file

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. Read `.ai/docs/invariants.md` before starting —
> `.ai/docs/README.md` requires it before touching `src/Run/`. When done,
> update the status row for this plan in `plans/README.md` — unless a
> reviewer dispatched you and told you they maintain the index.
>
> **Drift check (run first)**: `git diff --stat 334831e..HEAD -- src/Run/JsonReceiptStore.php tests/Unit/ReceiptStoreTest.php`
> On any change since this plan was written, compare the "Current state"
> excerpt against the live code; on a mismatch, STOP.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `334831e`, 2026-08-27 (refreshed; originally planned at `a05b7fa`)

> **Refreshed 2026-08-27.** Re-verified against `origin/main` (`334831e`): the
> finding still stands. `write()` is byte-identical to when this plan was
> written — only its line numbers moved (22-36 → 32-46) because a
> `LEGACY_PATH` constant was added above the constructor in 0.10.0.

## Why this matters

The receipt is rewritten in place after every resolution (`Run::recordReceipt()` runs at the end of every `record()`), while `pipeline:verify` reads the same file from a separate process — a CI gate, a pre-push hook — with no coordination. A read that lands mid-write gets truncated JSON; `JsonReceiptStore::read()` then returns `null` and the gate reports "No pipeline run has been recorded". It fails closed (a spurious red, never a false green), but it is a flaky gate whose cause is invisible from the message. A same-directory temp file plus `rename()` makes the swap atomic on POSIX filesystems.

## Current state

`src/Run/JsonReceiptStore.php:32-46` (the write path):

```php
    public function write(Receipt $receipt): void
    {
        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, recursive: true) && ! is_dir($directory)) {
            // Losing the receipt must not turn a real verdict into an error.
            return;
        }

        $json = json_encode($receipt->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json !== false) {
            @file_put_contents($this->path, $json.PHP_EOL);
        }
    }
```

Semantics that must be preserved exactly:

- Every failure is silent (`@`, early `return`) — "Losing the receipt must not turn a real verdict into an error."
- The final file content is `$json.PHP_EOL`.
- `read()` (same file, below `write()`) must be untouched.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full gate | `composer qa-check` | exit 0 |
| One file | `vendor/bin/pest tests/Unit/ReceiptStoreTest.php` | all pass |

## Scope

**In scope** (the only files you should modify):
- `src/Run/JsonReceiptStore.php` (the `write()` method only)
- `tests/Unit/ReceiptStoreTest.php`
- `plans/README.md` (status row for this plan only)

**Out of scope** (do NOT touch, even though they look related):
- `src/Run/Run.php` — the write cadence (every resolution) is deliberate; see the comment above `recordReceipt()` in `record()`.
- `src/Console/VerifyCommand.php` — the reader stays ignorant of the write mechanics.
- `JsonReceiptStore::LEGACY_PATH` — a constant added in 0.10.0 above the constructor, read by `VerifyCommand` to explain a moved receipt. Nothing to do with the write path.
- Any locking scheme — concurrent *writers* are a settled non-goal (README: "No lock; two agents on one server share a cursor"). This plan fixes torn *reads* only.

## Git workflow

- Branch from `main`: `fix/atomic-receipt-write`
- Commit style: plain imperative sentence (see `git log --oneline`).
- Commit signing is enabled (ssh). If signing fails, STOP — never commit unsigned.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Switch the write to temp-plus-rename

Replace the `file_put_contents` block:

```php
        if ($json === false) {
            return;
        }

        // A gate reads this file from another process with no coordination, so
        // the swap must be atomic: write beside the target, then rename. A
        // reader sees the old receipt or the new one, never a truncated one.
        $temp = $this->path.'.tmp';

        if (@file_put_contents($temp, $json.PHP_EOL) === false) {
            return;
        }

        if (! @rename($temp, $this->path)) {
            @unlink($temp);
        }
```

Constraints: the temp file lives in the SAME directory as the target (`rename` is only atomic within one filesystem), and every failure path stays silent. A fixed `.tmp` suffix is acceptable — concurrent writers are a non-goal (see Scope) — but note it in the comment only if you deviate.

**Verify**: `vendor/bin/pest tests/Unit/ReceiptStoreTest.php` → all existing tests pass unchanged (round-trip behaviour is identical).

### Step 2: Pin the new mechanics with tests

In `tests/Unit/ReceiptStoreTest.php` (follow its existing temp-path setup):

1. After a successful `write()`, assert the target file exists AND no `.tmp` sibling remains.
2. Overwrite case: `write()` twice with different receipts; assert `read()` returns the second and no `.tmp` remains.
3. Unwritable directory: `chmod` the receipt directory to `0500`, call `write()`, assert no throw and no partial/`.tmp` file. Restore with `chmod($dir, 0700)` at the end of the test body, and widen the existing `afterEach` guard (it is `if (is_file($this->path))` today) to clean by directory: delete `receipt.json` and any `.tmp` when `is_dir(dirname($this->path))`, then `rmdir`. Guard against root with exactly `->skip(fn (): bool => ! function_exists('posix_geteuid') || posix_geteuid() === 0, 'Root ignores directory modes.');` (same guard as plan 003).

**Verify**: `vendor/bin/pest tests/Unit/ReceiptStoreTest.php` → all pass, including 3 new tests.

## Test plan

Covered in step 2; pattern is the existing `ReceiptStoreTest.php` cases. A true torn-read race cannot be tested deterministically — the tests pin the mechanics (temp cleanup, atom swap end state) instead. Verification: `vendor/bin/pest` → all pass.

## Done criteria

- [ ] `composer qa-check` exits 0
- [ ] `grep -n 'rename' src/Run/JsonReceiptStore.php` shows the temp-plus-rename swap
- [ ] The 3 new tests pass, and each asserts `expect(is_file($this->path.'.tmp'))->toBeFalse()` as its final check (receipts are written under `sys_get_temp_dir()` in this suite, so an external `find` proves nothing)
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The live `write()` no longer matches the excerpt (drift).
- You find a caller that depends on the receipt being written in place (inode stability, a watcher on the exact file) — none is known; finding one changes the design question.
- Your change would alter WHAT is written or WHEN (see `.ai/docs/invariants.md`) — this plan changes only HOW the bytes land.

(Verified at planning time: no disallowed-calls ruleset in `phpstan.neon.dist` lists `rename` or `unlink`, and the baseline is empty — analyser pushback is not expected.)

## Maintenance notes

- One deliberate semantics shift the "preserved exactly" list does not cover: the in-place write succeeded when `receipt.json` was writable even in an unwritable directory; temp-plus-rename needs write on the DIRECTORY, so that corner now fails silently and the old receipt survives. Still fail-closed — the surviving receipt records its tree fingerprint, so the gate reports it stale rather than fresh.
- The `.tmp` sibling lands under `storage/logs/pipeline/` in a consumer app, which Laravel's `storage/logs/.gitignore` already covers — no new ignore rule needed.
- If receipts ever move to a network filesystem where `rename` atomicity is weaker, this needs revisiting — note is theoretical, receipts live in `storage/logs/pipeline/`.
- Reviewer should scrutinize: failure paths stay silent, temp file is always cleaned, and content still ends with `PHP_EOL`.
