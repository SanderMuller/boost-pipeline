# Detect a dropped step inside a scope

<!-- spec:planned-at f92f94ce264cf4f167559528eef9f730edd70ee4 2026-08-31 -->

## Overview

A step declared into a phase nothing registered is dropped from the walk with a notice. A whole-tree
`pipeline:verify` refuses a run whose config drops one; a **scoped** call cannot, because the notice
is a sentence and does not say which scope the dropped step belonged to. Applying it to a scoped call
anyway would fail a backend answer over a frontend step, so the gap was left open and documented.

Deriving the dropped steps where the selection is known closes it. `Walk::resolve()` already receives
both the declaration and the selection, so it can report the steps this walk dropped — filtered to the
scope it was resolved for — and the check stops needing a scope exemption at all.

## Assumptions

- **The gap is real and currently documented in code**, at `src/Console/VerifyCommand.php:543`. It
  takes a stale server to reach, because a run against the same config raises the same notice and
  records `coverage: incomplete`, which already fails.
- **`notices` stays a list of strings.** It is declared in the shared MCP envelope schema and read by
  agents. Changing its element type would break a consumer that reads it, so the structured data
  arrives as a NEW property beside it rather than as a change to it. Load-bearing: if a proposed edit
  changes `notices`' element type, that is a STOP.
- **The selection is what makes filtering possible, and only `Walk::resolve()` has it.** A dropped
  step is dropped whatever the selection, but whether it belongs to a given scope depends on its tags
  against that selection. `Walk` holds neither the selection nor the tags after resolving, so the
  filtering has to happen during resolution.
- **Tags are reachable at the point steps are dropped.** `noticesForUnregisteredPhases()` iterates
  `$steps->forPhase($phaseClass)`, which returns `Step` objects, so `tags()` is available where the
  notice is currently built. Load-bearing — without it there is nothing to filter on.
- **Membership, not order, decides scope.** `Walk::selected()` tests `in_array`, so a step carrying no
  tag is in every scope and tag order is irrelevant. The filter reuses that predicate rather than
  restating it, so the two cannot drift.
- **`allVerified` and `coverage` keep reading `notices`, unchanged.** Switching them to the
  scope-filtered list would LOOSEN them: a scoped run with an out-of-scope dropped step would become
  verifiable where today it is not. That may well be more correct, and it is a separate decision from
  closing a gap — see Open Question 1. This spec changes no existing verdict.
- **This removes a scope exemption rather than adding a check.** The whole-tree behaviour is
  unchanged; the scoped call gains the check it was excluded from. So a gate that passes today can
  refuse after this — the third such change in four releases, which is a pacing question for the
  release rather than a design one.

---

## 1. Current state

`src/Walk/Walk.php` builds two kinds of notice, both as prose:

- `noticesForUnregisteredPhases()` — names the steps dropped because their phase is not registered.
  It ignores the selection entirely.
- A selection notice when no step carries the requested tag. This one drops nothing: the walk is every
  untagged step.

`Walk` exposes `notices` (`list<string>`), `excluded` (a count), and `configDigest`. Four places read
`notices`:

- `src/Mcp/StepPayload.php:141` — the agent-facing payload.
- `src/Run/Run.php:406` — `allVerified` is false while any notice exists.
- `src/Run/Run.php:617` — `coverage` records `incomplete`.
- `src/Console/VerifyCommand.php:546` — the dropped-step refusal, **restricted to `$scope === null`**.

That restriction is the gap. Its comment states the reason plainly: the notice cannot say which scope
the dropped step belonged to, so reading it for a scoped call would fail an answer over an unrelated
scope.

## 2. Proposed changes

`Walk` gains a second, structured property describing what THIS walk dropped:

```php
/** @param list<array{id: string, phase: string}> $dropped */
public array $dropped = [],
```

Populated during `resolve()`, filtered by the same `selected()` predicate the walk itself uses, so a
step dropped from an unregistered phase appears only when it belongs to the selection. `walk(null)`
lists every dropped step; `walk('backend')` lists the dropped steps a backend run would have wanted.

`VerifyCommand` then drops its scope exemption:

```php
// was: if ($scope === null && $walk->notices !== [])
if ($walk->dropped !== []) {
```

and names the step ids, which the prose notice could only do by being quoted whole.

`notices` is untouched — same strings, same consumers, same schema. The two describe the same event
for different readers: prose for the agent, structure for the gate.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Dropped step carries the requested tag | In scope, so the scoped call now refuses. This is the gap being closed. Phase `derive` and `gate` Tests. |
| Dropped step carries a DIFFERENT tag | Out of scope, so the scoped call still passes. This is the false failure the exemption existed to avoid, and it must survive. Phase `gate` Tests. |
| Dropped step carries NO tag | In every scope, so every scoped call refuses. Matches `selected()`, which puts an untagged step in every walk. Phase `derive` Tests. |
| Whole-tree call | Unchanged: every dropped step is in scope, so behaviour matches today's. Asserted so the change is provably additive. |
| Selection no step carries | Still drops nothing, so `dropped` is empty and the call still passes. A notice exists, but the walk is every untagged step — the distinction this design turns on. Phase `derive` Tests. |
| Config drops a step AND the tree moved | Tree message still wins; it returns first. Unchanged. |
| Run recorded before this version | Nothing recorded changes — `dropped` is derived from config at read time, not persisted. No receipt field, no upgrade path, no unknown state. |
| `allVerified` / `coverage` for a scoped run with an out-of-scope dropped step | Unchanged, still false and `incomplete`. Deliberately not loosened here — Open Question 1. |

## Implementation

### Phase 1: Derive what the walk dropped (Priority: HIGH)

**ID:** derive · **Depends:** none

No behaviour change. `dropped` is populated and nothing reads it yet, so this phase is provable on its
own.

- [ ] Add `dropped` to `Walk`, populated in `resolve()` and filtered with the same `selected()` predicate the walk uses, so scope membership cannot drift from the walk's own rule.
- [ ] Leave `notices` exactly as it is — same strings, same order, same consumers. Note in the docblock that the two describe one event for two readers.
- [ ] Tests — an unregistered-phase step appears in `dropped` for a whole-tree walk; appears for a scoped walk when it carries that tag or no tag; is absent for a scoped walk when it carries a different tag; a selection nothing carries leaves `dropped` empty while still raising a notice. Assert `notices` is byte-identical to before for each case.

### Phase 2: Gate on it, with no scope exemption (Priority: HIGH)

**ID:** gate · **Depends:** derive

- [ ] Replace the `$scope === null && $walk->notices !== []` condition in `src/Console/VerifyCommand.php` with `$walk->dropped !== []`, and name the dropped step ids in the message.
- [ ] Rewrite the comment: it currently explains why a scoped call is exempt, and that reason is gone. Leaving it would document a restriction the code no longer has.
- [ ] Tests FIRST, each confirmed red before the change — a scoped call refuses a dropped step carrying its tag; a scoped call still PASSES a dropped step carrying another tag; the whole-tree call behaves as it does today; the message names the step ids. Then mutation-check by restoring the scope exemption and confirming the scoped-refusal test goes red.

### Phase 3: Documentation (Priority: HIGH)

**ID:** docs · **Depends:** gate

- [ ] `UPGRADING.md` — a scoped `pipeline:verify` now refuses a config that drops a step inside that scope, so a gate that passed can exit 1; a dropped step outside the scope still does not fail it.
- [ ] `.ai/docs/invariants.md` — update the false-green row that records this gap rather than adding one, since the gap is being closed rather than a new class being found.
- [ ] Check `README.md`'s `pipeline:verify` section for the scope wording, which currently describes the whole-tree restriction.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **`notices` keeps its element type.** It is declared in the shared MCP envelope schema and read by
   agents. If an edit would make it anything but a list of strings, stop: the structured data belongs
   beside it, never in place of it.
2. **The scope filter reuses `Walk::selected()`.** If it restates the membership rule instead, stop —
   two copies of "is this step in this scope" will disagree eventually, and the disagreement would be
   a false failure in a gate.
3. **The whole-tree call's behaviour is identical after the change.** Phase 2 removes an exemption for
   scoped calls only. If a whole-tree case changes outcome, the derivation is wrong rather than the
   exemption.
4. **No existing test asserts `Walk::$notices` is the only description of a dropped step.** If one
   pins that, it is stating a contract this spec extends — read it before editing it.

---

## Open Questions

1. **Should `allVerified` and `coverage` also read the scope-filtered list?** Today a scoped run is
   unverifiable while ANY notice exists, including one about a step in another scope. Reading
   `dropped` instead would loosen that: the run only ever claimed its own scope, and a step dropped
   outside it was never going to run, so arguably the current behaviour is over-strict.

   It is left out deliberately. This spec closes a gap and changes no existing verdict; loosening a
   false-green guard is the opposite kind of change and deserves its own decision, not a ride along
   with this one. Worth answering before the next release that touches `Run`, because the two
   behaviours will look inconsistent until it is settled — a scoped gate refusing on
   `all_verified` for a step the same scoped gate ignores under `dropped`.

---

## Resolved Questions

1. **Structured notices, or a new property beside them?** **Decision:** A new property.
   **Rationale.** `notices` is declared in the shared MCP envelope schema and read by agents as
   prose. Changing its element type would break a consumer reading it, for no gain — the gate needs
   step ids and tags, and an agent needs a sentence. Two shapes for two readers, one event.
2. **Where does the scope filtering happen?** **Decision:** Inside `Walk::resolve()`. **Rationale.**
   It is the only place holding both the selection and the dropped steps' tags. Filtering later would
   mean carrying tags on the walk purely so a caller could re-derive what resolution already knew, and
   `VerifyCommand` would then own a copy of the membership rule.
3. **Does `dropped` get recorded in the receipt?** **Decision:** No. **Rationale.** It is derived from
   the config at read time, and the question it answers is about the config as it stands now, not
   about the run. Recording it would add an upgrade path and an unknown state for no benefit.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
