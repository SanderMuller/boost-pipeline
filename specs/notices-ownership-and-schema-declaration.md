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
- **The schema change WIDENS, never narrows.** Both keys are already emitted today; declaring
  them cannot break a consumer, and it is the direction `tests/Unit/ToolContractTest.php` exists
  to enforce. Load-bearing — if a proposed edit would narrow the schema, that is a STOP.
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

Then delete `OpenRun.php:78-81`, and add both keys to `envelopeSchema()`. `OpenRun`'s local
`notices` declaration at `:57` carries a richer description than a generic one would — prefer
moving that wording into `envelopeSchema()` so there is one description, rather than leaving two.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| `status` on a freshly opened run with a dropped gate | Now reports `notices`. This is the behaviour fix. Covered by Phase 2 Tests (written before the change). |
| `open_run` on a run with notices | Same keys, same values, same presence condition. **Key ORDER shifts** — `notices` moves from a tail append in `OpenRun` into `envelope()`, so it now precedes `total_steps` and the step keys. Assert with `toEqual` (order-insensitive), never `toBe`. |
| Run with results **and** notices | Both `notices` and the result-derived keys present, as today. No change. |
| Run with no notices | Key absent, as today. Absence is meaningful and must stay — do not emit an empty array. |
| A consumer reading `notices` only from `open_run` | Still works; the key appears in strictly more responses than before, never fewer. |

## Implementation

### Phase 1: Declare the keys already being emitted (Priority: HIGH)

**ID:** schema · **Depends:** none

Ships alone. Pure declaration of existing behaviour; no payload changes.

- [ ] Add `notices` and `stale` to `envelopeSchema()` in `src/Mcp/Tools/Concerns/PipelineTool.php`, **copying** (not moving) `OpenRun.php:57`'s richer `notices` description so the wording is preserved. Phase 2 deletes the original.
- [ ] Leave `OpenRun`'s local declaration in place for this phase. `OpenRun::outputSchema()` spreads `envelopeSchema()` first (`:52`), so its own literal at `:57` wins and the duplicate is harmless in the interim. (An earlier draft said "move" here and "leave" in the next breath.)
- [ ] Tests — extend `tests/Unit/ToolContractTest.php`'s "declares every key a payload can actually contain" case to assert `notices` and `stale` on `next_step`, `report_step` and `status`. This case should FAIL before the change and pass after — confirm that.

### Phase 2: Give `notices` one assembly site, test-first (Priority: HIGH)

**ID:** single-owner · **Depends:** schema

Depends on `schema` so the key is declared before it is emitted from a new place, and so the two
phases do not both edit tool files concurrently.

**Write the failing test BEFORE the production change.** An earlier draft of this spec put the
hoist in this phase and its test in a later one, which meant the behaviour shipped untested for a
phase, contradicting this spec's own claim that the behaviour change is "sequenced last". The behaviour and its proof now land together.

- [ ] Tests FIRST — a `status` call on a freshly opened run (no results yet) whose config declared a step into an unregistered phase: assert `notices` is present and names the dropped step. **Confirm it FAILS against current `main`** — that failure is the whole justification for the change.
- [ ] Tests FIRST — capture the `open_run` payload for a run WITH notices and a run WITHOUT, as explicit before/after assertions (see the next task for why the existing test is not enough).
- [ ] Hoist the `notices` block in `src/Mcp/StepPayload.php` out of the `results() !== []` gate, keyed on `$run->walk->notices !== []`.
- [ ] Delete the duplicate assembly at `src/Mcp/Tools/OpenRun.php:79-81`.
- [ ] Remove `OpenRun`'s now-redundant local `notices` schema entry (`:57`) if its description moved in Phase 1.
- [ ] Tests — mutation check per `.ai/docs/invariants.md`: re-gate `notices` on `results() !== []` and confirm the fresh-run test goes red. If it does not, the test is not pinning the fix.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The schema must only widen.** If any edit removes or narrows an existing key in `envelopeSchema()` or a tool's `outputSchema()`, stop. The published schemas are a compatibility surface asserted by `ToolContractTest`.
2. **A tool's `protected string $name` needs touching.** It does not. The four names are pinned deliberately because `laravel/mcp` defaults them to kebab-case, and a mismatch has already shipped as a real defect once. If a change appears to require renaming, stop.
3. **`open_run` loses or gains a key, a value, or its presence condition in Phase 2.** Those must not change. **Key ORDER is expected to change and is NOT a stop** — `notices` moves from a tail append into `envelope()`, so it lands earlier. An earlier draft of this spec demanded "byte-identical" here, which is unachievable and a trap: an implementer writing the required before/after assertion with `toBe` would see it fail and be told by this very section to stop. Compare with `toEqual`. `tests/Feature/ScopedRunTest.php:116-124` going red IS a stop signal, but its passing is not sufficient proof — it is a contains-check.
4. **An existing test asserts the ABSENCE of `notices` on a no-results response.** None was found, but if one surfaces, that is a deliberate contract this spec would break — stop and re-decide Open Question 1 before proceeding.

---

## Open Questions

1. **Does emitting `notices` earlier on `status` / `next_step` count as an unwanted payload change?** It widens rather than narrows, and no test depends on the absence — but it does mean an agent sees notices at a point in the walk where it previously did not. The argument for: a dropped declared gate is exactly what a reader wants to know at `status` time, and withholding it until the first result is the defect. The argument against: it changes what the agent reads mid-walk, and this package is deliberately careful about what reaches the agent's attention. Phase 1 is safe regardless; Phase 2's hoist is what depends on this. Recommend proceeding, but it is a product call — and Phase 2 writes the proving test first, so the decision is visible in a failing test before any production line changes.
2. **Should `stale` also be ungated?** It is result-derived (`Run::verification()` needs a measurement to compare), so gating it on results is arguably correct as-is. This spec deliberately leaves it gated and only declares it. Flagging so the decision is explicit rather than incidental.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
