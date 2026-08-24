# Verifying what the server ran

<!-- spec:planned-at 83d7c58fb539b90013981dbd198a6134c04c208c 2026-08-24 -->

## Overview

`pipeline:verify` answers one question: is this tree verified? A pipeline that sequences
agent work can never hear yes. A `Skill` step resolves to `acknowledged`, `all_verified`
stays false, and the command exits non-zero however green the shell steps are. So the
consumer shape the walk exists to serve — one step at a time, judgement included, none of it
skippable — has no answerable question at all.

Add the narrower one:

```bash
php artisan pipeline:verify --server-run-only
```

Exit 0 when the run covered the config it declared, the walk finished, and every verdict the
server produced is a pass — counting no acknowledgement as evidence of anything. Same
fingerprint checks, no config read and no new dependency.

> **This changes the receipt contract, and needs sign-off before Phase 1.** The first draft of
> this spec did not, and was unsafe: see section 2. `all_verified` is false for two unrelated
> reasons — an acknowledgement, and a *declared step dropped before the walk began* — and the
> receipt records neither. A predicate that only excludes acknowledgements therefore exits 0
> for a run that never ran a declared gate. The receipt has to persist a coverage signal for
> this flag to exist at all.

**Why it matters beyond this package.** A downstream skill wants to skip re-running a
formatter the pipeline already ran against this exact tree. The exit code is the whole
interface it has. Today the aggregate answer throws the per-step detail away, so the skill
re-runs every check and the project pays twice for one answer. The alternative — the skill
reads `receipt.json` and re-derives freshness in prose — puts this package's fingerprint
algorithm in someone else's markdown, where it will rot.

## Assumptions

<!-- Each bullet is one AI-introduced inference, kept so the spec can be signed off by
     skimming this section alone. -->

- **`state === complete` is a precondition, not a detail.** `recordReceipt()` writes after
  every resolution, deliberately, so a walk abandoned at step 1 leaves a readable receipt
  holding one `passed` verdict and nothing else. A predicate of "every non-acknowledged
  verdict passed" exits 0 for that run, reporting a formatter, an analyser and a suite that
  never ran. Requiring the terminal state is what makes the flag safe.
- **`halted` does not count as finished.** It is terminal but it means a step errored, so the
  steps behind it never resolved. Only `complete` says the walk covered the config.
- **An acknowledgement is excluded, never counted.** The flag changes which verdicts the
  predicate reads. It must never change what one means.
- **The success line has to state what it did not answer.** A reader who sees only "verified"
  will believe the run was green. The count of excluded steps is part of the answer, not
  decoration.
- **A run of nothing but acknowledgements exits non-zero.** It reaches `complete` and holds
  no server verdict at all. Zero passes is not "every pass passed"; an empty predicate must
  fail closed, the same rule the scoped-run notice already follows.
- **The flag composes with `--only`.** A scoped receipt answers its own scope; the flag says
  which verdicts inside it count. Both guards apply in their existing order.
- **No config is read, so no `Pipeline` dependency.** This is the difference from the
  rejected design (see Resolved Questions 1). The predicate needs the receipt only — once the
  receipt carries coverage, which today it does not.
- **`all_verified: false` is two answers, and only one of them is benign.** It is false for an
  acknowledgement, and false when `Walk::resolve()` dropped a declared step — a step declared
  into an unregistered phase, or a selection no step carries. `verifiedGiven()` fails on
  `walk->notices !== []` for exactly that reason, and the receipt persists no notices, so the
  two cases are indistinguishable on disk. This is the finding that forces the contract change.
- **Coverage is recorded, not inferred.** The receipt gains a `coverage` key. Its *absence*
  means unknown, not clean: a receipt written before this release fails the new flag closed.
  A boolean would read a missing key as `false`-y and a `notices` list would read a missing
  key as empty — both silently pass the case they cannot see.
- **A bare `pipeline:verify` is untouched, to the exit code.** It asks about the tree, and a
  run holding an acknowledgement has not verified the tree.

---

## 1. The predicate

`handle()` currently checks, in order: receipt exists, tree matches, not stale, scope
mismatch, `allVerified`. The first four are unconditional facts about the receipt and keep
their place — a stale receipt must fail on staleness, never on a verdict read from a run that
does not describe this code. Only the last becomes flag-aware:

- **Without the flag** — unchanged. `allVerified` decides; `explainUnverified()` keeps its
  wording.
- **With `--server-run-only`** — require, in this order: the receipt records `coverage:
  complete`; `state === complete`; every verdict that is not `acknowledged` is `passed`; and
  at least one such verdict exists.

Coverage is checked first because it is the cheapest true/false and the most dangerous to
miss. A run that dropped a gate has nothing worth reporting about the gates it did run.

Stated against the enum rather than in prose: every verdict is `Acknowledged` or satisfies
`Verdict::isVerified()`, and at least one satisfies it. `isVerified()` is already true for
`Passed` alone, so `Failed` and `Error` fail the predicate without a second rule. The receipt
stores verdict *values*, so the command maps them back through `Verdict::tryFrom()` and treats
an unknown string as not verified.

## 2. The coverage key — a receipt-contract change

`Receipt` gains `?string $coverage`, round-tripped by `JsonReceiptStore` and omitted when
null, matching how the class already drops null fields. Written by `recordReceipt()` from the
run's own walk:

| Value | Written when |
|---|---|
| `complete` | `walk->notices === []` — the walk reported no coverage notice |
| `incomplete` | `walk->notices !== []` — the walk raised a blocking coverage notice: a declared step dropped into an unregistered phase, or a selected tag no step carries |
| absent | the receipt predates this release, or was written by something else |

**`complete` means "no blocking coverage notice", not "every declared step ran."** A scoped
run leaves its out-of-scope steps out without a notice — `buildWalk()` counts them in
`excluded` and says nothing, deliberately, because a declared scope is not a dropped gate.
So a `backend` run writes `coverage: complete` while frontend steps never ran. That is safe
for a different reason, already shipped: the receipt records `scope`, and `scopeMismatch()`
rejects a bare or mismatched question before any verdict is read. `coverage` answers "did
anything go missing by accident", and `scope` answers "what was this run about". Neither
substitutes for the other.

The predicate accepts `complete` and nothing else. `incomplete` and absent both fail closed,
with different messages: one says the walk did not cover what the config declared, the other
says the receipt cannot answer. The value is `incomplete` rather than `dropped` because the
notices are not all dropped steps — a selection no step carries raises one too, and nothing was
dropped in that case; the walk simply never held the thing the caller asked about.

Two things this deliberately does **not** do:

- **It does not persist the notice text.** A reader who needs to know *which* step was dropped
  reads `status` on a live run. Copying notices into the receipt makes the file a log and
  invites a consumer to parse it, which is the coupling Resolved Question 4 refuses.
- **It does not change `all_verified`.** That field keeps its meaning exactly, so the bare
  call and every existing consumer are untouched. `coverage` is strictly additive.

**Strict verdict parsing comes with it.** `Receipt::fromArray()` currently drops any verdict
entry whose key or value is not a string, so a corrupted file can arrive as a `complete`
receipt holding only the entries that happened to survive — and the new predicate would pass
it. A malformed verdict map must make the whole receipt unreadable (`null`), which the command
already handles as "no run recorded". Fail-closed, and the same answer a truncated file gets
today.

**A numeric step id is not malformed.** `json_decode($json, true)` returns an *integer* key for
a step id of `"123"` — PHP coerces numeric-string array keys — and nothing forbids that id:
`Shell::run()` takes an arbitrary string and the walk checks only uniqueness. So strict parsing
must accept an integer key and cast it back to a string, and reject only a key that is neither.
Today's permissive parser silently drops such a verdict, which is the same bug wearing a quieter
face; the round trip needs a persisted test with a numeric id.

## 3. Messages

Success names the exclusion, in the same breath:

```
Run [r-abc123] passed all 6 step(s) the server ran against this tree. 2 step(s) were only
acknowledged and are not counted, so this is not a claim that the tree is verified.
```

The second sentence is the whole safety margin. Drop it and the flag reads as a synonym for
the bare call.

Three non-zero cases, each with its own message:

| Situation | Message says |
|---|---|
| `coverage: incomplete` | Which of the two it is — a declared step that never reached the cursor, or a selected tag no step carries — and that neither leaves an answer worth having about the steps that did run |
| `coverage` absent | That the receipt predates coverage recording and cannot answer this question — open a new run |
| State is not `complete` | The state, and that the walk did not finish, so the steps behind the cursor never ran |
| A server verdict is not `passed` | The step id and its verdict — retryable, in the style `explainUnverified()` already uses for a failure |
| Every verdict is an acknowledgement | That the run produced no server verdict at all, so there is nothing to pass |

## 4. Exit table

Extends the table in `specs/step-tags-and-scoped-runs.md` section 4. No existing row changes.

| Receipt | Command | Exit |
|---|---|---|
| complete, all steps passed | `--server-run-only` | 0, same answer the bare call gives |
| complete, shell steps passed, agent steps acknowledged | `pipeline:verify` | non-zero, unchanged |
| complete, shell steps passed, agent steps acknowledged | `--server-run-only` | **0** — this is the change |
| complete, one shell step failed, agent steps acknowledged | `--server-run-only` | non-zero, naming the failed step |
| `running`, resolved steps all passed | `--server-run-only` | non-zero: the walk did not finish |
| `coverage: incomplete`, complete, every remaining step passed | `--server-run-only` | non-zero: the walk did not cover the config |
| no `coverage` key (receipt predates this release) | `--server-run-only` | non-zero: unknown coverage is not clean coverage |
| verdict map malformed on disk | `--server-run-only` | non-zero: unreadable receipt, same as no run |
| `halted` after an error, earlier steps passed | `--server-run-only` | non-zero: terminal is not finished |
| complete, every step acknowledged | `--server-run-only` | non-zero: no server verdict to report |
| `blocked` | `--server-run-only` | non-zero — and unreachable with all verdicts passing: `blocked` means the current position holds a `failed`, which is in the receipt |
| tree moved since the run | `--server-run-only` | non-zero, before any verdict is read |
| receipt records `stale` | `--server-run-only` | non-zero, before any verdict is read |
| `backend` scoped, complete, shell passed, agent acknowledged | `--server-run-only --only=backend` | 0 |
| `backend` scoped, complete | `--server-run-only` | non-zero: the scope guard runs first, unchanged |
| no receipt | `--server-run-only` | non-zero, unchanged |

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Walk abandoned mid-run | Fails on state. The test writes a `running` receipt whose only verdict is a pass, and asserts non-zero — this is the false green the guard exists for |
| `awaiting` on a skill step | Same as any unfinished state: non-zero. The cursor has not passed the step |
| A `Skill` step made verifiable with `proving()` | Holds a `passed` verdict, so it is ordinary evidence and is counted |
| A verdict this package does not know | Not `passed`, so it fails closed. No enum lookup that can throw |
| Empty walk, run completes with no results | Reaches `complete` in the constructor and writes **no** receipt, so verify sees no receipt at all, or a stale one from an earlier run. Not reachable as "a `complete` receipt with no verdicts" — the test has to build that receipt by hand, and should say so |
| Receipt written before this release | Still readable, and the bare call still answers from it unchanged. The new flag fails it closed: no `coverage` key means unknown coverage |
| Flag on a project with no pipeline config | Unaffected: the predicate reads the receipt only, so the command still needs no config to run |
| Both flags, scope mismatch | Scope guard first. A question about the wrong scope is unanswerable before verdicts matter |

## Implementation

### Phase 1: Coverage in the receipt (Priority: HIGH)

**ID:** coverage · **Depends:** sign-off on section 2

- [x] Add `?string $coverage` to `Receipt`, round-tripped by `JsonReceiptStore`, omitted when null — appended last with a default, so no positional caller breaks.
- [x] Write it in `recordReceipt()` from `walk->notices`: `complete` when empty, `incomplete` otherwise. Do not classify the notice — the receipt records that coverage broke, `status` says how.
- [x] Leave `all_verified` exactly as it is. Nothing about the bare call changes.
- [x] Make `Receipt::fromArray()` reject a malformed verdict map instead of dropping entries — the whole receipt becomes `null`, which the command already reports as no run recorded.
- [x] Cast an integer verdict key back to a string first: a step id of `"123"` decodes as `int`, and rejecting it would break a legal config.
- [x] Tests — both values written from a real run, `complete` written for a *scoped* run (it is not a claim about out-of-scope steps), the key absent on an old receipt, a malformed verdict map reading as no receipt, and a numeric step id surviving the round trip. Use a persisted receipt, not a hand-built object, for the round trips.

### Phase 2: The flag (Priority: HIGH)

**ID:** server-run-only · **Depends:** coverage

- [x] Add `--server-run-only` to the signature, described as the narrower question it is.
- [x] Check the guards in order: `coverage === complete`, then `state === complete`, then every server verdict passed, then at least one exists.
- [x] Word the success line so it states what it excluded and that it is not a claim about the tree.
- [x] Word the failures so incomplete coverage, unknown coverage, unfinished walk, a failed step and nothing-to-report are five distinguishable answers.
- [x] Leave the bare path and the `--only` guard untouched, in place and in order.
- [x] Tests — every row of the section 4 table. Write the mid-walk and dropped-gate false greens first: they are what the guards exist for.

### Phase 3: Documentation (Priority: HIGH)

**ID:** docs · **Depends:** server-run-only

- [x] README: the sequencing case in the receipt section — what the flag answers, and plainly what it does not.
- [x] README: `coverage` in the receipt shape, with absence meaning unknown.
- [x] `.ai/docs/invariants.md`: two false-green rows — a flag that excludes acknowledgements must not exit 0 for a walk that did not finish (closed by the `state` guard), and must not exit 0 for a run that dropped a declared gate (closed by `coverage`). The second is the one this spec was rewritten for.
- [x] `UPGRADING.md`: the `Receipt` constructor gains a parameter, and a receipt written by an older release reads as unknown coverage. Both are additive; both are worth one line.
- [x] No CHANGELOG edit: it is CI-managed from the release body (`CLAUDE.md`, Release Automation).

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The predicate reads a persisted coverage signal.** Without it the flag exits 0 for a run
   that dropped a declared gate, because `all_verified` conflates that with an
   acknowledgement and the receipt records neither. If coverage cannot be persisted, this
   flag must not ship — the first draft of this spec was wrong on exactly this point.
2. **`state === complete` gates the predicate too.** Coverage says nothing went missing by
   accident; the state says the cursor finished the walk that was resolved. Both, or neither.
3. **An `acknowledged` verdict is excluded, never counted as a pass.** If keeping those apart
   needs a special case, stop. Laundering a self-report into an exit 0 is the one outcome
   this package exists to prevent.
4. **Zero server verdicts fails closed**, and so does an absent `coverage` key. If either
   ever exits 0, the flag is a false-green generator.
5. **The bare call keeps its exit codes exactly, and `all_verified` keeps its meaning.** This
   adds an answer to a narrower question. If the aggregate gate loosens by one row, stop.
6. **No consumer config is read.** If the predicate turns out to need the walk after all,
   stop and re-read Resolved Question 1 — that is the design decision this spec reverses, not
   an implementation detail.

---

## Open Questions

None. The naming question below is resolved.

<!--
1. **Is `--server-run-only` the right name?** It borrows the `server_run` vocabulary, and
   that is the argument against it, not for it. `Result::serverRun()` answers *who produced
   the verdict*, not whether it passed — a `failed` step is `server_run: true`, and the README
   says conflating the two is the easiest way to launder a claim into a receipt. Read
   literally, `--server-run-only` promises the steps the server ran, failures included, which
   is not what the flag does. `--server-verified` matches `Verdict::isVerified()`, the
   predicate actually used. Leaning that way; the flag is unshipped, so the name is free.

---

## Resolved Questions

0. **Is `--server-run-only` the right name?**
   **Decision:** No. Shipped as `--server-verified`.
   **Rationale:** The spec's own argument against the first name held. `Result::serverRun()`
   answers who produced a verdict, and is true for `failed` and `error` too — the README calls
   conflating that with passing the easiest way to launder a claim into a receipt. Read
   literally, `--server-run-only` promises the steps the server ran, failures included.
   `--server-verified` matches `Verdict::isVerified()`, which is the predicate actually used.

1. **Why not answer this per scope, with the tag machinery?**
   **Decision:** No. A tag-scoped predicate needs the walk, so `VerifyCommand` would load
   and execute consumer PHP to answer a question about a JSON file — a real change to that
   command's risk profile, for precision nobody has asked for. It also drags in a staleness
   argument (a tracked config edit moves the fingerprint, so a tag map read from disk is
   safe) that holds only while the consumer tracks their config. Tags are the right
   vocabulary for choosing what to *run*, where the config is already loaded. The scoped case
   is also already served: a consumer who scopes at `open_run` gets a shell-only receipt
   whose `all_verified` is true, and `--only=backend` works today. The unserved shape is
   specifically the unscoped run holding acknowledgements, and this flag answers exactly that.
   Revisit only when a consumer needs per-scope verify.

2. **Why not `--step=<id>`?**
   **Decision:** No. A step id is project-chosen, so a downstream skill cannot name one
   generically; it would need a check-to-step-id mapping written down somewhere, which is
   coupling with none of the validation a config-declared tag gets.

3. **Why not name the passing steps in the non-zero output and let a caller read that?**
   **Decision:** No. It gives a caller prose to parse instead of an exit code, and makes
   every message a compatibility surface. The exit code is the interface.

4. **Why not let a consumer read `receipt.json` themselves?**
   **Decision:** No. Freshness is a comparison against a fingerprint whose algorithm lives
   here. A consumer doing it themselves either re-implements that or omits it, and the second
   is both more likely and more dangerous.

5. **Why a new `coverage` key rather than reusing `all_verified`?**
   **Decision:** A new key. `all_verified` is one boolean covering two unrelated failures — an
   acknowledgement and a dropped gate — and the whole point of this flag is to accept the
   first while still refusing the second. Splitting them at the receipt is the only place the
   distinction survives the session. Widening `all_verified` instead would change what every
   existing consumer reads.

6. **Why does an absent `coverage` key fail rather than default to clean?**
   **Decision:** Fail. A receipt written before this release did record notices in memory and
   dropped them on the way to disk, so "absent" genuinely means unknown. Defaulting it to
   clean would make every pre-upgrade receipt answer a question it never measured — and that
   is the same false green the key exists to close.

## Findings

- **Both false greens were live in a first implementation before this spec landed.** A probe
  confirmed it: a `running` receipt holding one pass exited 0, and so did a `complete` receipt
  from a run whose walk had dropped a declared step. The spec's insistence that coverage be
  persisted was not theoretical.
- **The "neither string nor int" key branch is unreachable.** A PHP array key is always
  `string|int`, so strict parsing reduces to rejecting a non-string *value* and casting the key.
  The static analyser caught the dead branch.
- **A non-passing server verdict on a `complete` receipt cannot come from a live run.** A verdict
  that is not a pass holds the cursor, so the state guard catches it first. The branch is kept and
  tested for a receipt that came from elsewhere, and the test says so.
