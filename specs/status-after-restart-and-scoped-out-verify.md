# `pipeline:verify` on a scope nothing carries, and `status` after a server restart

<!-- spec:planned-at 34f44d73834c7bea0ad8f7ed8f4a88786b6240a7 2026-09-02 +uncommitted -->

## Overview

Dogfood feedback from a consumer project that drove v0.11.0 and v0.16.0 over stdio against a real
change. One finding survives tracing against `main` at the planned-at commit:

`pipeline:verify --only=<tag>` on a receipt whose scope is a tag no step carries answers
`Run [id] has not verified every step. State [complete].` The run verified every step it held. The
true reason is that the scope covered nothing, and the message sends the reader hunting for a
missing step.

Two more findings were documentation defects and are fixed in the same change as this spec:

- The README claimed a tag no step carries "blocks the run". The code opens the run, walks the
  untagged steps, emits a notice and records `coverage: "incomplete"`. The README now says so.
- `status` after a server restart answers `No run is open`. The reporter first read this as a
  defect and then retracted it: their harness spawned one server process per tool call. Run state
  is process-local by design. The README sentence "Run state lives in the server process" now
  names the consequence for `status` and points at the receipt and `pipeline:history`.

## Assumptions

- **The exit code is right and only the message is wrong.** `coverage: "incomplete"` plus
  `all_verified: false` already fail the bare call and `--only=`. Nothing here changes an exit
  code. Load-bearing: if an implementation step needs to change one, STOP.
- **The receipt does not record which notice broke coverage.** `src/Run/Run.php` writes
  `coverage` from `walk->notices === []` and nothing else. The message can be certain about a
  mistyped tag only when the receipt's `scope` is set and no recorded step carries it, which the
  receipt cannot tell either: verdicts are keyed by id, not by tag. The command loads the config
  in its own process, so it can ask the declaration whether any step carries the receipt's scope.

## 1. Current state

**Bare path order.** `src/Console/VerifyCommand.php:70-135`: empty verdicts, tree moved, `stale`,
`scopeMismatch()`, `declarationMismatch()`, `declaredButNeverRecorded()`, then
`--server-verified`, then `allVerified`. The coverage check at `:254` lives only inside
`answerServerVerified()`.

**The mistyped-tag receipt.** `scope: "bakend"`, `coverage: "incomplete"`,
`all_verified: false`, one passed untagged step. With `--only=bakend`: `scopeMismatch()` returns
null because the scopes are equal. `declaredButNeverRecorded()` reads "declared" in the receipt's
scope, which holds only the untagged step, and that step was recorded, so it returns null. The
call reaches `explainUnverified()` (`:688`), which names unverified steps, finds none, and prints
`has not verified every step. State [complete].` with nothing after it.

**Why `all_verified` is false.** `Run::verifiedGiven()` returns false whenever
`walk->notices !== []`. `Walk::for()` (`src/Walk/Walk.php:47-53`) appends a notice when the
selection matched no step.

## 2. Proposed changes

Check coverage on the bare path too, after `declaredButNeverRecorded()` and before
`explainUnverified()`. A receipt with `coverage !== 'complete'` exits 1 with a message that names
the cause. Share one message builder with `answerServerVerified()` so the two paths cannot drift.

Wording, by what the command can know:

- `scope` set and the loaded config holds no step tagged with it: the run verified nothing in
  scope `[x]` because no step carries that tag, so the scope covered nothing. Check the spelling,
  matching is case-sensitive.
- Otherwise: the run did not cover the config that declared it, a declared step never reached the
  cursor or a selected tag no step carries. This is the wording `answerServerVerified()` has now.
- `coverage === null`: unchanged wording, the receipt predates the field.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Bare call on a mistyped-tag receipt | Exits 1 via `scopeMismatch()` first, as today. Unchanged. |
| `--only=<tag>` matching the receipt scope, coverage incomplete, tag carried by no step | New "scope covered nothing" message. Exit code unchanged. |
| `--only=<tag>` matching the receipt scope, coverage incomplete, tag carried by a step | Generic coverage message: a declared step was dropped for another reason. |
| Unscoped receipt, coverage incomplete | Generic coverage message on the bare path, ahead of `explainUnverified()`. Today this reaches `explainUnverified()` and may name no step at all. |
| `--server-verified` | Same builder, same wording, position unchanged. |
| `coverage === null` | Bare path tolerates it, as it tolerates an absent config digest. `--server-verified` keeps refusing it with unchanged wording. |

## Implementation

### Phase 1: Name the cause when a scoped receipt covered nothing (Priority: HIGH)

**ID:** verify-coverage · **Depends:** none

- [x] Tests FIRST in `tests/Feature/VerifyCommandTest.php`: a receipt with `scope: "bakend"`,
      `coverage: "incomplete"`, `all_verified: false`, one passed untagged step, against a config
      where no step carries `bakend`. `--only=bakend` exits 1 and the output names the scope as
      covering no step. Confirm it fails against current `main` on the message, not the exit code.
- [x] Tests FIRST: the same receipt with `--server-verified` prints the same message.
- [x] Tests FIRST: an unscoped receipt with `coverage: "incomplete"` and every recorded step
      passed prints the generic coverage message on the bare path.
- [x] Extract the coverage message from `answerServerVerified()` into a private builder that takes
      the `Pipeline` and the receipt, and call it from both paths.
- [x] Mutation check per `.ai/docs/invariants.md`: remove the bare-path call and confirm the new
      bare-path tests go red.
- [x] `UPGRADING.md`: none. Messages only.

### Phase 2: Discoverability of `Shell::inspecting()` (Priority: LOW)

**ID:** inspecting-docs · **Depends:** none

- [x] Reference `->inspecting()` from the README `Configure` section with a one-line pointer to
      "The trap worth knowing". The reporter found it only by reading the README end to end.

---

## STOP Conditions

1. **An existing `pipeline:verify` case changes exit code.** This spec changes messages only.
2. **The bare-path coverage check fires ahead of `scopeMismatch()` or the tree check.** Order is
   part of the contract: a moved tree explains everything after it.
3. **The check needs the receipt to record which notice broke coverage.** That is a receipt format
   change and belongs in its own spec.

---

## Open Questions

1. Should `open_run` warn when a receipt on disk belongs to a run this server process never
   held, so an agent re-opening "to check" learns it is starting over before the first step
   runs? The retracted finding shows the failure mode: an agent that lost `status` re-opens
   and re-runs the suite.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- The first cut refused `coverage === null` on the bare path too, and two existing acknowledged-run
  cases went red: their fixtures predate the field. The bare path now tolerates absent coverage the
  way it tolerates an absent config digest, and only `--server-verified` refuses unknown. The spec's
  edge-case row was corrected to match.
- Mutation check done: with the bare-path call removed, the two bare-path cases go red and the
  `--server-verified` case stays green through its own call.
- The "no step carries" wording comes from `Walk::$selectionCarriedNothing` on a walk of the
  config on disk, so it is a fact about the declaration now, not a guess from the receipt.
