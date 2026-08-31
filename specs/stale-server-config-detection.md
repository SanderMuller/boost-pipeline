# Detect a server running a config other than the one on disk

<!-- spec:planned-at 07b99f3b08d999fe060daf7b6d0357b816ed6ec2 2026-08-31 -->

## Overview

The MCP server resolves the pipeline config once when its process starts. A config edited after
that is invisible to every run until the client reconnects, so the server can run a *different
definition of the same step id* and record it as a pass. Nothing detects that today.

This records a fingerprint of the loaded config in the run record, and has `pipeline:verify` compare
it against the config on disk. A mismatch means the server ran something other than what the project
declares, which makes every verdict it produced suspect.

## Assumptions

- **A config fingerprint adds nothing for "the config changed since the run" — as long as git can see
  the config file.** `GitTreeFingerprint` hashes HEAD plus the contents of every dirty and untracked
  path (`src/Runner/GitTreeFingerprint.php:37-65`), so a config file that is committed, modified or
  merely untracked is already covered and any edit to it already fails the gate on the tree check.

  There are three exceptions, and the digest covers all of them because it is computed from the
  config the server actually **loaded** rather than from git's view of a file:

  1. **A gitignored config.** `git status --porcelain=v1 -z --untracked-files=all` is called without
     `--ignored` (`:43`), so an ignored path contributes nothing to the digest.
  2. **A tracked symlink.** Git fingerprints the link's target path, not the target's contents, so a
     symlinked config stays clean while what it points at changes. `PipelineLoader::load()` does
     `require $this->path()` (`src/Config/PipelineLoader.php:43`), which follows the link and executes
     the target.
  3. **A composed config.** `require` is arbitrary PHP, so `.config/pipeline.php` may pull in files
     that are ignored or live outside the repository. Those contribute nothing to the tree digest.

  So the last row of the inference table below is not stale-server-only. That does not weaken the
  design — it widens what the check is worth — but the refusal must not assert staleness as the cause.
  See Resolved Question 5.

  Load-bearing: if the tracked-file half of this is wrong, the spec's scope is wrong — it would then
  also have to cover "config changed since the run" as a first-class case rather than inheriting it
  from the tree check.
- **For a git-visible config, the only gap left is a stale server.** Reaching the new check requires a
  matching tree, which means the config on disk at run time is the config on disk now. If the digest
  still differs, the server held a third version. See `## 2`. For a gitignored config the check also
  catches a plain edit, which the tree cannot see at all.
- **The tree check running first is a feature, not an obstacle.** `pipeline:verify` returns on a
  moved tree (`src/Console/VerifyCommand.php:81`) before any config-level guard, so a config
  comparison placed after it only ever runs when the tree matches — exactly the condition that makes
  "config differs therefore the server was stale" a sound inference rather than a guess. A consumer
  observed the same ordering from outside and read it as a diagnosis gap; for this design it is what
  makes the diagnosis exact.
- **The digest covers everything that defines a step**, per an explicit decision: command, scope
  command, env keys, timeout, mutating flag, tags, kind, invocation, step order, phase registration,
  batch grouping and the pipeline timeout. Env VALUES are deliberately excluded — see Resolved
  Question 0, which narrows Resolved Question 2 after a false-failure risk surfaced.
- **A mismatch fails every gate, scoped or not**, per an explicit decision. One digest over the whole
  declaration cannot say which scope changed, and a stale server's in-scope verdicts are suspect
  regardless. See Resolved Question 1.
- **An absent digest is refused only where the precedent already refuses unknown.** `coverage`'s
  "recorded before this existed" refusal lives inside `answerServerVerified()` alone
  (`src/Console/VerifyCommand.php:216-238`); the bare `pipeline:verify` never reads `coverage`. So an
  absent digest refuses under `--server-verified` and is ignored by the bare call. A digest that is
  PRESENT and differs refuses on both. This keeps the strict flag strict without failing every
  consumer's pre-push hook on upgrade day for a receipt that is otherwise sound. See Resolved
  Question 4.

- **The config must be deterministic for this check to work, and that is a real constraint on the
  consumer.** `.config/pipeline.php` is arbitrary PHP (`PipelineLoader::load()` does `require`), so
  ANY hashed field can depend on the environment, the clock, randomness, or a file outside the repo —
  not just env values. Two honest processes can then produce different digests from unchanged files,
  and the gate fails with nothing wrong. Excluding env values narrows the common case but does not
  establish stability. Load-bearing, and handled three ways rather than assumed away: the message
  names this cause, the README states the requirement, and a documented config toggle exists for a
  consumer whose config is deliberately dynamic. See Resolved Question 5.
- **Coverage is complete for the package's own step types and partial for a custom `Step`.** The
  `Step` contract exposes only `id`, `description`, `kind`, `mutates` and `tags`. Command and env live
  on `Shell`, invocation on `Skill`, so the digest reads those through type checks. A consumer's own
  `Step` implementation contributes the contract surface only. Not closeable without adding a method
  to a published contract, which is a major bump.
- **`Pipeline::walk()` owns the computation; `Walk` only carries the result.** `Pipeline` is the
  object that holds the whole declaration, so it is the only place that can fingerprint it.
  `Walk::resolve()` neither computes nor needs the digest — it receives it and stores it. The
  distinction matters: computing from `$walk->steps` would silently fingerprint only the SELECTED
  scope, so a scoped run would record a digest that no unscoped comparison could match. `Run::start()`
  receives a `Walk` and not a `Pipeline`, and its signature has already grown twice, so carrying it
  on `Walk` adds no parameter to it.

---

## 1. Current state

**The server resolves config once.** `RunManager::open()` builds a run from
`$this->pipelineNamed($name)->walk($selection)`, and `Pipelines` is resolved from the container when
the server process boots. Nothing re-reads the config file for the life of that process.

**Nothing records what the server loaded.** `Receipt` holds `runId`, `state`, `allVerified`, `tree`,
`stale`, `verdicts`, `recordedAt`, `scope`, `coverage` and `asserted` (`src/Run/Receipt.php:28-80`).
No field describes the config. Confirmed by absence: no digest, command, or step definition is
persisted anywhere in `src/`.

**`pipeline:verify` compares step ids, not definitions.** As of 0.12.0 it refuses a run missing a
step the config declares (`src/Console/VerifyCommand.php:403`). A step that *is* recorded passes that
check whatever its definition was when it ran.

**The tree check returns first.** `src/Console/VerifyCommand.php:81` returns `FAILURE` on a moved
tree, ahead of the scope and declared-step guards.

**`declaredButNeverRecorded()` currently guesses its own cause.** Its message names a stale server as
"the usual cause" because nothing available to it could establish that. This spec removes the need to
guess, so the guess has to go with it — a hedge left in place beside a mechanism that now answers
the question reads as a second, weaker opinion.

## 2. What this catches that nothing catches today

A stale server running a different definition of the same step id:

1. `pint`'s command changes in `.config/pipeline.php`.
2. The server still holds the old command in memory.
3. A run happens. The tree already contains the new config, so the fingerprint is correct and stays
   correct.
4. The server runs the **old** command and records `pint: passed`.
5. `pipeline:verify`: tree matches, every declared step has a verdict, `coverage: complete`,
   `all_verified: true` → **exit 0**.

The step that ran is not the step the config declares. The 0.12.0 guard cannot see it, because `pint`
has a verdict — that guard catches a step the server never *heard of*, this is a step it heard of
*differently*.

The same shape applies to a `->mutating()` flag added or removed (which changes what `asserted`
means, so the gate can report that a rewriting step verified the tree), to a tag change (which moves
a step between scopes), and to a changed scope command, env or timeout.

**The inference the two digests give, together:**

| tree | config | Means |
|---|---|---|
| differs | not reached | Config and/or code changed since the run. Already handled, message unchanged. |
| matches | matches | Nothing to report. |
| matches | differs | The declaration the run used is not the declaration on disk now. |

The last row has several possible causes — a stale server, a config git cannot see (ignored,
symlinked, or composed from outside the repo), or a config that computes part of its declaration at
load time. The check cannot tell them apart, so the message states the observable fact and lists the
causes rather than accusing the server. The first action is the same for the first two
(reconnect the client, open a new run); the third is a configuration problem the message has to name,
or a consumer will hunt a stale server that does not exist. See Resolved Question 5.

## 3. The fingerprint

A new `SanderMuller\BoostPipeline\Config\PipelineFingerprint` with one static entry point:

```php
public static function for(Pipeline $pipeline): string
```

It hashes a canonical array built from the **whole declaration**, never the scoped walk:

- `Phases::all()` — registered phase class-strings, in order.
- `Steps::declaredPhases()`, and for each, `Steps::entriesForPhase()` in order, so step order and
  batch grouping are both covered (a `StepBatch` exposes `public array $steps`).
- Per step: `id()`, `kind()->value`, `tags()`, `mutates()`, and by type:
  - `Shell` — `command()`, `scopeCommand()`, `timeoutSeconds()`, and the **keys** of `env()` but not
    their values (Resolved Question 0).
  - `Skill` — `invocation()`, `proof()`, and `description()`. `proof()` is a shell command that
    decides whether the step passes (`src/Steps/Skill.php:109`), so omitting it would let a stale
    server run an old proof undetected — the same hole as an old command, on the step type where the
    server is the only thing checking anything. `description()` is `$this->instruction ?? "Invoke
    …"` here, and the instruction is the work handed to the agent verbatim, so it is part of the
    declaration; there is no `instruction()` accessor, and `description()` is the way to reach it.
- `Pipeline::timeoutSeconds()`.

Not hashed for `Shell`: `description()`. It is `$this->description ?? $this->command`
(`src/Steps/Shell.php:49-52`), so relying on it would **hide** a command change behind a custom
description — the exact case this exists to catch. `command()` is read directly instead. `Skill` is
the opposite: its `description()` is the instruction, which is declaration rather than label, so it
is hashed there.

Stability rules, because a digest that differs between two processes reading the same config is a
false-failure generator: no object identities, no closures, no absolute paths, and a fixed key order.
Comparison is always same-machine, same-checkout.

## 4. Where it is recorded and compared

- `Walk` gains a `configDigest` property. `Pipeline::walk()` computes it via `PipelineFingerprint`
  and passes it to `Walk::resolve()`. `Pipeline::walk()` is the only caller
  (`src/Config/Pipeline.php:101`).
- `Receipt` gains `?string $config = null`, appended last, serialised as `config`. `toArray()` already
  drops nulls through `array_filter`, and `fromArray()` reads keys defensively — the same shape
  `coverage` uses.
- `Run` records `$this->walk->configDigest` when it writes a receipt. No new constructor parameter.
- `pipeline:verify` computes `PipelineFingerprint::for($pipeline)` and compares. Placed **after** the
  tree and staleness checks so it speaks only when the tree matches, and **before**
  `declaredButNeverRecorded()` so a root cause reports ahead of its symptom.
- A new `verify.config_fingerprint` key in the published `config/boost-pipeline.php`, defaulting to
  true, sitting beside the existing `ui` block. False skips the comparison — the escape for a
  deliberately dynamic config (Resolved Question 5). Its comment block must say that and nothing
  friendlier, since switching it off gives up a real check.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Receipt predates the field (`config` absent) | Refuse, naming the upgrade and telling the reader to open a new run. Unknown is not clean, matching `coverage`. Phase `gate` Tests. |
| Tree moved **and** config differs | Tree message only — it returns first, and "open a new run" is the same advice. Deliberate; Phase `gate` Tests assert the tree message still wins. |
| Scoped gate, change in another scope | Fails. One whole-declaration digest cannot attribute a change to a scope, and a stale server's in-scope verdicts are suspect anyway. Resolved Question 1. Phase `gate` Tests. |
| Config declares no steps at all | Digest still computes over an empty structure and is stable. Phase `fingerprint` Tests. |
| Project declares several pipelines | One digest per pipeline, recorded in that pipeline's own receipt. A change to pipeline B must not alter pipeline A's digest or fail its gate. Two pipelines declaring the same steps share a digest, which is correct — it describes a declaration, not an identity. Phase `fingerprint` and `gate` Tests. |
| Same steps, different order | Digest differs — order decides which checks see a mutating step's output. Phase `fingerprint` Tests. |
| Same steps, regrouped into a parallel batch | Digest differs. Batch grouping changes how a position resolves. Phase `fingerprint` Tests. |
| A custom `Step` implementation changes behaviour without changing its contract surface | Not detected. Stated in the README limitations table rather than left to be discovered. Phase `docs`. |
| A step's `env` value is computed at config load and differs between processes | Not a failure: env values are outside the digest, keys only. Resolved Question 0. Phase `fingerprint` Tests assert a changed value does not move the digest and a changed key does. |
| Config computes part of its declaration at load time | Digests differ between processes with unchanged files, so the gate fails persistently. The message names this cause, the README states the requirement, and `verify.config_fingerprint: false` is the way out. Phase `gate` Tests assert both the message and the toggle. |
| A stale server runs an old `Skill` proof or instruction | Caught: `proof()` and `description()` are hashed for a skill step. A proof is a shell command that decides whether the step passes, so leaving it out would reproduce the motivating hole on the step type where the server is the only thing checking anything. Phase `fingerprint` Tests. |
| `.config/pipeline.php` is gitignored | The tree fingerprint cannot see it, so an edit moves nothing and the receipt passes today. The digest catches it, because it is computed from the loaded config rather than from git. Phase `gate` Tests cover a changed config with an unchanged tree, which is this case and the stale-server case at once. |
| Config file unreadable or a parse error at verify time | Unchanged by this spec, and NOT covered by the 0.12.0 guard's catch as an earlier draft of this table claimed: that catch surrounds `$pipeline->walk()` inside `declaredButNeverRecorded()`, while `PipelineLoader::load()` runs while Laravel resolves the injected `Pipelines`, before `handle()` is entered. A parse error therefore surfaces as an unhandled exception with a non-zero exit — fail-closed, but not a message this command composed. Stated so nobody reads the new guard as having fixed it. |

## Implementation

### Phase 1: The fingerprint, on its own (Priority: HIGH)

**ID:** fingerprint · **Depends:** none

Pure computation, no wiring, no behaviour change. Ships and is reviewable alone.

- [x] Add `src/Config/PipelineFingerprint.php` with `for(Pipeline $pipeline): string` — canonical array per `## 3`, hashed with `xxh3` to a short digest, matching `SafeFilename` and `GitTreeFingerprint` conventions.
- [x] Read `Shell` and `Skill` specifics through type checks; a step that is neither contributes its contract surface only.
- [x] Tests — same config gives the same digest across two independently built `Pipeline` instances; each input in `## 3` changes it when changed alone (command, scope command, env KEY, timeout, mutates, tags, kind, invocation, order, batch grouping, phase registration, pipeline timeout); a changed env VALUE does NOT change it; an empty config is stable; two pipelines with DIFFERENT declarations differ, while two with identical declarations match — the digest describes a declaration, not an identity, and does not receive the pipeline name.

### Phase 2: Record it (Priority: HIGH)

**ID:** record · **Depends:** fingerprint

- [x] `Walk` gains `configDigest`; `Pipeline::walk()` computes and passes it. Note in the docblock that it describes the whole declaration and is therefore identical for every scope of one pipeline.
- [x] `Receipt` gains `?string $config = null`, appended last, with a docblock stating what absent means.
- [x] Register `config` in `Receipt::fieldsAreWellFormed()`'s string-key list, which is an explicit array (`['tree', 'stale', 'recorded_at', 'scope', 'coverage']`, `src/Run/Receipt.php:162`). Skipping this is silent: a non-string `config` would pass validation and then be dropped by `fromArray()`, so a corrupt digest would read as absent — which this spec treats as "unknown", not as "corrupt".
- [x] `Run` writes it into the receipt.
- [x] Tests — a receipt round-trips the digest; an absent key reads as null rather than failing to parse; a scoped and an unscoped run of one pipeline record the same digest.

### Phase 3: Gate on it (Priority: HIGH)

**ID:** gate · **Depends:** record

- [x] `pipeline:verify` compares the receipt's digest against `PipelineFingerprint::for($pipeline)`, after the tree and staleness checks and **before** `declaredButNeverRecorded()`. Order decides which message a reader gets: a stale server is the root cause and a missing step is its symptom, so the config mismatch must speak first or the reader is sent hunting for a step when the real answer is "reconnect the client".
- [x] Soften the guess in `declaredButNeverRecorded()`'s message. It currently says "the usual cause is a step declared after the MCP server process started", because it had no way to know. With the config guard ahead of it, a message reaching that point has already ruled a stale server out — so the guess is now actively misleading and must go.
- [x] The refusal states the observable fact — the declaration the run used is not the declaration on disk now — then names the causes in likelihood order: a server that loaded the config before it changed, a config git cannot see, and a config that computes part of its declaration at load time. It must NOT assert staleness as the cause. Resolved Question 5.
- [x] Honour a `verify.config_fingerprint` config key (default true). False skips the comparison entirely, for a consumer whose config is deliberately dynamic.
- [x] Refuse an absent digest under `--server-verified` only, naming the upgrade, matching where `coverage` already refuses unknown. The bare call ignores an absent digest. Resolved Question 4.
- [x] Tests FIRST, each confirmed red before the change — a matching digest passes; a differing digest fails the bare call, `--server-verified`, and a scoped call; an absent digest fails `--server-verified` and does NOT fail the bare call; a moved tree still reports the tree message and not this one; the message names the dynamic-config cause; `verify.config_fingerprint: false` passes a differing digest. Then a mutation check: remove the comparison and confirm the failing cases go green.

### Phase 4: Let the run surfaces say it (Priority: MEDIUM)

**ID:** surfaces · **Depends:** record

Write-disjoint from `gate`, so the two can run as one wave.

- [x] `LiveProgress` carries the digest, so an in-flight run can be flagged rather than only a finished one.
- [x] `PipelineOverview` reports whether a run's digest matches the config as it stands now, as a nullable field — null when the receipt predates it, never false.
- [x] The page and `pipeline:history` render it.
- [x] Tests — a run recorded under a changed config reads as mismatched on both surfaces; an absent digest reads as unknown rather than mismatched.
- [x] Document the field in the README's page and history sections.

### Phase 5: Documentation (Priority: HIGH)

**ID:** docs · **Depends:** gate

- [x] README — the new refusal beside the `pipeline:verify` contract, and a row in *what it deliberately does not do* for the custom-`Step` limitation.
- [x] `UPGRADING.md` — the new field, that `--server-verified` refuses a pre-upgrade receipt while the bare call does not, that a passing gate can now exit 1, and the `verify.config_fingerprint` toggle.
- [x] README — state the determinism requirement plainly: a config that computes part of its declaration at load time cannot be fingerprinted across processes, and such a project should set `verify.config_fingerprint` to false.
- [x] `.ai/docs/invariants.md` — a false-green registry row for a stale server running a different definition of the same step id.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The tree fingerprint covers a git-visible config file.** Verified for committed, modified and
   untracked files, and deliberately NOT true for a gitignored one (see `## Assumptions`). If it turns
   out not to hold for a tracked file either, stop: the spec's scope is then wrong, because "config
   changed since the run" would need to become a first-class case here instead of being inherited
   from the tree check.
2. **The digest is stable across two processes reading one STATIC config.** Determinism for a
   dynamic config is not promised — that is what the toggle is for — but a config with no computed
   values must fingerprint identically in the server process and the verify process. If it does not,
   an input is environment-dependent and must come out of the digest. Env values were removed for
   exactly this reason (Resolved Question 0).

5. **The digest must be computed from the whole declaration, never from `$walk->steps`.** The latter
   is scope-filtered, so a scoped run would record a digest no unscoped comparison could match. If the
   implementation finds itself reaching for the walk's steps, stop and re-read Resolved Question 3.
3. **`Pipeline::walk()` is the only `Walk::resolve()` caller.** If another caller exists, threading
   the digest through `Walk` stops being free and the carrier decision needs revisiting. Pre-verified
   at spec time: one caller, `src/Config/Pipeline.php:101`.
4. **No existing test asserts the exact key set of a receipt.** A new key would break it, and that
   test would be stating a contract this spec must not silently change. Pre-verified clean at spec
   time, so this is a tripwire against a test added in between rather than a known risk.

---

## Open Questions

None.

---

## Resolved Questions

0. **Should the digest hash env VALUES, or only env KEYS?** **Decision:** Keys only.

   **Rationale.** `Shell::withEnv()` takes an array resolved when the config loads
   (`src/Steps/Shell.php:121`), so `->withEnv(['TOKEN' => getenv('TOKEN')])` bakes a process-specific
   value into the declaration. The server process and the `pipeline:verify` process load the config
   separately, so two shells with different environments would produce different digests from an
   identical config. That is a gate failing with nothing wrong — a false failure, which is worse than
   the false green this spec closes, because a gate that cries wolf gets switched off and then
   catches nothing at all.

   A key added or removed is a real declaration change and stays in the digest. A value is as likely
   to be ambient as declared, so it comes out. The cost is stated plainly: a stale server passing a
   different VALUE for the same env key is not detected. That is the right side to err on — it
   trades a rare miss for the absence of a common false alarm.

   This narrows Resolved Question 2, which was decided before this risk was visible.

1. **Should a mismatch fail a scoped gate as well as the whole-tree call?** **Decision:** Fail every
   gate. **Rationale:** One digest over the whole declaration cannot say which scope changed, and a
   stale server may have run a different definition of an in-scope step, so its verdicts are suspect
   whichever scope was asked about. The cost is accepted: a change to a frontend step fails a backend
   gate. Chosen over a per-scope digest and over exempting scoped calls, the latter leaving the false
   green open for exactly the callers most likely to be automated.
2. **What should the fingerprint cover?** **Decision:** Everything that defines a step — command,
   scope command, env, timeout, mutating flag, tags, kind, invocation, order, phase registration,
   batch grouping, pipeline timeout. **Rationale:** Any config change the server missed is caught,
   including ones that change no verdict but do change what a verdict means. Chosen over a
   verdict-affecting subset and over a structure-only digest, which would have missed the motivating
   case entirely. Open Question 1 narrows one input of this.
3. **Where does the digest live on the way to the receipt?** **Decision:** On `Walk`, computed by
   `Pipeline::walk()`. **Rationale:** `Walk::resolve()` already receives the whole declaration before
   scope filtering and already derives `notices` and `excluded` from it. `Run::start()` takes a `Walk`
   rather than a `Pipeline` and has already grown two rounds of optional parameters, so this adds
   none.
4. **What does an absent digest mean?** **Decision:** Refuse under `--server-verified`; ignore on the
   bare call. **Rationale:** An earlier draft refused on both, citing `coverage` and `asserted` as
   precedent. That misread them: `coverage`'s unknown-is-not-clean refusal lives only inside
   `answerServerVerified()` (`src/Console/VerifyCommand.php:216-238`), and the bare call never reads
   it. Refusing on both would have gone beyond the precedent it claimed and failed every consumer's
   pre-push hook on upgrade day, for a receipt that is otherwise perfectly sound — a deliberate false
   failure affecting everyone, to close a case that self-corrects on the next run. The strict flag
   stays strict; a PRESENT digest that differs refuses on both calls.

5. **What does the refusal claim, given the digest can differ for reasons other than a stale server?**
   **Decision:** It states the observable fact — the declaration the run used is not the declaration
   on disk now — and names the causes, including a dynamic config. A documented config toggle,
   `verify.config_fingerprint`, lets a consumer whose config is deliberately non-deterministic turn
   the comparison off.

   **Rationale.** `.config/pipeline.php` is arbitrary PHP, so any hashed field can be computed at load
   time from the environment, the clock, or a file outside the repo. Two honest processes then differ
   with unchanged files, and the gate fails forever. Asserting "your server is stale" in that
   situation sends the reader hunting something that does not exist, and a gate that cannot pass is
   one people learn to switch off — at which point it catches nothing at all, which is worse than
   never having shipped it.

   The toggle is deliberately loud rather than convenient: it is documented as being for a dynamic
   config only, and turning it off restores exactly today's behaviour rather than weakening anything
   else. Chosen over shipping a gate with a known permanent-failure mode and no way out, and over
   dropping every field that could be dynamic — `command` and step order can both be computed, and
   they are the feature's essential inputs.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

### Evaluation before implementation

Every cited `file:line` was re-read. Three were off by a few lines and were re-pinned. Two real
problems came out of the pass, both now tasks:

- **`Receipt::fieldsAreWellFormed()` validates from an explicit key list.** A new key not added to it
  is not merely unvalidated — a non-string `config` would pass validation and then be dropped by
  `fromArray()`, so a corrupt digest would read as "unknown", which this design treats as benign.
  Pinned by a test that mutation-checks the registration.
- **Guard order decides which cause a reader is told.** A stale server explains both a changed
  definition and a missing step, so the new guard must report ahead of `declaredButNeverRecorded()`.
  Its "the usual cause is a stale server" hedge also had to go: with a mechanism that answers the
  question, a guess beside it reads as a second, weaker opinion.

### From the independent review

Seven findings, all verified against source before acting on any:

1. **`Skill::proof()` and the instruction were missing from the digest.** A proof is a shell command
   that decides whether the step passes, so a stale server running an old one is the motivating hole
   on the step type where the server is the only thing checking anything. Both added. This was the
   review's most valuable catch — the spec claimed to cover "everything that defines a step" and did
   not.
2. **The central git claim was too broad.** A tracked symlink stays clean while its target changes,
   and a config composed by `require` can pull in files git never sees. Both widen what the digest is
   worth rather than weakening it, and the assumption now says so.
3. **Excluding env values does not establish determinism.** The config is arbitrary PHP, so ANY
   hashed field can be computed at load time. This was the finding that changed the design: the
   refusal now states the observable fact and names a dynamic config as a cause, and
   `verify.config_fingerprint` exists so such a project is not left with a gate that can never pass.
   A gate that cries wolf gets switched off, and then catches nothing at all.
4. **Refusing every legacy receipt was a false failure for every consumer.** The spec cited `coverage`
   as precedent for refusing unknown; re-reading it showed that refusal lives only inside
   `answerServerVerified()`, and the bare call never reads it. Refusing on both would have gone beyond
   the precedent it claimed. Now: the bare call ignores an absent digest, `--server-verified` refuses
   it.
5. **The invalid-config edge case cited a catch that does not cover config loading.** `PipelineLoader`
   runs while Laravel resolves `Pipelines`, before `handle()`. The row now states what actually
   happens instead of crediting the guard with a fix it never made.
6. **The carrier justification overstated `Walk::resolve()`.** It carries the digest; `Pipeline::walk()`
   computes it. The distinction is load-bearing enough to be a STOP condition: computing from
   `$walk->steps` would fingerprint the selected scope, and a scoped run would record a digest no
   unscoped comparison could match.
7. **"Two different pipelines differ" was not a valid invariant.** Two identical declarations SHOULD
   share a digest — it describes a declaration, not an identity, and never receives the pipeline name.

### After release, from runtime use

**The list view did not report the mismatch, only the per-run view.** Phase 4's task said the page and
`pipeline:history` render it, and only the detail surfaces got it: `config_matches` was added to
`RunRow` and `LiveRow` but not to `HistoryRow`, so the list had no access to it. An incomplete task
rather than a missing one.

It matters more than a missing field usually would, because of which run it hides. A stale declaration
leaves the tree matching, so in a real list the refused run was the only row claiming `tree matches`
while the ordinary stale rows around it said `tree moved`. The one run the gate rejects read as the
healthiest thing there. `config moved` now sits before the tree state on the row, because it outranks
it: a run whose declaration moved is refused however well its tree matches.

Confirmed in a real project rather than a fixture: a semantically inert edit (a step timeout from 900
to 901, inside the digest) produced `all_verified: true`, `coverage: complete`, 4/4 passed, a matching
tree, and a digest byte-identical to the pre-edit baseline — the mechanism caught in the act. Both
calls then exited 1, and reverting the edit restored the digest and exit 0, so the check is symmetric
rather than sticky. Cross-process stability also held for a config carrying a closure that resolves
`withEnv()` per checkout, which is what excluding env values buys.

### During implementation

**The new `--server-verified` guard broke 15 existing tests, and that was informative.** Every one
used a fixture predating the field. The fix was to default the fixture to a modern receipt, exactly as
it already defaults `coverage` to `complete`, with `omitConfig: true` for the tests about absence.

**Three of those failures were real test defects, not fixture drift.** They built a run from one
pipeline while the container held another, and nothing had ever compared the two. They now bind the
pipeline they run, which is what a real consumer does.

**Every new guard was mutation-checked.** Dropping each digest input fails exactly the one test
covering it; removing the comparison fails 3 gate tests; removing the `--server-verified` refusal
fails 1; removing `config` from the validation list fails the corrupt-digest test.

**Two cross-process false-failure sources found in self-review, after the external review was
unavailable.** Both are the class STOP condition 2 names, and both are fixed and pinned:

1. **`serialize()` renders a float per `serialize_precision`, an ini setting.** The server process and
   the process comparing digests need not share a php.ini, so a fractional timeout could produce two
   digests from an identical static config. Floats are normalised to a fixed-precision string.
2. **Tag order was hashed.** Selection tests membership, so reordering tags changes nothing a run
   would do; reporting it as a mismatch is a false failure. Tags and env keys are sorted.

The first test written for (1) was itself vacuous, and the mutation check is what exposed it: it used
a timeout of 0.5, which is exactly representable in binary and therefore serialises identically at
any precision. It passed against the very bug it was written for. Changed to 0.1, which does not, and
re-checked — the mutation now fails it. Worth recording because the test looked right and the only
thing that caught it was reverting the fix.
