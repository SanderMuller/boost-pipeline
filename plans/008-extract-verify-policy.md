# Plan 008: Extract the receipt-acceptance policy out of `VerifyCommand`

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. Read `.ai/docs/invariants.md` before starting:
> this plan moves the logic that decides whether a run may be called verified,
> and that document lists the ways that answer has been wrong before. When
> done, update the status row for this plan in `plans/README.md` — unless a
> reviewer dispatched you and told you they maintain the index.
>
> **Drift check (run first)**: `git diff --stat 334831e..HEAD -- src/Console/VerifyCommand.php src/Run/Receipt.php tests/Feature/VerifyCommandTest.php`
> If any of these changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch,
> treat it as a STOP condition.

## Status

- **Priority**: P2
- **Effort**: M
- **Risk**: MED — this is the exit-code contract; a reordered guard changes which message a caller sees
- **Depends on**: none
- **Blocks**: plans/007-receipt-read-path-spike.md
- **Category**: tech-debt
- **Planned at**: commit `334831e`, 2026-08-27

## Why this matters

"Does this receipt verify this tree?" is answerable today only by shelling out to `pipeline:verify` and reading an exit code. The rules that decide it — nine guards across four private methods — exist only as branches that print a message and `return self::FAILURE`. Nothing in-process can ask the question: not a future MCP tool, not a programmatic API, not a unit test. Anything that wants the answer must re-derive the guards, and a re-derivation that drops one produces a false green in the one place this package exists to prevent one.

It also makes the rules testable only through command output. `tests/Feature/VerifyCommandTest.php` is 924 lines — the largest test surface in the repo — because every guard has to be exercised by booting an artisan command and matching printed prose.

This is also the blocker under plan 007. That plan prototypes `pipeline:verify --json`, and its own step 2 concedes the prototype will duplicate guard logic unless a policy object exists first. With one, `--json` becomes a serializer over a returned result instead of a second copy of the rules.

## Current state

`src/Console/VerifyCommand.php` is 445 lines. `handle()` runs the guards in a fixed order, and **the order is load-bearing** — the empty-verdicts check carries a comment saying so explicitly:

```php
        // Ahead of the tree, staleness and scope checks, which would otherwise
        // answer first and report that an empty receipt verified a different
        // tree. It verified no tree.
        if ($receipt->verdicts === []) {
```

### The full guard inventory (in execution order)

Everything below is in `src/Console/VerifyCommand.php`. Each row is a terminal answer.

| # | Where | Condition | Result |
|---|---|---|---|
| A | `storeFor()` :159-173 | no `--pipeline` and the project declares several | FAILURE, names them |
| B | `storeFor()` :178-186 | `--pipeline` names one that is not configured | FAILURE |
| C | `handle()` :48-52 → `nothingRecorded()` :127 | store returns no receipt | FAILURE, two message shapes: plain, or a pre-0.10.0 file still at `JsonReceiptStore::LEGACY_PATH` |
| D | `handle()` :65-72 | `$receipt->verdicts === []` | FAILURE |
| E | `handle()` :76-83 | `$now`, `$receipt->tree` both non-null and different | FAILURE |
| F | `handle()` :85-89 | `$receipt->stale !== null` | FAILURE, prints the receipt's own message |
| G | `handle()` :91-97 → `scopeMismatch()` :362 | scoped run vs the scope asked (two shapes) | FAILURE |
| H | `answerServerVerified()` :210-220 | `--server-verified` and no usable fingerprint (two shapes) | FAILURE |
| I | `answerServerVerified()` :225-237 | `coverage !== 'complete'` (two shapes: null, incomplete) | FAILURE |
| J | `answerServerVerified()` :242-250 | `state !== RunState::Complete->value` | FAILURE |
| K | `answerServerVerified()` :260-268 | every verdict was an acknowledgement | FAILURE |
| L | `answerServerVerified()` :275-290 | some server verdict is not a pass | FAILURE, names them |
| M | `reportAssertions()` :310-317 | `$receipt->asserted === null` | FAILURE |
| N | `reportAssertions()` :321-329 | nothing that passed also asserted | FAILURE |
| O | `reportAssertions()` :334-351 | — | SUCCESS, rich message |
| P | `handle()` :103-107 → `explainUnverified()` :403 | not `--server-verified` and `! $receipt->allVerified` (two shapes) | FAILURE |
| Q | `handle()` :109-116 | — | SUCCESS, message |

### Two inputs currently read from the command

`scopeMismatch()` reads the option directly at :364-365:

```php
        $asked = $this->option('only');
        $asked = is_string($asked) && trim($asked) !== '' ? $asked : null;
```

`storeFor()` reads `$this->option('pipeline')` at :156-157 in the same shape. `handle()` reads `$this->option('server-verified')` at :99. These have to become parameters — a policy object must not reach for `$this->option()`.

### The boundary this plan draws

**In the policy**: guards D through Q — everything that judges a `Receipt` you already hold.

**Left in the command**: guards A, B and C. They decide *which* receipt to read and handle its absence; A and B need `Pipelines` / `ReceiptStoreFactory`, and C needs `$this->laravel->storagePath()`. Dragging container access into a policy object would defeat the point.

**But the vocabulary covers all of them.** The outcome enum (step 1) names A, B and C as well, and the command maps its own three refusals onto it. Plan 007 needs one vocabulary covering every terminal answer; splitting it across two type systems would push that problem downstream.

## The constraint that makes this safe

**Every message and every exit code must come out byte-identical.** `tests/Feature/VerifyCommandTest.php` asserts on printed prose across ~80 cases; it is the proof that the refactor preserved behaviour.

**That file must not be modified.** If you find yourself needing to change it, the refactor changed behaviour — that is a STOP condition, not something to accommodate. It boots the real command through `Artisan::call` with bound test doubles (`receiptStoreHolding()` at :25, `useReceiptStore()` at :57), so it exercises the whole path and does not care how the internals are arranged.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full gate | `composer qa-check` | exit 0 |
| The safety net | `vendor/bin/pest tests/Feature/VerifyCommandTest.php` | all pass, unmodified |
| New unit tests | `vendor/bin/pest tests/Unit/VerifyPolicyTest.php` | all pass |
| Static analysis | `composer phpstan` | 0 errors |

## Scope

**In scope**:
- `src/Console/VerifyCommand.php` — becomes option parsing, store selection, one policy call, and `info`/`error`
- `src/Run/VerifyOutcome.php` (create) — the outcome enum
- `src/Run/VerifyResult.php` (create) — the returned value object
- `src/Run/VerifyPolicy.php` (create) — the guards
- `tests/Unit/VerifyPolicyTest.php` (create)
- `plans/README.md` (status row for this plan only)

Placement note: `src/Run/` holds `Receipt`, `JsonReceiptStore` and `ReceiptStoreFactory`, so a type that judges a receipt belongs beside them. Do not invent a new top-level directory.

**Out of scope** (do NOT touch):
- `tests/Feature/VerifyCommandTest.php` — the safety net; see the constraint above.
- `src/Run/Receipt.php` — no new keys, no parsing changes. This plan moves rules, it does not change what a receipt holds.
- The `$signature` / `$description` on the command — the CLI surface is unchanged.
- Any message text, and the order the guards run in. Both are the contract.
- `--json` or any structured output — that is plan 007, and it comes after this.

## Git workflow

- Branch from `main`: `refactor/verify-policy`
- Commit style: plain imperative sentence, no conventional-commit prefix (see `git log --oneline`).
- Commit signing is enabled (`commit.gpgsign true`, ssh format). If signing fails, STOP and report — never commit unsigned.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Name every outcome

Create `src/Run/VerifyOutcome.php` — a backed string enum with one case per terminal answer in the guard inventory, including the three the command keeps (A, B, C). Suggested names, which plan 007 will reuse as its JSON `category` values:

`no_pipeline_selected` (A), `unknown_pipeline` (B), `no_run` (C plain), `no_run_legacy_receipt` (C legacy), `empty_verdicts` (D), `tree_mismatch` (E), `self_stale` (F), `scope_mismatch` (G), `no_fingerprint` (H), `incomplete_coverage` (I), `run_not_complete` (J), `all_acknowledged` (K), `server_verdict_failed` (L), `asserted_unknown` (M), `nothing_asserted` (N), `verified_server` (O), `not_all_verified` (P), `verified` (Q).

Give it a `passed(): bool` method returning true only for `verified` and `verified_server`. Cases that today carry two message shapes (C, G, H, I, P) stay ONE case each — the shape difference is in the message, not the outcome.

**Verify**: `composer phpstan` → 0 errors.

### Step 2: Define the result

Create `src/Run/VerifyResult.php`: a `final readonly` class holding `VerifyOutcome $outcome` and `string $message`. Add `passed(): bool` delegating to the outcome. Two named constructors keep call sites readable — for example `VerifyResult::failure(VerifyOutcome $outcome, string $message)` and `VerifyResult::success(VerifyOutcome $outcome, string $message)`.

Match the conventions in `src/Results/Result.php` — read it first for the repo's value-object style (constructor promotion, `final readonly`, named constructors).

**Verify**: `composer phpstan` → 0 errors.

### Step 3: Move the guards

Create `src/Run/VerifyPolicy.php` (`final readonly`) with one entry point:

```php
public function judge(
    Receipt $receipt,
    ?string $now,
    ?string $scopeAsked,
    bool $serverVerified,
): VerifyResult
```

Move guards D through Q into it, **in exactly the order the inventory lists them**, with their `sprintf` message construction copied verbatim. `scopeMismatch()`, `answerServerVerified()`, `reportAssertions()` and `explainUnverified()` move across as private methods; they return `VerifyResult` (or a message string, as they do today) instead of printing and returning an int.

Carry the explanatory docblocks and inline comments across with the code they explain. They document why each guard exists and why the order holds — they are the most valuable thing in this file and must not be lost in the move.

`$scopeAsked` replaces the `$this->option('only')` read; `$serverVerified` replaces `$this->option('server-verified')`.

**Verify**: `composer phpstan` → 0 errors. (Tests will not pass until step 4 — that is expected.)

### Step 4: Reduce the command to a caller

Rewrite `handle()` to: select the store (keep `storeFor()` as it is), read the receipt, handle absence via `nothingRecorded()`, capture the tree, then one call:

```php
$result = $policy->judge($receipt, $tree->capture(), $scopeAsked, $serverVerified);

$result->passed()
    ? $this->components->info($result->message)
    : $this->components->error($result->message);

return $result->passed() ? self::SUCCESS : self::FAILURE;
```

Take `VerifyPolicy` as a `handle()` parameter — Laravel resolves it from the container, matching how `Pipelines`, `ReceiptStoreFactory` and `TreeFingerprint` already arrive. It has no constructor dependencies, so no binding is needed in the service provider; do not add one.

Keep the class docblock on `VerifyCommand` (the "NO RUN IS A FAILURE" paragraph) — it explains the command's contract, which has not changed.

**Verify**: `vendor/bin/pest tests/Feature/VerifyCommandTest.php` → all pass, with the file unmodified. This is the whole proof of the refactor. Then `composer qa-check` → exit 0.

### Step 5: Unit-test the policy directly

Create `tests/Unit/VerifyPolicyTest.php`. Model the file header and construction style on `tests/Unit/ReceiptStoreTest.php` (a Unit test runs on bare PHPUnit — no `$this->app`, so build `Receipt` objects and the policy by hand).

One case per outcome the policy owns (D through Q), asserting on `outcome`, not on message text — the feature test already pins the prose, and duplicating those assertions here would mean every future wording change breaks two files. Include at least:

- an empty-verdicts receipt → `VerifyOutcome::EmptyVerdicts`
- a receipt whose `tree` differs from `$now` → `TreeMismatch`
- a receipt with `stale` set and a MATCHING tree → `SelfStale` (this is the case that proves the two are distinct, not one condition)
- a scoped receipt with `$scopeAsked = null` → `ScopeMismatch`, and the same receipt with a matching scope → passes on to a later outcome
- `$serverVerified = true` with `coverage` null → `IncompleteCoverage`
- `$serverVerified = true`, everything clean → `VerifiedServer`, `passed()` true
- a clean unscoped receipt, `$serverVerified = false` → `Verified`, `passed()` true

**Verify**: `vendor/bin/pest tests/Unit/VerifyPolicyTest.php` → all pass. Then `composer qa-check` → exit 0.

## Test plan

- New: `tests/Unit/VerifyPolicyTest.php`, one case per policy-owned outcome, asserting outcomes.
- Unchanged: `tests/Feature/VerifyCommandTest.php` — passing it untouched is the behaviour-preservation proof.
- Verification: `composer qa-check` → exit 0, suite count up by the number of new unit tests and no feature test removed.

## Done criteria

ALL must hold:

- [ ] `composer qa-check` exits 0
- [ ] `git diff --stat main..HEAD -- tests/Feature/VerifyCommandTest.php` is EMPTY (the safety net is unmodified)
- [ ] `src/Console/VerifyCommand.php` is under 200 lines (`wc -l`) — it was 445
- [ ] `grep -c 'components->error' src/Console/VerifyCommand.php` → at most 4 (guards A, B, C, and the single policy-failure branch)
- [ ] `grep -c "option('only')\|option('server-verified')" src/Run/VerifyPolicy.php` → 0 (the policy reaches for no options)
- [ ] `tests/Unit/VerifyPolicyTest.php` exists and every case asserts on a `VerifyOutcome`
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- Any excerpt in "Current state" does not match the live code (drift since `334831e`).
- **You need to modify `tests/Feature/VerifyCommandTest.php` to make it pass.** That means the refactor changed a message or an exit code. Report the exact assertion that failed and what the new output was — do not adjust the test.
- Making a guard fit the policy would require reordering the guards. The order is the contract; report which guard resisted and why.
- A message needs a value the policy has no access to. Report which — do not pass the `Command` or the container in to get it.
- Read `.ai/docs/invariants.md` first: if your arrangement would let an `error` verdict, an `acknowledged` step, or an incomplete run reach a `passed()` result, stop. Those are the invariants this file defends.

## Maintenance notes

- After this lands, "does this receipt verify this tree?" is answerable in-process by anything holding a `Receipt`. Plan 007 should be executed next: its `--json` prototype becomes a serializer over `VerifyResult` plus the outcome enum, rather than a second copy of the guards. Update 007's step 2 accordingly when you get there.
- A reviewer should scrutinise: guard order preserved exactly (compare against the inventory table above), messages byte-identical, and that the `VerifyOutcome` cases the command maps onto (A, B, C) are the same enum the policy returns — one vocabulary, not two.
- The enum is a near-public surface the moment plan 007 ships it as JSON `category` values. Naming is cheap to change now and expensive after. Push back on a name in review if it reads wrong.
- Deliberately not done here: no behaviour change, no new receipt keys, no `--json`. This plan is a pure move, and its value is entirely in being provably so.
