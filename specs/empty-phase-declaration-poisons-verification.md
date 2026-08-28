# An empty phase declaration permanently poisons verification

<!-- spec:planned-at c1b3d7d6150c474a65527e02ffb8aa7c4ac6d537 2026-08-28 +uncommitted -->

## Overview

`Steps::in()` registers a phase on **access**, not on append. So `$steps->in(SomePhase::class);`
with nothing appended — or a loop that appends nothing because its input was empty — registers a
phase holding zero steps. If that phase is not also registered in the pipeline, the walk emits a
dropped-steps notice naming **no steps**, and that notice permanently forces `all_verified: false`
and `coverage: 'incomplete'`.

The run can never exit 0, and the message cannot tell the consumer what to fix, because nothing
was dropped.

## Assumptions

- **The fix filters on the FLATTENED step list, not `StepCollection::isEmpty()`.** `isEmpty()`
  tests `$this->entries === []` (`src/Phases/StepCollection.php:93-96`), so
  `parallel(fn () => null)` leaves one empty `StepBatch` in `entries` and `isEmpty()` returns
  `false` — missing the case. Verified; load-bearing, so it is also a STOP condition.
- **This does not weaken the "a declared gate never vanishes without trace" invariant.** There is
  no step to drop. Every phase holding at least one step still produces its notice with its ids,
  unchanged. Checked against `.ai/docs/invariants.md`.
- **`StepCollection::isEmpty()` has zero callers.** The conclusion holds, but an earlier draft
  stated the evidence wrongly ("the two `isEmpty()` hits are `Walk::isEmpty()`"). There are in fact
  FIVE hits across THREE classes: `Walk::isEmpty()` (`Walk.php:172`, called at
  `Run.php:93` and `PipelineConfigTest.php:47`), `StepBatch::isEmpty()` (`StepBatch.php:47`), and
  `StepCollection::isEmpty()` (`:93`). Only the last has no callers. **Note `StepBatch::isEmpty()`
  exists** — an implementer might reach for it by mistake; it is not the predicate you want
  either, for the same reason (`entries`-shaped, not flattened).
- **Reachability is narrow but real.** It needs an unregistered phase *and* zero steps. The
  realistic path is a loop-append over an empty collection into a phase the consumer forgot to
  register. Rated medium-confidence on reachability, high on mechanism.

---

## 1. Current state

The chain, traced end to end at `c1b3d7d`:

1. **`src/Phases/Steps.php:21`** — `return $this->inPhase[$phase] ??= new StepCollection;`
   Asking for a phase's collection registers the phase, whether or not anything is appended.

2. **`src/Phases/Steps.php:51`** — `return array_keys($this->inPhase);`
   `declaredPhases()` returns every key, including phases holding nothing. Its own docblock says
   *"Every phase that steps were declared into"* — which is not what it computes.

3. **`src/Walk/Walk.php:143-165`** — for each declared-but-unregistered phase it builds `$ids`
   from `forPhase()` and emits:
   ```
   Step(s) %s dropped: declared into phase [%s], which is not registered.
   ```
   With an empty collection `$ids` is the empty string, so the notice renders as
   `Step(s)  dropped: declared into phase [X], which is not registered.` — a dropped-steps
   notice naming no steps.

4. **`src/Run/Run.php:355`** — `if ($this->results === [] || $this->walk->notices !== [])`
   makes `all_verified` **false** for the whole run.

5. **`src/Run/Run.php:476`** — `coverage: $this->walk->notices === [] ? 'complete' : 'incomplete'`
   makes coverage **incomplete**, which `pipeline:verify --server-verified` refuses outright
   (`src/Console/VerifyCommand.php:225-237`).

The underlying representation problem: `$inPhase` conflates *"a consumer asked for this
collection"* with *"this phase has steps"*. Those are different facts, and only the second should
produce a notice.

## 2. Proposed changes

Make `declaredPhases()` compute what its docblock says. One line in `src/Phases/Steps.php`:

```php
public function declaredPhases(): array
{
    return array_keys(array_filter(
        $this->inPhase,
        // in() registers on access, so a phase whose collection was requested but
        // never appended to holds nothing. Nothing was declared into it, and a
        // dropped-steps notice naming no steps cannot be acted on.
        static fn (StepCollection $collection): bool => $collection->all() !== [],
    ));
}
```

`all()` flattens parallel groups; `entries` does not. That distinction is the whole fix — see
STOP condition 1.

Optionally, in the same change: correct `StepCollection::isEmpty()` to delegate to `all()` so its
one public claim is true. It has no callers, so this is safe but not required.

Deliberately **not** doing: emitting a different notice for "phase declared with no steps". That
is a behaviour addition, not a simplification, and it would re-introduce the `all_verified: false`
pin this spec exists to remove.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| `$steps->in(Unregistered::class);` with no append | No notice. `all_verified` and `coverage` unaffected. Covered by Tests. |
| `$steps->in(Unregistered::class)->parallel(fn () => null);` | No notice — this is the case `isEmpty()` would miss, because `entries` holds one empty `StepBatch`. Covered by Tests. |
| `$steps->in(Unregistered::class)->append(...)` with a real step | Notice still emitted, naming the step id, exactly as today. This must not regress. Covered by Tests. |
| Phase declared, empty, and **registered** | No notice today and none after — the notice only fires for unregistered phases. No change. |
| A phase with one real step and one empty parallel group | `all()` returns the one step, so the phase still counts as declared and still notices if unregistered. Correct — the `all()` predicate retains this case. |
| A run whose ONLY content is the empty declaration | `all_verified` is still false — but because `results === []`, not because of a notice. That is correct and unchanged behaviour. Tests must add a passing step to isolate the notice effect. |

## Implementation

- [x] Filter `declaredPhases()` in `src/Phases/Steps.php` on `$collection->all() !== []` — carry the comment explaining why `all()` and not `entries`.
- [x] Optionally correct `StepCollection::isEmpty()` (`src/Phases/StepCollection.php:93-96`) to delegate to `all()` — zero callers, so no risk; skip if you prefer a minimal diff. **Skipped, deliberately — see `## Findings`.**
- [x] Tests — two new cases: a bare `in(Unregistered::class)` and an `in(Unregistered::class)->parallel(fn () => null)`, each asserting `$walk->notices === []`.
- [x] Tests — **scaffolding note before writing these:** `Run::start()` takes `?ReceiptStore $receipts = null` (`src/Run/Run.php:103`) and `tests/Unit/RunTest.php` never passes one, so there is no in-memory receipt store available in that file. The only existing fake is `InMemoryReceipts`, a file-local `final class` in `tests/Unit/MultiPipelineRunsTest.php:36` — not reusable across files. Either build a local anonymous `ReceiptStore` in the new test, or assert `allVerified()` only and drop the receipt-coverage assertion. Do not add a shared double for this alone.
- [x] Tests — **each of those cases must ALSO declare a registered phase holding at least one step, and resolve it to a pass.** Then assert `allVerified()` is TRUE and the written receipt carries `coverage: 'complete'`. Without a resolved passing step the assertion proves nothing: `Run::verifiedGiven()` (`src/Run/Run.php:355`) returns false when `results === []` regardless of notices, so a notices-only assertion would pass or fail for the wrong reason.
- [x] Tests — a regression guard that the existing unregistered-phase notice still fires with its step ids named. `tests/Unit/RunTest.php:221` already covers this; assert it stays green.
- [x] Tests — mutation check per `.ai/docs/invariants.md`: make the filter too aggressive (drop non-empty phases as well), and confirm the `RunTest.php:221` case goes red. If it does not, the guard is not actually tested.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The filter must use `all()`, not `isEmpty()`/`entries`.** If you find yourself reaching for `StepCollection::isEmpty()` as the predicate, stop: `parallel(fn () => null)` leaves an empty `StepBatch` in `entries`, so `isEmpty()` returns `false` and the bug survives with a test that appears to pass.
2. **The existing notice for a populated unregistered phase must still fire, with its ids.** If the regression guard at `tests/Unit/RunTest.php:221` goes red, the filter is too wide — that notice is the "declared gate vanishing without trace" invariant and must not be weakened.
3. **`all_verified` must not become true for any run that has a real notice.** If any existing test asserting `all_verified: false` flips to true, stop — the filter has removed a notice that was doing its job.
4. **A new test asserts `allVerified()` is true without resolving a passing step.** Stop — `verifiedGiven()` returns false on empty results independently of notices, so such a test cannot distinguish the fix from the bug.

---

## Open Questions

None. The fix is one predicate, the evidence is traced end to end, and the two behaviours that
must not regress both have existing tests.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **`StepCollection::isEmpty()` left as it is.** The spec offered the correction as optional. It
  is skipped for a reason the spec did not state: `isEmpty()` is a `public` method on a class
  consumers configure pipelines with, so changing what it returns for a collection holding only
  empty parallel groups is a public-behaviour change — and it buys nothing, because the method has
  zero callers (verified across `src`, `tests`, `workbench` and `.config`; the other four
  `isEmpty()` hits are `Walk::isEmpty()`, called at `Run.php:93` and `PipelineConfigTest.php:47`,
  and `StepBatch::isEmpty()`, also uncalled). The trap the spec warns about is covered instead by
  the comment inside `declaredPhases()` and by STOP condition 1.

- **`declaredPhases()` has exactly one caller**, `Walk::noticesForUnregisteredPhases()`
  (`src/Walk/Walk.php:147`). Checked before editing, because the one-line fix changes the method
  for every consumer and the spec asserted the call site without asserting it was the only one.

- **The empty group is written as `function (StepCollection $steps): void {}`, not
  `fn () => null`.** The spec names the latter. `StepCollection::parallel()` declares
  `Closure(self): void`, so a closure returning `null` is a type mismatch PHPStan reports at
  `level: max`. The two are semantically identical here — an empty group either way.

- **The receipt double reads through the contract, not a public property.** The first draft
  exposed `public ?Receipt $receipt` and asserted on it. PHPStan rejected that: the helper's
  return type is `ReceiptStore`, which erases the anonymous class's own property
  (`property.notFound`). `ReceiptStore::read()` is already on the interface and returns the
  receipt, so the assertion uses that and the property is private.

- **Both proofs the spec asks for were run.**
  *Fail-first:* with `src/Phases/Steps.php` alone stashed, the two new no-notice tests fail on
  their notices assertion (14/16).
  *Mutation:* widening the filter to `static fn (...): bool => false` turns the existing guard
  *"refuses to claim all_verified when a declared step was dropped before the walk began"*
  (`tests/Unit/RunTest.php:215`) red, along with the new mixed-phase test — so the guard does pin
  the populated-phase notice.

- **Two tests beyond the checklist, one per uncovered Edge Cases row.** The row *"a phase with one
  real step and one empty parallel group"* had no task of its own; it is now *"still drops a phase
  that holds one real step and one empty parallel group"*, and the mutation check relies on it
  alongside the existing guard. The row *"a run whose ONLY content is the empty declaration"* is
  now *"does not turn a run that verified nothing into a green one"* — the walk is empty, so
  `Run::__construct` completes it immediately (`src/Run/Run.php:93-95`) and `allVerified()` stays
  false because `results === []`. That is the row that proves removing the notice did not buy a
  false green.
