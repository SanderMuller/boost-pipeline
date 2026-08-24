# Step tags and scoped runs

<!-- spec:planned-at 3cac619119d6ff33e3a171eb006bcee9263ca79f 2026-08-24 -->

## Overview

Let a step declare tags, and let `open_run` select one, so a change that touches only part of a
project runs only the steps that can say something about it. A backend-only run leaves the frontend
steps out of the walk entirely rather than halting on them.

The whole design problem is that a scoped run **deliberately omits declared gates**, which is the
one thing every other rule in this package exists to prevent. It is only safe if the omission is
recorded and a consumer can tell a scoped pass from a full one.

## Assumptions

<!-- Filled by the Assumptions Audit. Each bullet is one AI-introduced inference, kept so the
     spec can be signed off by skimming this section alone. -->

- **Untagged steps run in every selection.** Chosen so adding a tag to one step never silently
  drops the steps that carry none. The alternative, where an untagged step runs only in an
  unscoped run, makes the first tag a breaking config change.
- **A step carries zero or more tags; a selection names exactly one.** Confirmed. A step runs when
  it is untagged or when its tags contain the selection, so a step can be both `backend` and `slow`
  without the tool input, the recorded scope and every `--only` comparison becoming set operations.
- **An empty or whitespace-only tag is a config error**, thrown when the config loads, consistent
  with how a parallel group rejects a bad member.
- **A selection matching no tagged step raises a notice, and that notice blocks like any other.**
  Asking for a scope that nothing carries is almost always a typo, and the untagged steps would
  otherwise pass and report the run verified. Making it blocking also keeps `verifiedGiven()`
  untouched: it currently fails on `notices !== []` wholesale, and carving out a non-blocking notice
  kind would mean restructuring the guard that closes the dropped-gate false green.
- **Opening a run with a different selection replaces the open run.** Confirmed. A different
  selection asks a different question, so returning the run already open would answer the wrong
  one. This mirrors the existing rule for a working tree that moved.
- **Bare `pipeline:verify` fails for a scoped receipt.** Confirmed. Exit 0 only for an unscoped
  run; asking about a subset is explicit. See Resolved Questions.
- **`--only` on `pipeline:verify` must match the receipt's selection exactly.** A receipt from a
  `backend` run does not satisfy `--only=frontend`.
- **Scopes do not accumulate across runs.** There is one receipt file and each run overwrites it,
  so verifying `backend` and then `frontend` leaves only the second. A change spanning both wants an
  unscoped run, not two scoped ones.
- **Tag matching is case-sensitive.** `Backend` and `backend` are different tags. A mistyped case
  therefore matches nothing and hits the blocking notice above rather than silently narrowing.
- **An unscoped selection is a selection.** Opening unscoped while a `backend` run is open starts a
  new run, on the same rule as any other change of selection.
- **Tags are free strings, not an enum.** `frontend` and `backend` are the motivating pair, but a
  project may want `api`, `infra`, or `migrations`, and the package cannot know the vocabulary.

---

## 1. Data model

`Step::tags(): array` joins the contract, returning `list<string>`. Breaking for a custom `Step`,
the same shape as `mutates()` in 0.3 and handled the same way in `UPGRADING.md`.

`Shell` and `Skill` gain `tagged(string ...$tags): self`, following `mutating()` and `proving()` in
returning a new instance so declaration order does not matter:

```php
$steps->in(Formatting::class)
    ->append(Shell::run('vendor/bin/pint --test')->tagged('backend'))
    ->append(Shell::run('yarn lint-all')->tagged('frontend'));
```

A tag that is empty or whitespace-only throws `InvalidPipelineConfigException` when the config
loads, named after the step, matching how `StepBatch` rejects a bad member.

## 2. Filtering the walk

`Walk::resolve(Phases $phases, Steps $steps, ?string $selection = null)`. A step enters the walk
when `$selection === null`, when `$step->tags() === []`, or when `in_array($selection, $step->tags(), true)`.

Two consequences to handle explicitly:

- **Filtering happens inside a parallel group too.** A group whose members are partly filtered out
  keeps the survivors as a group; a group left with one member is an ordinary position; a group
  left with none contributes no position at all.

  A single survivor must also **lose its `batchId`**, not merely be treated as a lone position.
  `Walk::isGrouped()` reads that field, and a stale verdict for a step that ran by itself would
  otherwise say it "ran in a parallel group ... cannot tell its members apart", which is exactly the
  overclaim v0.6.1 removed.
- **A selection that matches no tagged step raises a notice**, which blocks like every other
  notice. An excluded step is deliberate and produces nothing; a selection that excluded
  *everything* is a fault, almost always a mistyped tag. Keeping it blocking means `verifiedGiven()`
  keeps its wholesale `notices !== []` guard rather than growing a notice taxonomy around the line
  that closes the dropped-gate false green.

`Pipeline::walk(?string $selection = null)` passes it through.

## 3. Carrying the selection through the run

`RunManager::open(?string $selection = null)` and `Run::start(..., ?string $selection = null)`.

`RunManager` currently returns the run already open unless the tree moved. A selection is a second
reason the open run may be the wrong answer: opening `backend` while a `frontend` run is open must
start a new run rather than hand back verdicts about a different set of steps.

`open_run` gains an input:

```php
public function schema(JsonSchema $schema): array
{
    return [
        'only' => $schema->string()->description(
            'Run only the steps carrying this tag, plus every untagged step. Omit to run the whole pipeline. A scoped run verifies less, and its receipt records the scope.'
        ),
    ];
}
```

`StepPayload::envelope()` reports `scope` when a run is scoped, so `status` and every response say
what is in play. Absent means unscoped, so an existing consumer reading the payload is unaffected.

## 4. The receipt contract

`Receipt` gains `?string $scope`, round-tripped by `JsonReceiptStore` under a `scope` key and
omitted when null, matching how the class already drops null fields.

`VerifyCommand` gains `--only=`:

The rule is coverage, not equality: **exit 0 when what the receipt verified covers what was
asked.** An unscoped run verified every step, so it satisfies any scope query.

| Receipt | Command | Exit |
|---|---|---|
| unscoped, verified | `pipeline:verify` | 0 |
| unscoped, verified | `pipeline:verify --only=backend` | 0, the backend was verified along with everything else |
| `backend`, verified | `pipeline:verify` | non-zero, the run verified a scope rather than the tree |
| `backend`, verified | `pipeline:verify --only=backend` | 0 |
| `backend`, verified | `pipeline:verify --only=frontend` | non-zero, different scope |

Every non-zero case names the receipt's scope and the scope asked for, in the style of the existing
messages: a reader must be able to tell "this run verified something else" from "this run failed".

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Selection names a tag no step carries | Blocking notice on `open_run`; the untagged steps still run but the run cannot report verified. Phase `tags` tests |
| Every step is filtered out | Empty walk, run completes, `all_verified` false because `results === []`. Existing behaviour, asserted in Phase `tags` tests |
| Parallel group partly filtered | Survivors stay one position; a lone survivor becomes an ordinary position and drops its `batchId`; none contributes nothing. Phase `tags` tests |
| Stale verdict for a lone survivor of a filtered group | Must not claim the step ran in a group. Phase `tags` tests |
| Scoped run, then a differently scoped run | The second receipt replaces the first. Only the latest scope is verifiable; a change spanning both wants an unscoped run. Phase `receipt-scope` tests |
| A tagged step is also `->mutating()` | Unaffected. Tags and mutation are independent; the group rules still forbid mutating members |
| `open_run` twice with the same selection | Idempotent, as today |
| `open_run` with a different selection | New run. Phase `run-selection` tests |
| Tree moves during a scoped run | Unchanged: staleness is orthogonal to scope |
| Scoped run, then bare `pipeline:verify` | Non-zero, naming the scope and how to ask for it. Phase `receipt-scope` tests |
| Receipt scope differs from `--only` | Non-zero, message names both. Phase `receipt-scope` tests |
| Empty or whitespace tag in config | `InvalidPipelineConfigException` at load. Phase `tags` tests |
| A consumer on the old payload | `scope` is absent for an unscoped run, so nothing changes for them |

## Implementation

### Phase 1: Tags on steps and in the walk (Priority: HIGH)

**ID:** tags · **Depends:** none

- [ ] Add `tags(): array` to the `Step` contract — returns `list<string>`, empty for an untagged step.
- [ ] Add `tagged(string ...$tags)` to `Shell` and `Skill` — new instance, preserving `mutating()` and `proving()` state.
- [ ] Reject an empty or whitespace-only tag with a named `InvalidPipelineConfigException` — at config load, not at run time.
- [ ] Filter in `Walk::resolve()` behind an optional selection — untagged always in, tagged in only on a match.
- [ ] Handle groups under filtering — survivors keep the position, an emptied group contributes none, and a lone survivor loses its `batchId` so nothing later calls it grouped.
- [ ] Notice when a selection matches no tagged step — blocking, so `verifiedGiven()` keeps its wholesale notice guard.
- [ ] Tests — the filter, group survival, the notice, tag validation, and that an excluded step is not a dropped step.

### Phase 2: Selecting a scope when the run opens (Priority: HIGH)

**ID:** run-selection · **Depends:** tags

- [ ] Thread the selection through `Pipeline::walk()`, `Run::start()` and `RunManager::open()`.
- [ ] Start a new run when the selection differs from the open run's — a different selection is a different question.
- [ ] Add the `only` input to `open_run`, described so an agent knows a scoped run verifies less.
- [ ] Report `scope` in the payload envelope, absent when unscoped.
- [ ] Report how many steps the scope excluded in `status` — a reader must be able to see the walk is smaller than the config without diffing it themselves.
- [ ] Update the `run_pipeline` prompt — it drives `open_run` and would otherwise never mention `only` exists.
- [ ] Declare `scope` in the output schema — the schema must not fall behind the payload again.
- [ ] Tests — threading, the re-open rule, the payload key present and absent, and the schema declaring it.

### Phase 3: Scope in the receipt and the verify command (Priority: HIGH)

**ID:** receipt-scope · **Depends:** run-selection

- [ ] Add `scope` to `Receipt` and round-trip it in `JsonReceiptStore`, omitted when null.
- [ ] Add `--only=` to `pipeline:verify` and implement the table in section 4.
- [ ] Word each mismatch so a wider or narrower answer is distinguishable from a failure.
- [ ] Tests — every row of the table, including that a bare call against a scoped receipt exits non-zero.

### Phase 4: Documentation (Priority: HIGH)

**ID:** docs · **Depends:** receipt-scope

- [ ] README section on tags and scoped runs, stating plainly that a scoped run verifies less.
- [ ] `UPGRADING.md` entry for `Step::tags()` on the contract.
- [ ] Add the scoped-receipt row to the false-green table in `.ai/docs/invariants.md`, with what closes it.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **A step excluded by the selection produces no notice at all.** It is simply not in the walk.
   If filtering ends up reporting each excluded step the way a dropped step is reported, stop: that
   collapses a deliberate scope into a fault, and every scoped run would report itself unverified.
2. **`verifiedGiven()` keeps its wholesale `notices !== []` guard.** That line is what closes the
   dropped-gate false green. If filtering turns out to need a notice kind that does not block, stop:
   carving an exception into that guard is a design decision, not an implementation detail.
3. **A scoped receipt is distinguishable from an unscoped one by any consumer reading it.** If the
   scope cannot be recorded and read back, the feature is a false-green generator and must not ship.
4. **A full run satisfies a scope query.** If `--only` ends up comparing scopes for equality rather
   than coverage, a fully verified tree starts failing subset queries, which inverts what the
   command is for.
5. **Untagged steps run in every selection.** If this inverts, adding a single tag becomes a
   breaking change to every config that already exists.

---

## Open Questions

None.

---

## Resolved Questions

1. **What should a bare `pipeline:verify` do when the receipt records a scope?**
   **Decision:** Exit non-zero unless the recorded run was unscoped. Asking about a subset is
   explicit: `pipeline:verify --only=backend`.
   **Rationale:** The command answers "is this tree verified?". A scoped run did not verify the
   tree, so yes would be wrong, and a gate cannot be expected to read the receipt to find out. The
   cost is that a hook must name the scope it cares about, which is a fair thing to make explicit.

2. **How much of the tag model should the first version carry?**
   **Decision:** A step declares zero or more tags; a selection names exactly one.
   **Rationale:** A step that is both `backend` and `slow` is realistic and cheap to support. A
   list-valued selection is not: the recorded scope and every `--only` comparison would become set
   operations, with subset rules to define before anything could be compared.

3. **What happens when `open_run` is called with a different selection while a run is open?**
   **Decision:** Start a new run.
   **Rationale:** A different selection is a different question, and the package already treats a
   moved working tree that way. Refusing instead would leave no way forward, because there is no
   tool that closes a run.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
