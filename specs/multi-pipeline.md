# Multi-Pipeline

<!-- spec:planned-at 5ce1a8973f99bbe53fc812df195707436a4cca07 2026-08-24 -->

## Overview

A project declares one pipeline today, and the walk it produces is the only question the
package can answer. A release, a PR check and an evaluation loop are different questions with
different steps and different orders, and there is no way to hold verified answers to more than
one of them at a time.

This lets `.config/pipeline.php` return a named map of pipelines. Each keeps its own phases,
steps, receipt and verdicts, so a project can hold "the PR pipeline verified this tree" and "the
release pipeline verified this tree" as two independent, simultaneously true facts.

## Terminology

The word "pipeline" already carries two meanings and this feature adds a third. The spec uses:

| Term | Meaning |
|---|---|
| **the package** | `sandermuller/boost-pipeline` itself |
| **a pipeline** | one named `Pipeline` instance, its phases and its steps |
| **the pipeline map** | the `array<string, Pipeline>` a config file may now return |
| **a scope** | a tag selection *within* one pipeline — the existing `only:` mechanism, unchanged |

A scope narrows one pipeline's walk. A pipeline name selects which walk exists at all. They
compose, and they are not alternatives to each other.

## Assumptions

<!-- Filled by the Assumptions Audit. Each bullet is one AI-introduced inference, kept so the
     spec can be signed off by skimming this section alone. -->

- **Declaration shape** — `.config/pipeline.php` returns either a `Pipeline` (as today) or an
  `array<string, Pipeline>`. Confirmed by the user; see Resolved Question 1.
- **Pipelines are fully independent** — each declares its own phases and steps, and duplicating a
  step across two pipelines is expected rather than a smell. Confirmed; see Resolved Question 2.
- **A bare `pipeline:verify` refuses when the map holds more than one** — it names them and asks
  for `--pipeline=`. Confirmed; see Resolved Question 3.
- **The agent picks a pipeline with an argument on `open_run`** — one MCP server, one tool set.
  Confirmed; see Resolved Question 4.
- **A run is held per pipeline, and the agent moves between them.** Each keeps its own cursor.
  Confirmed by the user; see Resolved Question 5.
- **The `pipeline` argument is required on every tool that acts on a run** — `next_step`,
  `report_step` and `status` as well as `open_run` — whenever the config declares a map. A single
  pipeline keeps the argument absent, so nothing changes for a project with one. AI-chosen, and
  load-bearing: see STOP condition 4.
- **Resuming is conditional on the tree not having moved.** Switching away and back resumes only
  when nothing was edited in between; otherwise the existing tree rule discards that run. This is
  today's behaviour applied per run, not a new rule. AI-chosen.
- **Receipts move to `storage/logs/pipeline/receipts/<name>.json`,** and the legacy
  `receipt.json` is not read. Confirmed; see Resolved Question 6.
- **A legacy single `Pipeline` is named `default`.** That name is what `--pipeline=` and the
  receipt filename use, and it is not otherwise reserved — a map may declare `default` itself.
- **A pipeline name must match `^[a-z0-9][a-z0-9-]*$`.** The name becomes a filename component,
  so anything else is a config error rather than a sanitised value. AI-chosen; not load-bearing —
  a wider pattern changes only the validation rule.
- **An empty map is a config error.** A file that opts in and declares nothing is a mistake, and
  the alternative is a project whose tools register with nothing behind them. AI-chosen.
- **Every pipeline in the map is validated at server start,** not lazily when first opened, and
  `configError()` builds each pipeline's walk so duplicate step ids fail at boot too. Traced
  rather than assumed: config closures already run at `require` time, but `duplicateStepId`
  throws from `Walk::resolve()`, which nothing calls at start — so today it surfaces at
  `open_run`. Building the walks closes that, and changes the single-pipeline case to fail
  earlier than it does now. AI-chosen; see section 3.
- **`StepRunner` gains a factory rather than a new contract argument.** Its `run()` signature is
  public API that consumers implement, and changing it was already a breaking change once
  (UPGRADING, 0.2.0). AI-chosen.
- **The envelope carries `pipeline` only when the config declares a map.** Consistent with how
  `scope` appears only for a scoped run, so nothing changes for a single-pipeline project.
  AI-chosen.

---

## 1. Current state

Every layer assumes exactly one pipeline. The chain, in the order it binds:

| Location | What is singular |
|---|---|
| `src/Config/PipelineLoader.php:18` | `CONFIG_PATH` is one fixed relative path |
| `src/Config/PipelineLoader.php:33` | `load(): ?Pipeline` — one instance or null |
| `src/BoostPipelineServiceProvider.php:38` | `Pipeline::class` is a container singleton |
| `src/BoostPipelineServiceProvider.php:51` | the one `StepRunner` is built with *that* pipeline's timeout |
| `src/BoostPipelineServiceProvider.php:72` | one `JsonReceiptStore` on one hardcoded path |
| `src/BoostPipelineServiceProvider.php:75` | one `RunManager`, constructed with the one pipeline |
| `src/Run/RunManager.php:28` | `private ?Run $run` — the session's single run |
| `src/Mcp/Tools/NextStep.php:50` | `$this->runs->current()` — three tools resolve "the" run |
| `src/Console/VerifyCommand.php:34` | resolves one `ReceiptStore` and answers from it |

Two of these are more than mechanical:

**The timeout coupling.** `ProcessStepRunner` is constructed once, and its timeout comes from
`Pipeline::timeoutSeconds()` (`src/BoostPipelineServiceProvider.php:51`). With a map, the runner
cannot be a plain singleton any more, because two pipelines may declare different timeouts.

**The receipt is the whole point.** One file at one path is what makes today's scopes
non-accumulating — a second scoped run replaces the first, which the README already records as a
limitation. Per-pipeline receipts are what turn that limitation into the feature being asked for.

Step-id uniqueness is asserted per walk (`src/Walk/Walk.php:41`), so two pipelines declaring a
step called `phpstan` need no special handling. `Walk` and `Receipt` need no knowledge of a
pipeline's name at all.

`Run` does need it, for one reason: the envelope reports it. `Run` already carries
`public readonly ?string $scope` as a promoted constructor property (`src/Run/Run.php:75`) for
exactly the same purpose, so a `$pipeline` beside it is the existing pattern rather than a new
one, and `StepPayload::envelope()` reads it the way it already reads `scope`.

**Step logs need no change.** `LogWriter` names a file `<runId>-<stepId>.log`
(`src/Runner/LogWriter.php:20`), and run ids are random per run, so a `phpstan` step in two
pipelines writes two files. Checked because a shared `storage/logs/pipeline` directory is the
obvious place to expect a collision, and there is none.

## 2. Config surface

```php
// .config/pipeline.php
return [
    'pr' => Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint --test', id: 'pint'));
            $steps->in(StaticAnalysis::class)->append(Shell::run('vendor/bin/phpstan', id: 'phpstan'));
        }),

    'release' => Pipeline::configure()
        ->withSteps(function (Steps $steps): void {
            $steps->in(StaticAnalysis::class)
                ->append(Shell::run('vendor/bin/phpstan', id: 'phpstan'))
                ->append(Shell::run('composer audit', id: 'audit'));
            $steps->in(Agent::class)->append(Skill::run('/release-notes'));
        }),
];
```

A file that still returns a bare `Pipeline` keeps working and is named `default`.

New value object, `SanderMuller\BoostPipeline\Config\Pipelines`:

```php
final readonly class Pipelines
{
    /** @param array<string, Pipeline> $pipelines */
    private function __construct(public array $pipelines) {}

    public function get(string $name): ?Pipeline;
    /** @return list<string> */
    public function names(): array;
    public function isSingle(): bool;
    public function sole(): Pipeline;   // throws InvalidPipelineConfigException when several
}
```

`PipelineLoader::load()` returns `?Pipelines` rather than `?Pipeline`. **This is a breaking
change to a public class**, so it lands in a pre-1.0 minor with an UPGRADING entry.

### Config errors

Each throws `InvalidPipelineConfigException`, naming the offending key where there is one:

| Shape returned | Message names |
|---|---|
| neither a `Pipeline` nor an array | the actual type (existing behaviour, kept) |
| `[]` | that the file declared no pipelines |
| a non-string key | that a map is required, and that a bare list produces integer keys |
| a value that is not a `Pipeline` | the key and the actual type |
| a name failing `^[a-z0-9][a-z0-9-]*$` | the name and the pattern |

## 3. Wiring

`Pipeline::class` stays bound for the single-pipeline case, resolving to `Pipelines::sole()`, so
an existing consumer resolving it is unaffected. It throws when the map holds several, which is
the honest answer to "give me the pipeline" in a project with three.

Two factories replace two singletons:

```php
$this->app->singleton(StepRunnerFactory::class, ...);   // ->for(string $pipeline): StepRunner
$this->app->singleton(ReceiptStoreFactory::class, ...); // ->for(string $pipeline): ReceiptStore
```

`StepRunner` and `ReceiptStore` stay bound for the single case, for the same reason
`Pipeline::class` does.

`RunManager` takes `Pipelines` and both factories, and holds a run **per pipeline** rather than
one for the session:

```php
-    private ?Run $run = null;
+    /** @var array<string, Run> */
+    private array $runs = [];

public function open(?string $pipeline = null, ?string $selection = null): Run;
public function for(string $pipeline): ?Run;   // replaces current()
```

Each pipeline keeps its own cursor, so an agent can walk `pr` to step 4, work on `release`, and
come back to `pr` where it left off. The rules already in `src/Run/RunManager.php:40` do not
change; they become **per entry** instead of global:

1. `$pipeline` is null and the map holds one → that one. Null and the map holds several → an
   error naming them. Never a default.
2. `$pipeline` names nothing configured → an error naming what is configured. Never a fallback.
3. `$runs[$pipeline]` exists with a different selection → discard **that entry only** and start a
   new run for it. Other pipelines' runs are untouched.
4. `$runs[$pipeline]` exists and its tree has moved → today's rule, applied to that entry: rebaseline
   when it has recorded nothing, otherwise discard it.
5. Same pipeline, same selection, tree unmoved → that entry, unchanged.

**Resuming is not a promise that survives an edit.** Rule 4 is what makes switching back safe
rather than misleading: a `pr` run measured before an edit cannot resume after one, because its
verdicts describe code that no longer exists. The agent gets a fresh `pr` run instead, which is
the same answer it gets today when a tree moves under a single run.

Run ids are already random per run (`src/Run/Run.php:92`), so several open at once need no
change to stay distinguishable.

`RunManager::isOpen()` (`src/Run/RunManager.php:72`) has no caller in `src/` or `tests/`. Delete
it rather than porting it to a map.

### Config validation at server start

`configError()` (`src/BoostPipelineServiceProvider.php:136`) resolves `Pipeline::class` inside a
`try`. Rebinding that to `Pipelines::sole()` would make it **throw for every multi-pipeline
project**, from the one path whose job is to turn a config error into a readable message. It must
resolve `Pipelines::class` instead.

What that validates is narrower than it looks, and the spec is precise about it because the
distinction is not obvious:

- `withPhases()` and `withSteps()` run their closures immediately (`src/Config/Pipeline.php:47`,
  `:55`). So a bad phase anchor, an empty tag, a non-positive timeout and every parallel-group
  rule already throw during `require`, inside the existing `try`. With a map, that covers every
  pipeline, because loading the file constructs all of them.
- `duplicateStepId` is the exception: it throws from `Walk::resolve()`
  (`src/Walk/Walk.php:41`), which nothing calls at start. Today a duplicate id in a
  single pipeline surfaces at `open_run`, not at boot.

Left alone, that asymmetry gets worse with a map: a duplicate id in `release` would fail only
when `release` is first opened, in a session that may only ever run `pr`. Closing it is cheap —
building a walk is pure in-memory work — so `configError()` builds every pipeline's walk. That
also makes the single-pipeline case fail earlier than it does today, which is a behaviour change
in the direction of failing loud and sooner.

## 4. Receipts

One file per pipeline, under a new directory:

```
storage/logs/pipeline/receipts/pr.json
storage/logs/pipeline/receipts/release.json
```

The legacy `storage/logs/pipeline/receipt.json` is not read. After upgrading, the first
`pipeline:verify` reports no run recorded until the pipeline is run once — the fail-closed
direction, and consistent with how an absent `coverage` or `asserted` key already reads as
unknown rather than clean.

`Receipt` itself does not change. The name is the filename, not a field: a receipt read from
`receipts/pr.json` is by construction the `pr` receipt, and storing the name inside as well would
create two sources of truth that can disagree.

Converting a project from a single pipeline to a map leaves `receipts/default.json` behind,
unread, the same way the upgrade leaves `receipt.json` behind. Both are litter in a directory a
Laravel app already gitignores, and both are safe to delete. Documented, not handled — a package
that deletes files it no longer recognises is worse than one that leaves them.

## 5. `pipeline:verify`

```
{--pipeline= : Which pipeline to ask about. Required when the project declares more than one.}
```

Composes with the two existing options rather than replacing them — `--pipeline` selects the
walk, `--only` selects a scope within it, `--server-verified` selects which verdicts count:

```bash
php artisan pipeline:verify --pipeline=release
php artisan pipeline:verify --pipeline=pr --only=backend --server-verified
```

Resolution, before any receipt is read:

| Situation | Result |
|---|---|
| map holds one, no `--pipeline` | answers for it |
| map holds one, `--pipeline` names it | answers for it |
| map holds several, no `--pipeline` | exit 1, naming every configured pipeline |
| `--pipeline` names nothing configured | exit 1, naming every configured pipeline |
| no config file at all | exit 1, "no pipeline run has been recorded" (existing behaviour) |

The refusal in row 3 is the same rule a scoped receipt already follows: a run that answers a
narrower question does not answer the broad one. There is deliberately no aggregate "every
pipeline is green" answer — a project that routinely runs only `pr` could never reach exit 0
through it, and a gate that cannot pass is one people learn to skip.

### What row 3 costs outside this package

A downstream caller that runs `php artisan pipeline:verify` with no arguments gets exit 1 the
moment a project adopts a map. That is correct — the question genuinely has no single answer —
but it is a silent capability loss for any consumer that gates on the bare call, and it will not
look like this feature's doing.

The fix belongs on the calling side: pass `--pipeline=`. This spec does not change any consumer,
but the release notes must say plainly that adopting a map turns the bare call into an error, so
a project converting its config knows to update whatever gates on it first.

## 6. MCP surface

All four tools gain a `pipeline` argument, because several runs can be open at once and none of
them is "the" run:

```
open_run(pipeline: "release")
open_run(pipeline: "pr", only: "backend")
next_step(pipeline: "pr")
report_step(pipeline: "pr", verdict: "passed", summary: "...")
status(pipeline: "release")
```

**The argument is required exactly when the config declares a map, and absent when it declares a
single pipeline.** A static rule that never guesses. The alternative — defaulting to the most
recently opened run — is rejected: an agent that omits the argument would advance the wrong
pipeline's cursor, execute the wrong steps and write a verdict into the wrong receipt. That is a
worse failure than an error message, and it is silent.

`Illuminate\JsonSchema\Types\Type::required()` exists, so the schema can declare it. **The
schema is not the guard.** A declared-required argument is what a well-behaved client sends, not
what an ill-behaved one is prevented from omitting, so every run-acting tool validates the
argument in its handler and errors there. The schema makes it discoverable; the handler makes it
safe.

`open_run`'s schema description lists the configured names, so the agent reads them from the tool
rather than from the config file.

`RunManager::current()` becomes `for(string $pipeline)`. It has exactly three callers —
`src/Mcp/Tools/NextStep.php:50`, `src/Mcp/Tools/ReportStep.php:53` and
`src/Mcp/Tools/Status.php:51` — and each resolves its own run. The "No run is open" error gains
the pipeline name.

Every payload envelope gains `pipeline`, present only when the config declares a map — the same
rule `scope` follows in `StepPayload::envelope()`. The server `instructions` and the
`run_pipeline` prompt gain a paragraph on choosing one and on keeping the argument consistent
across a walk.

**Out of scope:** a `status` call that summarises every open run at once. Useful, but it needs a
second output shape, and the four-tool surface stays smaller without it.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Config returns `[]` | Config error naming the file. Phase `loader`, Tests |
| Config returns a bare list, so keys are integers | Config error that names the integer key and says a map is required. Phase `loader`, Tests |
| A map value is not a `Pipeline` | Config error naming the key and the actual type. Phase `loader`, Tests |
| A name has a slash, a dot, or is empty | Config error naming the pattern. The name is a filename component, so this is rejected rather than sanitised. Phase `loader`, Tests |
| Two map keys spelled identically | Undetectable — PHP collapses them at parse time and the later wins. Recorded as a limitation, not handled. Phase `docs` |
| Two pipelines declare the same step id | Allowed. Uniqueness is asserted per walk at `src/Walk/Walk.php:41`, and the walks are separate. Phase `loader`, Tests |
| Two pipelines declare different timeouts | Each gets its own `StepRunner` from the factory. Phase `wiring`, Tests |
| `open_run()` with no name, map holds several | Error naming them. Never a default. Phase `mcp`, Tests |
| `open_run(pipeline: "nope")` | Error naming what is configured. Phase `mcp`, Tests |
| `open_run(pipeline: "pr")` while a `release` run is mid-walk | Both runs are held. The `release` cursor stays where it was. Phase `wiring`, Tests |
| Returning to a pipeline after walking another, tree unmoved | Resumes at its own cursor. Phase `wiring`, Tests |
| Returning to a pipeline after an edit moved the tree | Does **not** resume. Rule 4 discards it — its verdicts describe code that no longer exists — and a fresh run starts. Existing behaviour, applied per entry. Phase `wiring`, Tests |
| Returning to a pipeline that recorded nothing before the tree moved | Rebaselines and keeps its id, as a single run already does. Phase `wiring`, Tests |
| Re-opening the same pipeline and scope on an unmoved tree | The existing run, unchanged — today's idempotency rule, now per entry. Phase `wiring`, Tests |
| `open_run(pipeline: "pr", only: "backend")` when an unscoped `pr` run exists | That entry is replaced; a `release` run open at the same time is untouched. Phase `wiring`, Tests |
| `next_step` with no `pipeline` while a map is configured | Error naming the configured pipelines. Never the most recently opened — advancing the wrong cursor is silent and worse than an error. Phase `mcp`, Tests |
| `next_step(pipeline: "release")` when only a `pr` run is open | The existing "no run is open" error, naming `release`. Phase `mcp`, Tests |
| `next_step` with a `pipeline` argument while a single pipeline is configured | The argument is absent from the schema in that case, so this is a client-side error, not a case the tool handles. Phase `mcp`, Tests |
| Two runs open, one reaches `complete`, the other is mid-walk | Independent. Each writes its own receipt; neither state affects the other. Phase `wiring`, Tests |
| `pr` verified, then the tree moves, then `release` runs | Each receipt records its own tree. `--pipeline=pr` fails on staleness while `--pipeline=release` passes. No change needed; covered to prove it. Phase `verify`, Tests |
| A legacy `receipt.json` is present after upgrading | Not read. The first verify reports no run recorded. Phase `receipts`, Tests |
| A project on the legacy single-`Pipeline` file passes `--pipeline=release` | Exit 1 naming `default`, the only configured name. Phase `verify`, Tests |
| A pipeline in the map has a config error but the session only runs another | The server still fails loud at start, as a single bad pipeline already does. Phase `loader`, Tests |

## Implementation

### Phase 1: Load a pipeline map (Priority: HIGH)

**ID:** `loader` · **Depends:** none

- [x] Add `Config\Pipelines` — a readonly map with `get()`, `names()`, `isSingle()`, `sole()` — the one place a name resolves to a `Pipeline`.
- [x] Change `PipelineLoader::load()` to return `?Pipelines`, accepting both a bare `Pipeline` (named `default`) and a map.
- [x] Add the five config errors in section 2 to `InvalidPipelineConfigException`, each naming the offending key or name.
- [x] Validate every name against `^[a-z0-9][a-z0-9-]*$` at load, because the name becomes a filename component.
- [x] Tests — both accepted shapes; each of the five error shapes with the key it names; two pipelines sharing a step id resolving to two clean walks; a name that would escape the receipts directory.

### Phase 2: Wire a runner and a receipt per pipeline (Priority: HIGH)

**ID:** `wiring` · **Depends:** `loader`

- [x] Add `StepRunnerFactory::for(string $pipeline): StepRunner`, so a per-pipeline timeout stops being read once at boot (`src/BoostPipelineServiceProvider.php:51`). The `StepRunner` contract is untouched.
- [x] Add `ReceiptStoreFactory::for(string $pipeline): ReceiptStore`, resolving `storage/logs/pipeline/receipts/<name>.json`.
- [x] Rebind `Pipeline::class`, `StepRunner::class` and `ReceiptStore::class` to the sole pipeline, throwing when the map holds several, so existing resolvers keep working in the single case.
- [x] Point `configError()` at `Pipelines::class` — resolving `Pipeline::class` there would throw for every multi-pipeline project, from the one path that exists to report config errors readably — and build each pipeline's walk so duplicate step ids fail at boot rather than at `open_run`.
- [x] Delete `RunManager::isOpen()`; it has no caller in `src/` or `tests/`.
- [x] Change `RunManager` to hold `array<string, Run>`, replace `current()` with `for(string $pipeline)`, and apply the five resolution rules in section 3 per entry.
- [x] Carry the pipeline name on `Run` beside `scope` (`src/Run/Run.php:92`), so the envelope has something to read. Done here rather than in `mcp`, which would otherwise write the same file this phase writes.
- [x] Tests — a config error in any pipeline of a map failing at server start, including a duplicate step id; a per-pipeline timeout reaching the right runner; two pipelines writing two receipt files; two runs open at once with independent cursors; resuming a pipeline after walking another; a moved tree discarding only the entry it belongs to; a scope change replacing only its own entry; the rebaseline rule holding per entry.

### Phase 3: Choose a pipeline from the agent (Priority: HIGH)

**ID:** `mcp` · **Depends:** `wiring`

- [x] Add the `pipeline` argument to all four tools, present in the schema only when the config declares a map, with the configured names in `open_run`'s description.
- [x] Error, naming every configured pipeline, when the argument is omitted with several configured or names one that is not — in every tool, never a most-recently-opened default.
- [x] Point the three run-acting tools at `RunManager::for()` and name the pipeline in their "no run is open" error (`src/Mcp/Tools/NextStep.php:50` and siblings).
- [x] Emit `pipeline` in the envelope when the config declares a map, reading the name `Run` carries, following the rule `scope` already uses in `StepPayload::envelope()`.
- [x] Update the server `instructions` and the `run_pipeline` prompt to cover choosing one and keeping the argument consistent across a walk.
- [x] Tests — opening each pipeline; a full walk of one while another is open; both error shapes on every tool; the argument absent from the schema for a single pipeline; the envelope key present for a map and absent for a single pipeline; `pipeline` and `only` composing.

### Phase 4: Ask about one pipeline (Priority: HIGH)

**ID:** `verify` · **Depends:** `wiring`

- [x] Add `--pipeline=` to the `pipeline:verify` signature (`src/Console/VerifyCommand.php:28`).
- [x] Implement the five-row resolution table in section 5 ahead of reading any receipt, so the message names the real problem — the ordering rule invariant 19 already follows.
- [x] Confirm `--pipeline` composes with `--only` and `--server-verified` without widening what exit 0 claims.
- [x] Tests — every row of the resolution table; the composition of all three options; two pipelines verified independently against one tree; one pipeline stale while the other is fresh.

### Phase 5: Document it (Priority: MEDIUM)

**ID:** `docs` · **Depends:** `mcp`, `verify`

- [x] README — the map form, that pipelines are independent, and how a pipeline differs from a scope.
- [x] README limitations — identically spelled keys are undetectable; scopes still do not accumulate *within* a pipeline; a pre-upgrade `receipt.json` is left on disk and never read, so it can be deleted.
- [x] UPGRADING — `PipelineLoader::load()` returns `Pipelines`; the receipt path moved and the legacy file is not read; `Pipeline::class` throws when several are configured; **adopting a map turns a bare `pipeline:verify` into an error**, so anything gating on it needs `--pipeline=` first.
- [x] `.ai/docs/invariants.md` — a false-green row for reading one pipeline's pass as the project's.
- [x] Tests — none; prose only. Verified by `boost sync` reporting no drift.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **`Receipt` needs no pipeline field.** The design puts the name in the filename alone. If any
   consumer of a receipt turns out to need the name from inside the file, stop: two sources of
   truth for one fact is the shape that produced the scope and coverage defects already fixed.
2. **The pipeline name reaches `Run` as a label and nothing more** — carried the way `scope`
   already is, and read only by the envelope. If the name has to reach the **cursor**, the walk,
   or a verdict to make any of this work, stop: the boundary is wrong and the phase plan needs
   re-cutting before more is built on it.
3. **The single-pipeline path stays behaviourally identical** apart from the receipt location. If
   rebinding `Pipeline::class` to `Pipelines::sole()` changes anything a single-pipeline project
   observes, stop — the compatibility promise in section 3 is what keeps this a minor.
4. **A required `pipeline` argument is enough to stop the wrong cursor advancing.** The whole
   safety of several open runs rests on it. If the MCP layer turns out to accept a call without
   it, or a client can send one the schema forbids, stop and report — a silently advanced cursor
   executes the wrong steps and writes into the wrong receipt, which is a worse failure than any
   this feature fixes.

---

## Open Questions

None.

---

## Resolved Questions

1. **How should a project declare more than one pipeline?** **Decision:** `.config/pipeline.php`
   returns either a `Pipeline` or an `array<string, Pipeline>`. **Rationale:** one config
   location, and a file returning a single `Pipeline` keeps working, so no existing project
   breaks.
2. **Should pipelines share step definitions?** **Decision:** fully independent — each declares
   its own phases and steps. **Rationale:** a shared library would define a step's phase and
   position globally, which puts back the constraint that makes tags insufficient. Duplicating a
   step across two pipelines is the accepted cost.
3. **What should a bare `pipeline:verify` answer with several configured?** **Decision:** refuse,
   naming them, and ask for `--pipeline=`. **Rationale:** the rule a scoped receipt already
   follows. An "every pipeline green" aggregate was rejected because a project that routinely
   runs one pipeline could never reach exit 0, and an unpassable gate is one people stop using.
4. **How should the agent choose a pipeline?** **Decision:** a `pipeline` argument on the tools.
   **Rationale:** one server and one tool set. A server per pipeline multiplies the four tools in
   the agent's context for every pipeline declared. Note that Resolved Question 5 widened this
   from `open_run` alone to all four tools.
5. **Should several runs be open at once?** **Decision:** yes — a run per pipeline, each with its
   own cursor, and the agent moves between them. **Rationale:** an agent that hits a blocking
   failure in one pipeline can work in another without losing its place, and re-walking a
   nine-step release pipeline to get back to where it was is the cost the alternative imposes.
   The price is that every run-acting tool now needs to say which pipeline it means, and that
   resuming is conditional on the tree not having moved.
6. **Where do receipts live, and is the legacy file read?** **Decision:**
   `storage/logs/pipeline/receipts/<name>.json`, and `receipt.json` is not read. **Rationale:**
   one path rule with no special case, and the fail-closed direction the package already takes
   for an absent `coverage` or `asserted` key. The cost is one spurious exit 1 per consumer after
   upgrading, until the pipeline runs once.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **`configError()` would have thrown for every multi-pipeline project.** Section 3's rebinding
  of `Pipeline::class` to `Pipelines::sole()` collides with
  `src/BoostPipelineServiceProvider.php:136`, which resolves it inside the `try` whose whole job
  is turning a config error into a readable message. Caught while checking the spec's own claims
  against the code; the fix is a task in `wiring`.
- **"Validated at server start" meant less than it sounded.** Config closures run at `require`
  time, so most errors already fail at boot — but `duplicateStepId` throws from `Walk::resolve()`
  and nothing builds a walk at start, so today it surfaces at `open_run`. The spec now says so,
  and closes it rather than inheriting the asymmetry into a map.
- **No step-log collision.** Checked, because a shared `storage/logs/pipeline` directory looks
  like one. `LogWriter` keys filenames on the random run id.
- **`RunManager::isOpen()` is dead.** No caller in `src/` or `tests/`.
- **`Type::required()` exists**, so the schema can declare the argument required — but the schema
  is a contract with well-behaved clients, not a guard, so the handler validates too.

### During implementation

- **`Pipelines` grew four methods the spec did not list** — `single()`, `has()`, `count()` and
  `implied()`. `implied()` is the load-bearing one: "the name to use when the caller gave none, or
  null when there is no answer" is asked by `RunManager`, `VerifyCommand` and the `ReceiptStore`
  binding, and each deriving it from `isSingle()` plus `names()[0]` would have been the same rule
  written three times.
- **`RunManager`'s receipt factory stayed nullable, and the parameter order was kept.** The spec
  showed `(Pipelines, StepRunnerFactory, ReceiptStoreFactory, ?TreeFingerprint)`. The old signature
  ended `(?TreeFingerprint, ?ReceiptStore)` and several tests pass the fingerprint positionally, so
  the order is unchanged and the factory is optional — a run that records no receipt was always
  allowed, and forcing a store on it would have made every test write files.
- **The three run-acting tools were touched in `wiring`, not `mcp`.** Changing `RunManager`'s API
  makes them uncompilable, so the `current()` to `for()` move had to land with it. The DAG is
  unaffected — `verify` does not touch those files — but the tree is red between the two phases,
  which a parallel wave would have had to account for.
- **`configError()` now catches a duplicate step id, which it never did before.** Building every
  walk at start closes the asymmetry the spec identified, and it changes single-pipeline behaviour:
  that error used to surface at `open_run`. Covered by a test that says so.
- **Test helpers, not per-file wiring.** `runManagerFor()` in `tests/Pest.php` wraps one pipeline
  into the map shape for the ~10 existing call sites; `useReceiptStore()` and `projectDeclaring()`
  do the same for `VerifyCommandTest`. Without them every existing test would have carried three
  lines of factory construction that had nothing to do with what it asserts.
### From the Codex review

- **`next_step` and `status` declared no input schema at all.** Both had only `outputSchema()`, so
  the helper that adds the `pipeline` argument was never wired into them. Their handlers demanded a
  name a conforming client had no way to send: a multi-pipeline walk would have stopped dead
  straight after `open_run`. The tests missed it because they pass arguments through the harness
  rather than through schema validation, so "refuses next_step without a name" passed by proving
  the guard rejects — never that a client can satisfy it. Now covered for all four tools, plus a
  full named walk end to end.

- **The name requirement keyed off the count, not the declaration shape.** The spec says required
  "when the config declares a map"; the code said "when there is more than one pipeline", so
  `['pr' => ...]` behaved like a bare `Pipeline`. That is a silent cliff: every call site that
  omitted the name would break on the day a second pipeline is added, and the ones that kept
  working would be the ones that had been guessing. `Pipelines` now records whether the file named
  its pipelines. `requiresName()` drives the MCP argument and the run label; `soleName()` stays
  count-based for the container aliases and the bare `pipeline:verify`, which the spec's own
  resolution table says a map of one should answer.

- **`ReceiptStoreFactory::for()` interpolated any string into a path.** The loader validates every
  name it accepts, but the factory is public API, and `JsonReceiptStore` creates parent directories
  on write — so `for('../../escape')` would have written outside the receipts directory. It now
  rejects a name the project does not declare, which is a stronger check than re-running the
  pattern: a receipt for an undeclared pipeline is meaningless anyway.

- **Two findings of my own, before Codex ran.** `StepRunner::class` resolved to `names()[0]` in a
  multi-pipeline project — silently handing back a runner carrying another pipeline's timeout,
  where the sibling `Pipeline::class` and `ReceiptStore::class` bindings throw. All three now share
  one `registerSolePipelineAliases()` method, which also replaced three near-identical comments
  with one. And `Pipelines::count()` was public API with a single internal caller; inlined.

- **Round two: the documented runner seam was silently broken.** The README says "bind your own
  over the container's and every step the server resolves goes through it". Routing runs through a
  factory that always constructed `ProcessStepRunner` ignored that binding without a word — a
  custom step kind would simply stop being handled. `StepRunner::class` is now bound separately as
  the seam it is, and the factory returns whatever is bound over it, varying only the shipped
  runner per pipeline (which is all the per-pipeline timeout ever needed). `ReceiptStore::class`
  deliberately does NOT honour an override the same way: one store shared across pipelines would
  collapse every receipt into one, which is the exact problem this feature exists to fix. It is not
  a documented seam, so the alias keeps its refuse-when-several behaviour.

- **Round three, and one of its two findings was a false positive.** It reported that a consumer
  subclassing `ProcessStepRunner` would be silently replaced by the `instanceof` discriminator.
  `ProcessStepRunner` is `final` (`src/Runner/ProcessStepRunner.php:24`), so no such subclass can
  exist — the package's own guidelines prefer final classes precisely to close that surface. The
  "fix" was written, the test for it would not compile, and both were reverted. Recorded because
  the reasoning was plausible and the premise was one grep away.

- **A custom `ReceiptStore` binding was silently ignored.** Real, and accepted in part. `RunManager`
  and `VerifyCommand` now reach receipts through the factory, so an override stopped taking effect.
  It is honoured again while the project declares one pipeline, where nothing is ambiguous. For a
  map it is deliberately not consulted: one store across several pipelines collapses every receipt
  into one, which is the defect named pipelines exist to fix. UPGRADING states both halves.

- **A test that asserted presence where the contract is a constraint.** The four-tool schema test
  checked that a `pipeline` key exists; it would have passed with `->required()` deleted. It now
  asserts the required flag itself.

- **One assertion was weakened, then fixed rather than left.** The tools-level resume test first
  asserted a position string the MCP response does not expose, so it briefly asserted only that the
  pipeline name came back — which the test name did not claim. It now asserts the resumed cursor
  through the step ids: `pint` holds a verdict, `phpstan` is being offered, and nothing from the
  pipeline walked in between appears.
