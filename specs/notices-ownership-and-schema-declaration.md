# `notices` ownership and undeclared envelope keys

<!-- spec:planned-at c1b3d7d6150c474a65527e02ffb8aa7c4ac6d537 2026-08-28 +uncommitted -->

## Overview

`notices` is a property of the **walk** — known the moment a run opens. It is currently emitted
from inside the block gated on `results() !== []`, which is the gate for `all_verified` and
`stale`, both properties of **results**. Two consequences: `status` on a freshly opened run whose
config dropped a declared gate reports nothing about it, and `OpenRun` has to assemble `notices`
a second time to work around the gate. Separately, neither `notices` nor `stale` is declared in
`envelopeSchema()`, yet three tools emit both.

## Assumptions

- **The three changes split, and the schema declaration ships alone first.**
  Declaring already-emitted keys is near-zero risk; ungating `notices` is a real payload change.
- **The schema change WIDENS, never narrows.** Declaring a key a tool already emits cannot break a
  consumer, and it is the direction `tests/Unit/ToolContractTest.php` exists to enforce.
  Load-bearing — if a proposed edit would narrow the EFFECTIVE schema of any tool, that is a STOP.

  Both keys are declared on the shared envelope. `notices` is emitted by all four tools after
  Phase 2. `stale` is emitted routinely by three and rarely by `open_run` — see Finding 1, which
  records a decision made one way and then reversed. The contract test proves a key IS declared,
  never that the tool can emit it, so it cannot police an over-declaration; that is a reason to
  think carefully, not a reason to under-declare a key a tool can send.
- **Tool names are untouched.** They are pinned explicitly (`protected string $name`) because
  `laravel/mcp` defaults them to kebab-case and a mismatch shipped as a real defect
  (`.ai/docs/laravel-mcp-notes.md`). Nothing in this spec goes near them.
- **`InvalidConfigServer` / `ExplainInvalidConfig` are unaffected** — they do not use
  `PipelineTool` and never reach this code.
- **Emitting `notices` earlier is a fix, not a regression.** This is the one genuine judgement
  call; see `## Open Questions` 1. No test depends on the key's absence (checked), but it is a
  payload behaviour change, so Phase 2 writes its test first.
- **The existing `open_run` test does NOT prove the payload is unchanged.** `tests/Feature/ScopedRunTest.php:116` only checks the response CONTAINS
  `notices` and `[bakend]`. It does not compare the payload, verify the presence condition, or
  verify absence when there are no notices. Treat it as a smoke test, not as the byte-identity
  guarantee — Phase 2 adds the real assertions.

---

## 1. Current state

**Split assembly.** `src/Mcp/StepPayload.php:135-157` builds `notices` and `stale` inside:

```php
if ($run->results() !== []) {
    $verification = $run->verification();
    $envelope['all_verified'] = $verification['all_verified'];
    $envelope['acknowledged'] = $run->acknowledgedCount();
    if ($verification['stale'] !== null) { $envelope['stale'] = $verification['stale']; }
    if ($run->walk->notices !== []) { $envelope['notices'] = $run->walk->notices; }
}
```

`src/Mcp/Tools/OpenRun.php:79-81` then assembles `notices` again (the `$payload = ...` line is `:77`):

```php
$payload = StepPayload::opened($run);
if ($run->walk->notices !== []) { $payload['notices'] = $run->walk->notices; }
```

That second copy exists **because** the envelope suppresses it at open time. `StepPayload`'s own
docblock (`:14-19`) claims it builds every tool response body so the rules "cannot drift per
tool" — this is that drift, already present.

**Undeclared keys.** `src/Mcp/Tools/Concerns/PipelineTool.php:84-101` — `envelopeSchema()`
declares `run`, `state`, `position`, `scope`, `pipeline`, `all_verified`, `acknowledged`. Neither
`notices` nor `stale`. `notices` is declared only in `OpenRun`'s own schema (`:57`). But
`next_step`, `report_step` and `status` compose `envelopeSchema()` plus a result/step schema
(`NextStep.php:56-63`, `ReportStep.php:44-51`, `Status.php:51-64`) and all three emit both keys
once a result exists.

This is the defect class `tests/Unit/ToolContractTest.php:66-76` was written for — its own
comment records that "two keys had already shipped undeclared".

## 2. Proposed changes

Match each key's presence condition to what the key is *about*:

- **Walk-derived** (`notices`) → keyed off the walk, available from `open_run` onward.
- **Result-derived** (`all_verified`, `acknowledged`, `stale`) → keyed off results, unchanged.

```php
// StepPayload::envelope() — hoisted OUT of the results-gated block
if ($run->walk->notices !== []) {
    $envelope['notices'] = $run->walk->notices;
}

if ($run->results() !== []) {
    // all_verified / acknowledged / stale stay exactly as they are
}
```

Then delete `OpenRun.php:79-81` and declare both `notices` and `stale` on `envelopeSchema()`,
beside `all_verified` — the key that already sets the pattern for one that is declared once and
present only under a condition (Finding 1 records why a per-tool split was tried first and dropped).

`OpenRun`'s local `notices` declaration at `:57` must be REWRITTEN rather than moved. It reads
"such as a dropped transition step", and no such step exists — `StepKind` holds `Shell` and `Skill`
only, and notices come from a step declared into an unregistered phase (`Walk.php:143`) or a tag
selection no step carries (`Walk.php:48`). Moving it would spread a wrong description from one
schema to four (Finding 2).

## Edge Cases

| Scenario | Handling |
|----------|----------|
| `status` on a freshly opened run with a dropped gate | Now reports `notices`. This is the behaviour fix. Covered by Phase 2 Tests (written before the change). |
| `next_step` whose FIRST position is a skill step | Returns `awaiting` with no results (`Run.php:192`), so the hoist adds `notices` to that response too. This is the case the agent most needs it in: it is about to do skill work on a walk that can never fully verify. Phase 2 tests it. |
| `next_step` on an empty walk that raised notices | Goes straight to `complete` with no results (`Run.php:182`), so the hoist adds `notices` there as well. Phase 2 tests it. |
| `open_run` on a run with notices | Same keys, same values, same presence condition. **Key ORDER shifts** — `notices` moves from a tail append in `OpenRun` into `envelope()`, so it now precedes `total_steps` and the step keys. Assert with `toEqual` (order-insensitive), never `toBe`. |
| Run with results **and** notices | Both `notices` and the result-derived keys present, as today. No change. |
| Run with no notices | Key absent, as today. Absence is meaningful and must stay — do not emit an empty array. |
| A consumer reading `notices` only from `open_run` | Still works; the key appears in strictly more responses than before, never fewer. |

## Implementation

### Phase 1: Declare the keys already being emitted (Priority: HIGH)

**ID:** schema · **Depends:** none

Ships alone. Pure declaration of existing behaviour; no payload changes.

- [x] Add `notices` to `envelopeSchema()` in `src/Mcp/Tools/Concerns/PipelineTool.php`. Do NOT copy `OpenRun.php:57`'s description — **rewrite it**. See Finding 2: the existing wording describes a step type this codebase does not have.
- [x] Add `stale` to `envelopeSchema()` alongside `notices`. See Finding 1: this was first implemented as a separate `staleSchema()` spread by three tools, then reversed — `open_run` can emit `stale` through a race the codebase deliberately declines to close, and `all_verified` already sets the pattern for a conditionally-present key on the shared envelope.
- [x] Leave `OpenRun`'s local `notices` declaration in place for this phase. `OpenRun::outputSchema()` spreads `envelopeSchema()` first (`:52`), so its own literal at `:57` wins and the duplicate is harmless in the interim. Phase 2 removes it.
- [x] Tests — extend `tests/Unit/ToolContractTest.php`'s "declares every key a payload can actually contain" case: assert `notices` on all four tools, and `stale` on `next_step`, `report_step` and `status`. Assert `open_run` does NOT declare `stale`, so the exclusion is pinned rather than incidental. This case must FAIL before the change and pass after — confirm that.
- [x] Fix the obsolete comment at `src/Run/Run.php:377`. See Finding 4: it names the same transition concept that does not exist, as the reason `walk->notices` blocks verification.

### Phase 2: Give `notices` one assembly site, test-first (Priority: HIGH)

**ID:** single-owner · **Depends:** schema

Depends on `schema` so the key is declared before it is emitted from a new place, and so the two
phases do not both edit tool files concurrently.

**Write the failing test BEFORE the production change.** An earlier draft of this spec put the
hoist in this phase and its test in a later one, which meant the behaviour shipped untested for a
phase, contradicting this spec's own claim that the behaviour change is "sequenced last". The behaviour and its proof now land together.

- [x] Tests FIRST — a `status` call on a freshly opened run (no results yet) whose config declared a step into an unregistered phase: assert `notices` is present and names the dropped step. **Confirm it FAILS against current `main`** — that failure is the whole justification for the change.
- [x] Tests FIRST — capture the `open_run` payload for a run WITH notices and a run WITHOUT, as explicit before/after assertions (see the next task for why the existing test is not enough).
- [x] Hoist the `notices` block in `src/Mcp/StepPayload.php` out of the `results() !== []` gate, keyed on `$run->walk->notices !== []`.
- [x] Delete the duplicate assembly at `src/Mcp/Tools/OpenRun.php:79-81`.
- [x] Remove `OpenRun`'s now-redundant local `notices` schema entry (`:57`) if its description moved in Phase 1.
- [x] Tests — mutation check per `.ai/docs/invariants.md`: re-gate `notices` on `results() !== []` and confirm the fresh-run test goes red. If it does not, the test is not pinning the fix.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The EFFECTIVE schema must only widen.** Effective means what a tool's `outputSchema()` finally returns, not where a key is defined. Moving `notices` from `OpenRun`'s literal into `envelopeSchema()` removes a line from `OpenRun` and is expressly NOT a stop, because the composed result still declares the key — Phase 2 does exactly this. Stop only if a tool's effective schema loses or narrows a key. The published schemas are a compatibility surface asserted by `ToolContractTest`.
2. **A tool's `protected string $name` needs touching.** It does not. The four names are pinned deliberately because `laravel/mcp` defaults them to kebab-case, and a mismatch has already shipped as a real defect once. If a change appears to require renaming, stop.
3. **`open_run`'s PAYLOAD loses or gains a key, a value, or its presence condition in Phase 2.** Payload, not schema: `open_run`'s effective schema is unchanged by this spec (Finding 1 keeps `stale` out of it), and its payload must stay as it is. Those must not change. **Key ORDER is expected to change and is NOT a stop** — `notices` moves from a tail append into `envelope()`, so it lands earlier. An earlier draft of this spec demanded "byte-identical" here, which is unachievable and a trap: an implementer writing the required before/after assertion with `toBe` would see it fail and be told by this very section to stop. Compare with `toEqual`. `tests/Feature/ScopedRunTest.php:116-124` going red IS a stop signal, but its passing is not sufficient proof — it is a contains-check.
4. **An existing test asserts the ABSENCE of `notices` on a no-results response.** None was found, but if one surfaces, that is a deliberate contract this spec would break — stop and re-decide Open Question 1 before proceeding.

---

## Open Questions

None.

---

## Resolved Questions

1. **Does emitting `notices` earlier on `status` / `next_step` count as an unwanted payload change?**
   **Decision:** Proceed. It is a fix.

   **Rationale.** A walk holding notices can never report `all_verified: true` — `Run.php:406`
   forces it false while `walk->notices` is non-empty. So the run's outcome is already decided at
   open time, and the agent is the party that has to act on it. The case that settles it is the
   skill step: `next_step` on a walk whose first position is a skill step returns `awaiting` with no
   results, so today the agent is asked to do the work with no way to learn that a declared gate was
   dropped. Withholding a fact the agent needs until after it acts is the defect, not the fix.

   It widens rather than narrows, and no test depends on the key's absence (checked). Phase 2 still
   writes the proving test first, so the change is visible as a failing test before any production
   line moves.

2. **Should `stale` also be ungated?** **Decision:** No — leave it gated on results.
   **Rationale.** It is result-derived: `Run::verification()` needs a recorded measurement to
   compare a tree against, so before the first result there is genuinely nothing to answer. Recorded
   so the asymmetry with `notices` is a decision rather than an oversight.

3. **Should `stale` be declared on the shared envelope, as originally planned?** **Decision:** Yes,
   after first deciding the opposite. **Rationale.** See Finding 1 — `open_run` CAN emit it, through
   a race the codebase deliberately declines to close, and a key a tool can emit but never declares
   is the defect this spec exists to fix. `all_verified` already sets the pattern: declared on the
   shared envelope, present only once a result exists.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

### Evaluation before implementation

Every `file:line` the spec cites was re-read and still points at what the spec describes. The stamp
carries `+uncommitted`, so the baseline was treated as unknown rather than diffed: of the cited
files, only `NextStep.php` and `ScopedRunTest.php` changed in the 38 commits since, and neither
changed in a way that affects this spec. Four corrections came out of that pass, all confirmed by an
independent review.

**Finding 1 — `stale` on the shared envelope: decided twice, and the second answer is right.**
The spec assumed "both keys are already emitted today" and put both on `envelopeSchema()`. The
assumption is wrong for `stale` on `open_run` in the ordinary path: `RunManager::open()` discards a
stale run and starts a fresh one, so the payload `StepPayload::opened()` builds normally has no
staleness to report.

That led to a first decision — declare `stale` only on the three tools that emit it, through a
dedicated `staleSchema()` — on the reasoning that `ToolContractTest` proves a key is DECLARED but
never that the tool can emit it, so an over-declaration would be invisible to the suite policing the
schema.

That was the wrong trade, and it was reversed. The race is real and reachable: `RunManager` reads the
tree once through `condition()`, `StepPayload::envelope()` reads it again through `verification()`
(`Run.php:399`), and a tree that moves between the two captures yields a stale `open_run` payload for
an existing run holding results. Sharing one capture is not available as a fix — `RunManager` states
plainly that it will not thread its digest onward, because a replaced or rebaselined run must read
the tree fresh rather than baseline against a moment already past. So the key can arrive.

Between advertising a key that seldom arrives and emitting one that was never declared, this package
has to prefer the first: an undeclared emitted key is the exact defect the spec was written to fix.
`all_verified` settles it by precedent — already on the shared envelope, already present only once a
result exists, already declared by `open_run` on runs that will not emit it. Conditionally-present
keys on the shared envelope are the established convention, and `staleSchema()` was the anomaly.

Recorded at length because the first answer was defensible and still wrong: exactness about which
tool declares what lost to honesty about what a tool can send.

The reversal then needed evidence of its own, and a review round said so: the whole decision rests on
`open_run` being able to emit `stale`, and nothing tested that. `NoticesOwnershipTest` now pins it —
a run holding a result, a tree fingerprint that moves afterwards, and `StepPayload::opened()` gaining
the key. Asserted both ways, so the path cannot quietly disappear and leave the declaration
unearned. It is deliberately not driven through the tool: doing that means flipping the digest
between two captures inside a single call, which couples the test to how many times the tree is read
and would break on refactors that change nothing about this behaviour.

**Finding 2 — the description the spec told me to preserve is wrong.** Phase 1 originally said to
copy `OpenRun.php:57` verbatim, calling it "richer". It reads "Config problems found while resolving
the walk, such as a dropped transition step." There is no transition step in this codebase:
`StepKind` holds `Shell` and `Skill`, and `Walk` has no transition concept at all. Notices come from
a step declared into an unregistered phase (`Walk.php:143`) and from a tag selection no step carries
(`Walk.php:48`). Copying it would have promoted a wrong description from one tool's schema to the
shared one read by four. Rewritten instead — a deliberate deviation from the spec's instruction,
logged here per the deviation contract.

**Finding 3 — two more responses gain `notices`.** The Edge Cases table covered `status` and
`open_run`. The hoist also reaches `next_step` when the first position is a skill step (`awaiting`,
no results, `Run.php:192`) and when the walk is empty (`complete`, no results, `Run.php:182`). The
first of those is the strongest argument for the whole change, so it is now the rationale in Resolved
Question 1 as well as a row in the table.

**Finding 4 — `Run.php:377` repeats the same phantom.** Its comment gives "a transition whose
anchors are not adjacent" as a reason `walk->notices` blocks verification. Same non-existent
concept as Finding 2, in the docblock explaining the very property this spec is about. Folded into
Phase 1.

### During implementation

**Phase 1's description test had to split.** The spec has Phase 1 declare `notices` on the shared
envelope while `OpenRun` keeps its own literal until Phase 2. `OpenRun::outputSchema()` spreads the
envelope first, so its literal still won — meaning a single test asserting the corrected wording on
all four tools could not pass until Phase 2. Split into two: one case pins that the key is declared
on all four (Phase 1), another pins the wording, covering three tools in Phase 1 and extended to all
four in Phase 2 once the literal is gone. The phase boundary stays real rather than being papered
over by a test that spans it.

**Every new test was confirmed red before the change.** Phase 1: 5 failures, including `open_run`
failing the "no transition" assertion against its own stale literal. Phase 2: 3 failures, and the
absence case green from the start — it pins existing behaviour rather than new. The Phase 2 mutation
check the spec asks for was run: re-gating `notices` on `results() !== []` turns those same 3 red.

**`Skill::run()`, not `Skill::invoke()`.** A first draft of the skill-step test used a factory that
does not exist. Noted only because the error surfaced as a fatal rather than a failed assertion, so
it is worth knowing the file will not even load if that name is wrong.

**Finding 5 — the STOP conditions could have stopped the spec's own plan.** STOP 1 said to stop if
an edit "removes ... an existing key in ... a tool's `outputSchema()`", but Phase 2 deliberately
removes `OpenRun`'s local `notices` literal once the shared schema carries it. STOP 3 said
`open_run` must not gain a key, which Phase 1 would have violated under the original `stale` plan.
Both now distinguish the EFFECTIVE schema (what `outputSchema()` finally returns) from the runtime
payload, so a correct implementation is no longer told to halt.
