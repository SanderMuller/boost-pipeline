# The boost-pipelines page

<!-- spec:planned-at 73ad6efe4b60772f1ebf9a95f8875db3117a0747 2026-08-30 -->

## Overview

A web page at `{app}/boost-pipelines` that shows every declared pipeline, the run in
flight, and the runs that came before it. The page polls, so a developer watches a walk
progress without reading the agent's transcript.

Two things the package does not have today make this possible: a record of past runs, and
a record of what is happening between resolutions. Both are storage, and both are worth
having on their own — `pipeline:history` reads them from a terminal, without the page and
without the opt-in a served page needs.

## Assumptions

Every inference this spec introduced, whether confirmed or corrected. Sign off by reading
this section alone.

- **Retention keeps the newest 20 run files per pipeline.** Asked and answered. The number
  was invented; 20 was chosen over 50 and over unbounded growth.
- **A failed step's log renders in the page, on demand.** Asked and answered. Command output
  reaches the browser, which the local-env gate and the opt-in limit but do not remove.
- **The route needs both the config flag and a local environment.** Chosen, not asked. Either
  alone is weaker: a flag committed by mistake would otherwise open the page in production.
  Load-bearing, so it is also a STOP condition.
- **History is keyed by run id and overwritten per resolution.** Chosen from evidence. A run
  resolves many times (`Run::record()` fires on every resolution, `src/Run/Run.php:538`), so
  appending per resolution stores the same run repeatedly.
- **The live record is a separate file, not a field on the receipt.** Chosen. The receipt is a
  record of verdicts that `pipeline:verify` reads; writing in-flight state into it would
  change what an existing consumer sees.
- **The receipt keeps its current path and meaning.** Chosen. History is a second write beside
  it, so nothing that reads `receipts/<pipeline>.json` changes.
- **The view is self-contained Blade with inline CSS and no build step.** Chosen. A package
  cannot assume a host app's asset pipeline.
- **Config file `config/boost-pipeline.php`, env var `BOOST_PIPELINE_UI`, default path
  `boost-pipelines`.** Names invented. The path follows the wording of the original request.
- **The `JsonReceiptStore` docblock gets corrected.** Chosen. It states a no-history decision
  this spec reverses, and leaving it would contradict the code.
- **History and live records are written on every run, ungated.** Asked and answered. The page
  is gated three ways; the persistence that feeds it is not, so enabling the page shows real
  history at once. Every consumer pays, per resolution: one history write, one live write, one compare-and-delete, and a retention pass that may delete files. All small, all bounded by retention.
- **`pipeline:history` exits 0 for every valid report, non-zero only on invalid input.** Chosen.
  A stale or failed run is a report, not a command failure — a reporting command with gate-like
  exit codes invites being wired into a pre-push hook, where it would block on a question it
  never answered. An unknown pipeline or run id is different: the command cannot answer at all.
- **The command reuses `PipelineOverview` rather than reading the stores itself.** Chosen. Two
  readers of the same files drift, and the first symptom is a page and a terminal disagreeing
  about one run.
- **No runtime record this package writes survives a push.** Chosen during the interview, and
  load-bearing for the decision to build no bridge. The publishable config file is committed, but
  it carries settings rather than verdicts. CI check results and commit statuses do reach a PR, but a
  consumer's CI produces those, not this package.
- **Mechanical steps are expected to run in CI.** This is the package's stated design
  expectation — the README says "CI runs the checks itself" — not a guarantee about any project.
  A project that ignores it gains nothing from a receipt either, because a receipt written by
  the working copy is not the independent answer CI would give.
- **A dedicated review proof commit fails the package's own proof-artifact rule.** A judgement,
  not a fact. The counter-argument is that such a commit has independent value as an audit
  record of when a review ran, which would let it pass the "would it exist anyway" test. The
  decision does not rest on this point alone.
- **Step logs are not pruned.** Chosen. Deleting a log that a retained run links to would be
  worse than leaving it, and nothing prunes them today.
- **The live record is written from `resolveCurrent()`, covering the awaiting branch.**
  Corrected during evaluation. An earlier draft named `resolveSteps()`, which never sees the
  skill-step branch — the one state that writes no receipt.
- **The read model groups by position, not by step.** Corrected during evaluation. An earlier
  draft joined by step id alone, which would render a parallel group as separate positions and
  contradict what the run reports.
- **A `running` record expires on its recorded timeout; an `awaiting` record never expires.**
  Reworked after review. An id comparison cannot detect a crashed server — the stale record
  still matches the last receipt — but a heartbeat would only restate the start timestamp,
  because nothing refreshes it mid-step. A timeout for a skill step is a documented non-goal,
  so the page does not invent one.
- **Cleanup has four boundaries, not one.** Reworked across two review rounds. A single
  `finally` around `resolveCurrent()` would delete the awaiting record it just wrote, and the
  ordinary shell-then-skill handover settles to `Awaiting` inside `record()`
  (`src/Run/Run.php:583-587`), not in the early branch. `RunManager::open()` discarding a run
  is the fourth.
- **A clear is a compare-and-delete on run id plus attempt token.** Added after review. Two
  servers share a pipeline with no lock, so an unconditional clear deletes another server's
  record.
- **Timeout expiry applies only to the shipped runner.** Corrected after review. The
  `StepRunner` contract declares no timeout, so expiring a custom runner's record on the
  package default would report a live run as interrupted.
- **Log output is rendered as text, never as markup.** Added after review. Command output is
  untrusted input reaching a browser, and the local and loopback gates do not change that.
- **Only a recorded log path is served, canonicalised inside the log root.** Reworked after
  review. Deriving the path and recording it were two policies that disagreed for a custom
  runner.
- **The live record stands alone, without a receipt.** Corrected after review. A run's first
  position resolves before any receipt exists, which is exactly the moment the page is for.
- **A position is entered repeatedly.** Corrected after review. An earlier draft asserted once
  per position; a blocked position holds the cursor and is re-entered.
- **A third gate, loopback-only middleware, guards the routes.** Added after review.
  `APP_ENV=local` describes the application, not the requester.
- **Run ids are encoded before they become paths.** Added after review. `Run::start()` accepts
  a caller-supplied id, and `LogWriter` already treats that value as unsafe.
- **The history payload records each step's log path.** Added after review. The receipt discards
  `Result::$logPath`, and a custom `StepRunner` need not use `LogWriter` at all.
- **The history payload does NOT record the pipeline name.** Corrected after review. An earlier
  draft added it; `ReceiptStoreFactory` (`src/Run/ReceiptStoreFactory.php:18-20`) rejects exactly
  that as two sources of truth that can disagree.
- **A non-validation throwable from the consumer's config is not caught.** Corrected after
  review. An earlier draft promised the page never 500s, which contradicts the provider's
  documented posture.
- **Rendering the page executes the consumer's config in a web process.** Chosen, and new. The
  provider deliberately avoids running it on every artisan command
  (`src/BoostPipelineServiceProvider.php`, the `ServerProcess::isStarting()` comment). A web
  request is a third execution context, and a slow or side-effecting config file would be felt
  on every poll.

---

## 1. Current state

### What is on disk after a walk

| Artefact | Path | Written |
|---|---|---|
| Receipt | `storage/logs/pipeline/receipts/<pipeline>.json` | After every resolution |
| Step log | `storage/logs/pipeline/<runId>-<stepId>.log` | After a step's process ends |

`Run::record()` is the single funnel (`src/Run/Run.php:514`), and it ends with
`recordReceipt()` (`:538`). Two call sites reach it: `resolveCurrent()` (`:181`) and
`acknowledgeCurrentStep()` (`:228`). So a receipt lands after every resolution,
acknowledgements included, not only at the end of a walk.

`resolveCurrent()` is the documented chokepoint — its docblock says "Nothing else in the
codebase may move the cursor" (`:150-152`).

**Awaiting reaches disk on one path and not the other.** A shell step followed by a skill
step records normally: `settleState()` sets `RunState::Awaiting` on arrival
(`src/Run/Run.php:583-587`) and `recordReceipt()` runs after it, so the receipt carries
`state: awaiting` with the preceding verdicts.

Two cases write nothing. A run whose **first** step is a skill step takes the early return in
`resolveCurrent()` (`:170-176`) before `record()`. So does a repeat call made while already
awaiting — `NextStep` returns the awaiting payload without resolving anything
(`src/Mcp/Tools/NextStep.php:84`). Neither leaves a trace, and neither carries a waiting
timestamp in any case. The live record covers both, and supplies how long the wait has run.

`JsonReceiptStore::write()` overwrites one file per pipeline
(`src/Run/JsonReceiptStore.php:32`). Its docblock states the decision this spec reverses:

> One file rather than a history — the question a reader asks is "does the current tree
> have a pass", and a directory of past answers only makes that harder.

`LogWriter::write()` names the file `<directory>/<runId>-<stepId>.log`
(`src/Runner/LogWriter.php:33`), with `filenameSafe()` applied to both components. The path
is derivable, but only through that method — the page must call it rather than concatenate.

### What is not on disk

- **In-flight state.** Nothing is written when a position starts. A 900-second PHPStan step
  is invisible until it ends.
- **Run history.** Each write overwrites the last.
- **Per-step timing.** `Receipt::$verdicts` is `stepId => verdict` and nothing more.

### What the package ships

`src/BoostPipelineServiceProvider.php` registers bindings, `VerifyCommand`, and the MCP
server. There is no `config/`, no `resources/`, no route file and no view. This spec adds
the package's first HTTP surface.

### Reading the walk without a run

`Pipeline::walk(?string $selection)` returns a `Walk` (`src/Config/Pipeline.php:99`), and
`Walk` exposes `$steps`, `$notices`, `count()`, `at()`, `isGrouped()` and `positionAt()`.
Each `WalkStep::toArray()` gives `id`, `phase`, `kind` and `description`
(`src/Walk/WalkStep.php:27`). That is the denominator the receipt lacks.

---

## 2. Storage design

### History

Keyed by run, not by resolution. A run resolves many times, so appending per resolution
would store the same run repeatedly with a growing verdict map.

```
storage/logs/pipeline/history/<pipeline>/<runId>.json
```

`<runId>` is **not** trusted. `Run::start()` takes a caller-supplied `?string $id`
(`src/Run/Run.php:98-107`) and only falls back to a generated one, so a run id can hold
separators or `..`. `LogWriter` already treats the same value as unsafe
(`src/Runner/LogWriter.php:44-56`). History paths go through the same encoding, and the
resolved path is checked to sit inside the history directory before any write.

Each resolution overwrites that run's file. The receipt at
`receipts/<pipeline>.json` keeps its current meaning and its current path — nothing that
reads it changes. History is a second write beside it, not a replacement.

`RunHistoryStore` mirrors `JsonReceiptStore`: same failure posture (a lost write must never
turn a real verdict into an error), same JSON encoding, same `Receipt::toArray()` payload.

**The store does not return a bare `Receipt`.** `Receipt::fromArray()` builds from a fixed key
list and ignores everything else (`src/Run/Receipt.php:105`), so a store that parses a history
file into a `Receipt` silently drops the log map on the way out — the field would be written and
never readable. `RunHistoryStore` returns a record holding the `Receipt` **and** its log map.

One addition to that payload: `logs`, a step id to log path map taken from `Result::$logPath`
(`src/Results/Result.php:16`). The receipt discards it — `Receipt::$verdicts` is
`stepId => verdict` and nothing more — so without it the page has to derive a path, which is
only correct for the shipped runner. A consumer that binds its own `StepRunner`, which the
README documents as a seam, may write logs anywhere or not at all.

**The pipeline name is not in the payload.** `history/<pipeline>/` identifies it by
construction, and `ReceiptStoreFactory` states the rule this follows
(`src/Run/ReceiptStoreFactory.php:18-20`): "storing the name inside the file as well would
create two sources of truth that can disagree." An earlier draft added the field; it is
removed rather than justified.

### Retention

Nothing in the package prunes anything today, and the step logs are already unbounded. The
history store prunes on write: keep the newest 20 run files per pipeline by modification
time, delete the rest. Step logs stay out of scope — deleting a log a retained run links to
would be worse than leaving it.

### Live progress

A separate record, so the receipt's contract stays a record of verdicts:

```
storage/logs/pipeline/live/<pipeline>.json
```

**The live record must stand alone.** A run's first position *starts* before any receipt
exists: `record()` runs after a position resolves, never when it begins
(`src/Run/Run.php:514`). So a page that only trusts a live record matching a receipt shows
nothing during the first step, which is the case it exists for. The record therefore carries everything needed to render a
run with no receipt at all: run id, scope, the step ids at the position, the state
(`running` or `awaiting`), a start timestamp, and the effective timeout for that position.

**Expiry is per state, and there is no heartbeat.** Nothing refreshes a record while a step
runs, so a heartbeat would only ever be the start timestamp under another name. The two
states expire on different rules because the package treats them differently:

| State | Rule | Why |
|---|---|---|
| `running` | Expired once the start timestamp is older than the position's effective timeout, plus a margin — **only when the shipped runner produced it** | `ProcessStepRunner` kills a step at that timeout, so a record open past it means the process died. The `StepRunner` contract declares no timeout, and the README documents custom runners as a seam, so a custom runner's record carries no timeout and never expires on age |
| `awaiting` | Never expires | The package deliberately does not time out a skill step. The README lists it as a non-goal: "A run stays `awaiting` forever if `report_step` never arrives." A page that invents that timeout would contradict it |

The effective timeout is the step's own where it declares one (`Shell::timeoutSeconds()`,
`src/Steps/Shell.php:170`), otherwise the pipeline's (`Pipeline::timeoutSeconds()`,
`src/Config/Pipeline.php:79`), otherwise the runner default. The record stores the resolved
value, so the page never recomputes it.

An `awaiting` record that outlives its server is the residue this cannot detect. The page
shows how long it has waited and leaves the judgement to the reader, which is what the
package already does.

**It is cleared on every exit, not only on resolution.** These paths reach a position and
never reach `record()`:

| Path | Where |
|---|---|
| A `StepRunner` throws | `src/Run/Run.php:193` — the documented extension seam, so consumer code |
| A batch runner throws | `resolveSteps()`, the `BatchStepRunner` branch |
| A proof command throws during acknowledgement | `src/Run/Run.php:266` |

**Clear only your own record.** Each write carries an attempt token, and a clear is a
compare-and-delete on run id plus token. Two server processes share a pipeline with no lock —
the package declines to coordinate them — so without the token, one server's cleanup deletes
the record another server just wrote, and the page goes blank mid-run.

The token narrows that window; it does not close it. Reading the record, comparing it and
unlinking it are three steps, and a writer can land between the second and the third. Closing it
needs a lock, which the package does not take anywhere else, and the cost of losing is a briefly
blank page.

Clearing must be a `finally`, not a line after the call — but **not one `finally` around
`resolveCurrent()`**. The awaiting branch has to leave its record in place after returning;
that is the state it exists to represent. So there are two boundaries:

- `resolveCurrent()` clears on every exit **except** the awaiting branch, which writes.
- After `record()`, when the settled state is `Awaiting`, write an awaiting record rather than
  clearing. This is the ordinary path — a shell step handing over to a skill step — and a
  blanket `finally` would delete the record the run just earned.
- `acknowledgeCurrentStep()` (`src/Run/Run.php:215`) clears on every exit, including a proof
  command that throws.
- `RunManager::open()` clears the record when it discards a run — a changed scope, a moved
  tree, or a stale run (`src/Run/RunManager.php:60-91`). Without this an abandoned awaiting
  record outlives its run forever, because awaiting records do not expire on age.

`Run::resolveCurrent()` (`src/Run/Run.php:160`) is the entry point, not `resolveSteps()`.
It is the documented chokepoint, it holds the position rather than a bare step list, and it
owns the skill-step branch — which is the state that writes no receipt at all.

**A position is entered more than once.** A failed or errored position holds the cursor
(`src/Run/Run.php:569-574` sets `Blocked` or `Halted` and returns without advancing), so the
next `next_step` re-enters `resolveCurrent()` for the same position. Each entry replaces the
record and starts a new attempt timestamp. It does not accumulate attempts — the receipt is
where a verdict history belongs.

This is what makes polling worth doing. Without it the page updates only at resolution
boundaries, and a long step reads as a frozen page.

---

## 3. Read model

A `PipelineOverview` service in the package, resolved from the container, answering one
question: what should the page show?

For each declared pipeline name:

1. Read the receipt. Absent is a first-class state, not an error — `storage/logs/` gets
   cleared as routine maintenance, and the README warns about exactly this.
2. Resolve the walk **with the receipt's own `scope`**, not unscoped. A scoped run walked
   fewer steps, so an unscoped walk gives the wrong denominator.
3. Join `Walk::$steps` against `Receipt::$verdicts` by step id, in walk order. A step in the
   walk with no verdict has not run. A verdict with no matching step means the config
   changed since the run — show it, labelled, rather than dropping it.
4. Group by position, not by step. Steps sharing a `WalkStep::$batchId`
   (`src/Walk/WalkStep.php:21`) occupy one position and resolve as a unit; `Walk::isGrouped()`
   and `Walk::positionAt()` already answer this. Rendering a group as separate positions would
   contradict what the run itself reports — the README's `position` is a range (`2-3/7`) for
   exactly this case.
5. Read the live record. It is authoritative on its own — do not require a matching receipt.
   Where it names a run the receipt does not, that run is in flight and has resolved nothing
   yet. Where both exist and the ids differ, the live record is the newer one. Apply the
   expiry rules in section 2 before rendering it as active.
6. Compare `Receipt::$tree` to the current fingerprint, the way `VerifyCommand` does, so the
   page can say whether the run still describes the tree on disk.
7. List the history directory, newest first.

**A history record resolves the same way, not as a summary row.** Steps 2 to 6 apply to any
receipt, and a history file holds one. Asked for a single past run, `PipelineOverview` joins
**its** verdicts, groups **its** positions, and compares **its** tree to the code on disk,
resolving the walk with **that record's** scope rather than the current receipt's — a past run
may have walked a narrower scope, and reusing the current one would label its steps wrongly.

**The walk itself is the current config, not a snapshot.** Nothing stores the step list a past
run walked; a history file holds a receipt, and a receipt holds verdicts. So a run recorded
before the pipeline was edited resolves against today's steps: a step added since shows as
never run, and a verdict whose step is gone shows labelled, exactly as step 3 already requires.
The page and the command must say which walk they resolved against rather than implying the
record carries one. Persisting a walk snapshot per run would fix this properly and is
deliberately not in scope — it is a second schema to version, for a question a reader can
usually answer from the verdict list.

The listing stays cheap — run id, state, verdict counts, scope, recorded at — and the full join
happens only for the record actually asked for.

The service returns plain arrays. No new public contract beyond the service itself.

---

## 4. HTTP surface

### Opt-in

A publishable `config/boost-pipeline.php`:

```php
return [
    'ui' => [
        'enabled' => env('BOOST_PIPELINE_UI', false),
        'path' => 'boost-pipelines',
        // The loopback middleware ships in the default, not as advice. An
        // example without it is an example that leaks command output.
        'middleware' => ['web', LoopbackOnly::class],
    ],
];
```

Off by default. The route registers only when `ui.enabled` is true **and** the application
environment is local. Both, not either: a config flag committed by mistake must not open the
page in production.

**Neither of those is access control.** `APP_ENV=local` describes the application, not the
requester. A local server routinely listens on a LAN address or behind a tunnel — Herd and
Valet both do — and `web` middleware authenticates nobody. The routes serve raw command
output, which can carry source, paths, environment values and test data.

So a third gate, applied to all three routes: the package ships a middleware that refuses a
request from a non-loopback address, and it is in the default `ui.middleware`. A consumer
who wants the page reachable from another machine replaces it deliberately, in their own
config, rather than discovering the page was open all along.

### Route and controller

Registered from the provider's `boot()`, beside the existing MCP registration, so a consumer
needs no route file. Three GET routes, all behind the same gates and the same middleware:

| Route | Returns |
|---|---|
| The page | The rendered HTML |
| The read model | The same data as JSON, for the poll |
| A step log | One step's output, looked up by run id and step id in that run's recorded `logs` map |

The log route is the one an implementer is most likely to register separately, and it is the
one that serves untrusted output. It gets the gates the other two get.

### The page

Self-contained Blade with inline CSS and no build step — the package cannot assume a host
app's asset pipeline. Per pipeline: the current run's state, its steps in walk order with
their verdicts, what is running now, whether the tree still matches, and the run history.

Polling is a small inline script against the JSON route. Update in place; do not reload.

---

## Edge Cases

| Scenario | Handling |
|----------|----------|
| No receipt for a pipeline | Render "no run recorded". Not an error — `storage/logs/` is cleared routinely |
| `.config/pipeline.php` absent | The route does not register. The package already gates the MCP server on `PipelineLoader::exists()` |
| Receipt records a `scope` | Resolve the walk with that scope, so the denominator matches the run |
| Verdict for a step not in the walk | Render it, labelled as no longer declared. The config changed since the run |
| History for a pipeline no longer declared | Not rendered. Orphan directories are left on disk, not deleted |
| Live record left by a killed process | Detected by age, not by run id — a crashed record still matches the latest receipt. A `running` record past its recorded timeout renders as interrupted. An `awaiting` one cannot be detected, by design |
| Two servers on one pipeline | The receipt and history files take the last write, as the receipt does today — the package declines to coordinate concurrent callers. The live record adds an attempt token, so a clear removes only the record that caller wrote. Read, compare and unlink are not one step, so a writer landing between them can still lose its record |
| Run sitting `awaiting` a skill step | The live record carries it. No receipt is written on that branch (`src/Run/Run.php:170-176`), so the receipt alone would show the previous step as current |
| Parallel group at the cursor | Render as one position, the way the run reports it. `WalkStep::$batchId` and `Walk::isGrouped()` already answer this |
| Web request resolves `Pipelines` | The consumer's `.config/pipeline.php` executes in a web process, which it has never done before |
| Config throws `InvalidPipelineConfigException` in a web request | Rendered as a config error, matching what `InvalidConfigServer` shows the agent |
| Config throws anything else — syntax error, `TypeError`, missing class | Not caught. The provider deliberately catches only its own validation errors (`src/BoostPipelineServiceProvider.php:241`), on the stated ground that swallowing the rest hides a real bug. The page holds that line rather than softening it |
| History write fails | Swallowed. The same posture as `JsonReceiptStore::write()` — losing the record must not fail the run |
| Retention deletes a run the page is showing | The next poll shows it gone. No pinning |
| Step log missing for a listed step | The link is absent. The log directory is documented as safe to delete |
| Custom `StepRunner` that writes no log | No link. A null in the recorded `logs` map is the answer — the page never guesses a path the shipped runner would have used |
| Run id or step id with path characters | Never used to build a serving path. The log route resolves through the run's recorded `logs` map, so an id is a lookup key, not a path component. A history filename does carry the run id, so it is encoded the way `LogWriter` does. A live filename is `live/<pipeline>.json` — the run id is payload, so it reaches no path |
| Recorded log path outside the log root | Not served. A custom `StepRunner` may write anywhere, so the recorded path is canonicalised and checked against the root before it is read. Outside means the log is non-renderable, and the page says so |
| Log request for a path outside the log directory | Refused. The log route takes a run id and a step id, never a path |
| `pipeline:history` with no history on disk | Prints "nothing recorded" and exits 0. It reports rather than gates, so absence is an answer, not a failure |
| `pipeline:history` where several pipelines are declared | Refuses without `--pipeline`, naming the declared ones. Same rule as `pipeline:verify`, which never guesses a pipeline |
| Log holding HTML or a script tag | Rendered as text. Blade escapes it, and the polling code sets `textContent`. Command output is untrusted input that reaches a browser |
| Log file larger than the page can hold | Pass it through `OutputSummariser::summarise()` (`src/Runner/OutputSummariser.php:85`), which strips ANSI, collapses rewritten lines, and returns head and tail with `truncated` and `clipped` flags |

---

## Implementation

### Phase 1: Persistence (Priority: HIGH)

**ID:** persistence · **Depends:** none

Ungated: every run writes these records whether or not the page is enabled.

- [x] Add `RunHistoryStore` — write a run's receipt payload to `history/<pipeline>/<runId>.json`, keyed by run id so a resolution overwrites rather than appends
- [x] Return a record carrying the `Receipt` and its log map, never a bare `Receipt` — `Receipt::fromArray()` drops unknown keys (`src/Run/Receipt.php:105`), so a bare return makes the log map unreadable
- [x] Add a step-id-to-log-path map to the history payload, from `Result::$logPath` (`src/Results/Result.php:16`), which the receipt discards. Do not add the pipeline name — the directory carries it (`src/Run/ReceiptStoreFactory.php:18-20`)
- [x] Prune on write — keep the newest 20 run files per pipeline by modification time, delete the rest
- [x] Add `LiveProgressStore` — write run id, scope, the step ids at the position, the state (`running` or `awaiting`), a start timestamp and the position's effective timeout, so the record renders a run that has no receipt yet
- [x] Call both from `Run` — history beside `recordReceipt()` (`src/Run/Run.php:538`), live from `resolveCurrent()` (`:160`) covering both the running and the awaiting branch
- [x] Bind both in the provider, per pipeline, mirroring `ReceiptStoreFactory`
- [x] Tests — a run writes one history file per run and overwrites it per resolution; retention keeps the newest 20; a live record appears at position start and clears on resolution; a skill step at the cursor writes an `awaiting` live record even though it writes no receipt; a throwing runner and a throwing batch runner both clear the record; a re-entered blocked position replaces the record rather than accumulating; a run id holding separators or `..` cannot escape the history directory, which is the only path that carries one; a recorded log path survives a write-then-read round trip through the store, which a bare `Receipt` return would lose; a shell step followed by a skill step leaves an awaiting record, not a cleared one; a throwing proof in `acknowledgeCurrentStep()` clears the awaiting record; a discarded run's record is cleared when the scope changes and when the tree moves; a clear whose token does not match leaves the record alone; a `running` record past its recorded timeout reads as expired while an `awaiting` record of any age does not; a record from a runner with no declared timeout never expires on age; a failed write does not fail the run

### Phase 2: Read model and HTTP surface (Priority: HIGH)

**ID:** http-surface · **Depends:** persistence

- [x] Add `config/boost-pipeline.php` with `ui.enabled`, `ui.path`, `ui.middleware`
- [x] Merge it in `register()` and publish it in `boot()` — the route gate reads the config, so a consumer who never publishes the file still needs the defaults
- [x] Register the routes in `boot()` only when `ui.enabled` is true and the environment is local
- [x] Ship a loopback-only middleware and put it in the default `ui.middleware` — the env gate describes the app, not the requester
- [x] Add `PipelineOverview` — join walk against receipt per section 3, resolving the walk with the receipt's own scope, grouping by position rather than by step
- [x] Bind it unconditionally in `register()`, never behind the UI gate. Only route registration is gated: `pipeline:history` has to work with the page disabled and outside a local environment, and the provider already registers console commands ahead of its own gates (`src/BoostPipelineServiceProvider.php:188-190`)
- [x] Compare the receipt's tree to the current fingerprint, reusing what `VerifyCommand` does
- [x] Add the controller — one HTML route, one JSON route serving the same read model
- [x] Tests — the route does not register without opt-in; does not register outside local; a non-loopback request is refused on the HTML and JSON routes; a missing `.config/pipeline.php` registers no route; a scoped receipt resolves a scoped walk; an unknown verdict renders labelled; an `InvalidPipelineConfigException` renders as a config error while any other throwable is left to surface; a live record with no receipt renders the run in flight; a `running` record past its recorded timeout renders as interrupted while an `awaiting` record of any age still renders as awaiting; a parallel group renders as one position; a recorded log path reaches `PipelineOverview`; history for a pipeline no longer declared is not listed; a run deleted by retention while the page is open disappears on the next poll; a second writer's history file overwrites rather than merges while its live record is left alone; rendering the page executes the consumer's config exactly once per request

### Phase 3: The page (Priority: HIGH)

**ID:** view · **Depends:** http-surface

- [x] Add the Blade view — self-contained, inline CSS, no build step; register `loadViewsFrom`
- [x] Render per pipeline: state, steps in walk order with verdicts, what is running, tree match, history list
- [x] Add polling against the JSON route, updating in place — write text through `textContent`, never `innerHTML`. Command output is attacker-controlled: a test name or a failing assertion can carry markup, and the local and loopback gates do not make unsafe rendering safe
- [x] Link each step through its recorded log path, never a derived one — `LogWriter::filenameSafe()` is private (`src/Runner/LogWriter.php:48`) and derivation is wrong for a custom runner
- [x] Render a step's log on demand — expand in place, fetched from a third route that takes a run id and a step id, looks the path up in that run's recorded `logs` map, and serves it only when the canonicalised path sits inside the log root
- [x] Tests — the page renders each state without a receipt, mid-run, blocked, and complete; a non-loopback request is refused on the log route; a recorded log path outside the root is refused, including an absolute path, a `../` path and a symlink out; a step id holding path characters resolves through the map rather than the filesystem; a step whose recorded log path is null renders no link and issues no log request; a step with a recorded path renders a working link; a log holding HTML, a `<script>` tag and encoded markup renders as text, not as markup; a missing log file renders without a link rather than erroring; an oversized log renders truncated; eye-verify in a browser against a real walk

### Phase 4: History command (Priority: HIGH)

**ID:** command · **Depends:** view

What it needs is ready after Phase 2: the stores and `PipelineOverview`. It uses neither the
Blade view nor the log route. The edge on `view` is a write conflict, not a logical one — both
phases edit `BoostPipelineServiceProvider`, and this spec only marks phases independent when
they are write-disjoint. Do not relax the edge to `http-surface` without first moving one of
the two registrations out of the provider.

- [x] Add `pipeline:history` — list a pipeline's recorded runs, newest first: run id, state, `all_verified`, scope, when it was recorded, and whether its tree still matches the code on disk
- [x] Mark the run in flight — where a live record is current, show it at the top as running or awaiting, with how long it has waited
- [x] Add `--run=<id>` for one run in detail: its verdicts joined against the walk resolved with that record's scope, plus each step's log path where one was recorded. Say that the walk comes from the current config, so a pipeline edited since shows added steps as never run and removed ones as labelled verdicts
- [x] Add `--pipeline=` and `--limit=` — `--pipeline` required when the project declares several, refusing rather than guessing. Follow `VerifyCommand::storeFor()` (`src/Console/VerifyCommand.php:158-192`) for the selection behaviour; `--run` and `--limit` are this command's own
- [x] Exit 0 for every valid report — an empty history, a stale run, a failed run. None of those is a command failure, and `pipeline:verify` owns the gate exit code; a second command with gate-like exits would get wired into a hook by mistake
- [x] Exit non-zero only when the command cannot answer — an unknown pipeline, a missing `--pipeline` where several are declared, an unknown `--run` id, or a `--limit` that is not a positive integer. That is invalid input, not a report
- [x] `--limit` accepts a positive integer and defaults to the retention cap, so the default can never promise more runs than are kept
- [x] Reuse `PipelineOverview` rather than reading the stores again, so the command and the page cannot disagree
- [x] Tests — a project with no history prints "nothing recorded" and still exits 0; `--pipeline` is required with several declared, and both that refusal and an unknown-name refusal print every declared name, as `VerifyCommand::storeFor()` does; `--run` on an unknown id fails clearly; a stale run, a failed run and a blocked run each print their state and exit 0 — the contract that separates this command from `pipeline:verify`, which returns failure for the same receipts (`src/Console/VerifyCommand.php:80`, `:107`); `--limit` rejects zero, a negative, a non-integer and an empty value with a non-zero exit; a live record renders as in flight; the command and the page report the same verdicts and the same log paths for one run; a past run whose scope and tree differ from the current receipt resolves with its own scope and its own fingerprint; a past run recorded before a step was added or removed renders the difference rather than hiding it

### Phase 5: Documentation (Priority: HIGH)

**ID:** docs · **Depends:** command

- [x] README — the page, the opt-in, `pipeline:history`, and what polling can and cannot show
- [x] Say plainly that `pipeline:history` reports and `pipeline:verify` gates. They read different records for different questions: `pipeline:verify` reads `receipts/<pipeline>.json` and answers whether the tree on disk is verified; `pipeline:history` reads the history and live stores and answers what the recent runs did
- [x] Correct the `JsonReceiptStore` docblock — it states a no-history decision this spec reverses
- [x] UPGRADING — the new config file and the new storage paths
- [x] Tests — none. Documentation only

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **`Run::record()` is the only receipt write path.** It is defined at `src/Run/Run.php:514` and calls `recordReceipt()` at `:538`. Two call sites reach it: `resolveCurrent()` (`:181`) and `acknowledgeCurrentStep()` (`:228`) — `resolveSteps()` only returns results, it writes nothing. If a third write path exists, history misses runs.
2. **`Run::resolveCurrent()` is the only path that reaches a position.** Its docblock calls it the chokepoint and says nothing else may move the cursor (`src/Run/Run.php:150-152`). It is entered *repeatedly* for one position — a blocked or halted position holds the cursor (`:569-574`) — so the live record must tolerate re-entry. What it must not tolerate is a second path reaching a position without writing a record.
3. **A `Walk` can be resolved outside a run.** The read model calls `Pipeline::walk()` with no runner and no run. If that has a hidden dependency on run state, the denominator cannot be built and the page can only show verdicts.
4. **The route gate holds on both conditions.** The page renders command output, so it must
   register only when the config flag is true and the environment is local. If either check
   cannot be made at registration time, stop rather than falling back to one.
5. **The added filesystem work does not slow a walk measurably.** Per resolution: a history write, a live write, a compare-and-delete, and a retention scan of one directory. Small next to running a step, but it is four operations rather than one. If the cost is real, the live record moves off the resolution path first — it is the one on the hot path.

---

## Open Questions

None.

---

## Resolved Questions

1. **How many past runs should the history keep per pipeline?** **Decision:** The newest 20,
   pruned on write. **Rationale:** Enough to answer what the last few walks did. Chosen over 50
   and over unbounded growth.
2. **Should the page render step log contents, or only link to them?** **Decision:** Render on
   demand, expanded in place. **Rationale:** Reading the failure is the reason to open the page.
   The local-env gate and the opt-in limit who can reach it. The log route takes a run id and a
   step id, never a path.
3. **Can this bridge to proving actions at PR time?** **Decision:** No. The page and the history
   stay a local tool, and no phase carries work toward a portable receipt.

   **Rationale.** No runtime record this package writes survives a push — not a receipt, a
   history file, a live record or a step log. The publishable config file is a different thing:
   it is meant to be committed, and it carries settings, not verdicts. The receipt sits under
   `storage/logs/`, which Laravel gitignores — `JsonReceiptStore`'s docblock names that as the
   reason it was put there, so it is a decision, not an obstacle.

   Plenty of other things do reach a PR: CI check results, commit statuses, uploaded artifacts.
   Those are attestations a CI system makes about a commit, and they are out of scope here — a
   consumer wires them up in its own CI, not through this package. The claim is only that this
   spec adds no portable proof, not that portable proof is impossible.

   The mechanical steps need no bridge. The README already tells a consumer to run those checks
   in CI, where they are re-run without trusting the working copy. A receipt would restate a
   weaker version of what that produces. Where a project does not run them in CI, a receipt does
   not fill the gap — it is written by the very working copy whose state is in question.

   What is left untracked is narrower than "the agent steps": a step declaring `->proving()`
   runs a real command and reports `passed`, not `acknowledged`. The gap is the agent step whose
   work leaves no artifact anything else could check, and the package names that gap directly in
   its non-goals table (`README.md:322`), whose row pairs the item "Verify an agent step whose
   work leaves no trace" with the reason "A proof needs an artifact to check". Two cells, not one
   sentence.

   Two further statements each support a narrower point. The README's local-gate paragraph says
   why a receipt does not travel with a push. `Receipt`'s docblock
   (`src/Run/Receipt.php:13-21`) says why a receipt in the working copy is not independent
   evidence even when read. Neither states the no-artifact policy; only the non-goals row does.

   That last rule is what makes the one artifact that does travel a poor bet — a dedicated proof
   commit, empty when a review round is clean, whose SHA a PR checkbox cites. Read strictly, such
   a commit would not exist if nobody wanted proof, so the rule says leave the step acknowledged.
   The reading is arguable: the commit doubles as an audit record of when a review ran, which is
   value independent of the proof. So this is a judgement that the mechanism does not earn its
   cost, not a rule that forbids it. Someone who disagrees is disagreeing with a trade-off, not
   with a citation.

   **The narrower thing that does work**, and needs nothing from this spec: `Skill::run(...)`
   `->proving('<command>')` runs a real command through the same runner and reports `passed`
   rather than `acknowledged`. Where an agent step leaves an artifact that would exist anyway,
   that turns an acknowledgement into a server verdict.

   It does not, by itself, reach CI. The proof runs from `acknowledgeCurrentStep()` when an agent
   reports the skill (`src/Run/Run.php:215`, `:258`); nothing runs a pipeline's proofs in CI. What
   travels is the **command**, which a consumer can also run as a CI job. Two places running the
   same check, not one mechanism spanning both — and both are changes to a consumer's own config,
   not to this package.

## Findings

### Phase 1 — Persistence

**Two new contracts, mirroring `ReceiptStore`.** `Run` holds `StepRunner`, `TreeFingerprint`
and `ReceiptStore` as interfaces, so `RunHistoryStore` and `LiveProgressStore` follow that
shape rather than binding `Run` to concrete stores. Factories mirror `ReceiptStoreFactory`.

**`SafeFilename` extracted from `LogWriter`.** History filenames carry a caller-supplied run
id and need the same encoding, but `LogWriter::filenameSafe()` is private. Duplicating the
rule would let two copies drift into different answers for one id — a collision nothing would
report — so it moved to `src/Runner/SafeFilename.php` and `LogWriter` delegates. No public
API changed; the existing `LogWriterTest` passes untouched.

**`ProcessStepRunner::effectiveTimeout()` added.** The live record stores the position's
ceiling, and `Run` could not compute it: the `Step` contract declares no timeout (only `Shell`
does), and the runner default is private. Narrowing to `ProcessStepRunner` is what the spec
already required — a custom runner enforces no ceiling, so its record carries none and never
expires on age — and it matches the provider's existing `instanceof ProcessStepRunner` check.

**A real bug the escape test found: `glob` skips leading-dot filenames.** A run id of
`../escape` encodes to `..-escape-<hash>`, which lands inside the directory but was invisible
to `glob('*.json')` — so such a run would be listed by nothing and pruned by nothing. The
store lists with `scandir` instead. The containment check was never the weak part; the
listing was.

**`writeLive()` takes the state rather than reading it.** While the first position executes,
`Run::$state` is still `open` — it settles after the position resolves. Reading the field
would have written `state: open` into a record whose whole job is to say what is happening
now.

**`acknowledgeCurrentStep()` captures the live token before its `try`.** `writeLive()`
replaces `$liveToken`, so a `finally` reading the field would delete the awaiting record the
same method had just written. `resolveCurrent()` is safe without this because it captures the
token before the write it owns.

**The unconditional `finally` in `resolveCurrent()` is correct because of the token.** On the
shell-to-skill handover the record is replaced and carries a newer token, so the
compare-and-delete on the older one is a no-op. That is what lets one `finally` cover the
ordinary exit, a throwing runner and a throwing batch runner without a flag.

**Tests: 30 added, suite 395 → 425.** Two test defects of my own, both caught by running
them: a retention test whose `touch` calls recreated files prune had already deleted, and a
tree-move test whose run had no results — `RunManager` rebaselines such a run instead of
discarding it, so nothing was ever discarded to clear.

### Phase 2 — Read model and HTTP surface

**A real bug the config-error test caught: the catch was in the wrong place.** The controller
took `PipelineOverview` as a method parameter, so building it resolved `Pipelines` and executed
the consumer's config **during dependency resolution** — before any handler body ran, and
before the `try`. A broken config produced a 500 rather than the rendered message the spec
requires. The controller now resolves the overview inside the try, through an injected
container, and the docblock says why so nobody re-injects it.

**A test-only collision that only appeared in the full suite.** A global `receipt()` helper
already exists in `VerifyCommandTest`; mine crashed the run with "cannot redeclare" while the
file passed on its own. Renamed to `overviewReceipt()`. Worth remembering that a green single
file proves nothing about global helpers.

**`loadViewsFrom` moved from Phase 3 to Phase 2, with a placeholder view.** The HTML route is a
Phase 2 task but the Blade page is a Phase 3 one, so Phase 2 would otherwise end with a route
that 500s. `resources/views/page.blade.php` renders the same payload the JSON route serves —
enough to make the route testable — and Phase 3 replaces it with the real page. Documented
deviation, not a scope change.

**The gate is read defensively.** `ui.path` and `ui.middleware` come from published,
consumer-owned config, so neither is assumed to hold the shape this package shipped: a
non-string path falls back to the default, and a non-array middleware value to none.

**Testbench has no per-test environment hook here.** `defineEnvironmentUsingPest` is not
available, so the routing tests follow the package's existing `bootWithConfig` idiom — write
`.config/pipeline.php`, set the config and environment, re-boot the provider. Two harness
details that cost time: route name lookups must be rebuilt after a late `boot()`, and the
default `web` middleware group needs an `APP_KEY` the test app does not ship.

**PHPStan on the new tests pushed a real improvement.** Chained offsets on
`array<string, mixed>` are all `mixed`, so the assertions now read through `data_get()` paths —
shorter, and they say what they are asserting.

**A security bug found while evaluating Phase 2: the loopback gate was spoofable.** The
middleware read `Request::ip()`, which resolves through `getClientIps()`
(`vendor/symfony/http-foundation/Request.php:842-851`) and returns the `X-Forwarded-For` value
whenever the request arrives from a trusted proxy. A host app that trusts proxies broadly is
ordinary, so anyone who could reach the port could send `X-Forwarded-For: 127.0.0.1` and pass
the one gate that was supposed to answer *who is asking*. The middleware now reads
`REMOTE_ADDR` — the peer this process is actually talking to. The docblock had claimed no proxy
header was consulted, which was false.

The test for it was vacuous twice before it was real: the test app trusts no proxies by default,
and setting them through `Request::setTrustedProxies()` is overwritten by Laravel's own
`TrustProxies` middleware in the kernel. It configures `TrustProxies::at('*')` now, and it fails
against `Request::ip()` and passes against `REMOTE_ADDR`.

**Tests: 23 added, suite 429 → 452.**

### Phase 3 — The page

**Eye-verification earned its place: it found three bugs every test had passed over.**

1. **The current run showed no log links.** `forPipeline()` called `describe()` with an empty
   log map — only `run()`, the past-run path, passed one. So the run a reader most wants a log
   for was the single view that had none. The unit test covered `run()`, the page test only
   asserted the marker could render, and neither noticed. `forPipeline()` now joins the current
   receipt's run against history, with a test.
2. **Polling destroyed an opened log every two seconds.** `render()` calls `replaceChildren()`
   on each tick, so "expand in place" was unusable — the panel vanished before it could be read.
   The script now keeps a set of opened logs and restores them after a render, and skips the
   render entirely when the payload is byte-identical to the last one. That second half also
   stops the page throwing away the reader's scroll and selection twice a second.
3. **A log rendered above its own step row.** The panel was appended to the list item before the
   step line was. Cosmetic, invisible to any assertion, obvious on sight.

**The untrusted-output rule was verified against real output, not a fixture.** The seeded log
contained `<script>alert(1)</script>`; the browser showed it as text and the console stayed
clean. A `textContent`-only regression guard now asserts the page source contains no
`.innerHTML`.

**Two of my page tests were vacuous and were replaced.** An `assertDontSee` for a script tag
nothing ever emitted proves nothing. They now assert a step id carrying markup is escaped, and
that the script never writes through `innerHTML`.

**A step id containing a separator cannot be addressed by the log route at all.** A route
segment cannot carry one, so such a log is unreachable from the page. Unreachable rather than
mis-resolved is the safe failure, and there is a test saying so.

**Not covered by the suite:** the polling script itself. The open-log persistence, the
unchanged-payload skip and the panel ordering were all verified in a browser and none has an
automated regression test — this package has no JS test runner, and adding one is a larger
decision than this phase.

**Tests: 15 added, suite 452 → 467.**

### Phase 4 — History command

**PHPStan forced a real improvement to the read model.** The command indexes `PipelineOverview`
by name, and `array<string, mixed>` gave it 42 errors. The projection now carries
`@phpstan-type` shapes — `PipelineRow`, `RunRow`, `LiveRow`, `HistoryRow`, `PositionRow`,
`StepRow` — which the command imports with `@phpstan-import-type`. The spec said "plain arrays",
and they still are; they are now described ones. With two consumers reading the same projection,
an unnamed shape was a contract waiting to drift.

**`positions()` no longer spreads `WalkStep::toArray()`.** That returns an open
`array<string, string>`, so the spread produced a shape PHPStan could not seal and neither
reader could index safely. The row is built key by key instead — longer, and honest about what
the projection promises.

**`expectsOutputToContain` does not see `$this->components->*` output.** Six tests failed
against a command whose output was correct all along. They read `Artisan::output()` now. Worth
knowing before writing another console test in this package.

**An absent `--limit` and a blank one are different.** Not passing it takes the retention cap;
passing `--limit=` or `--limit=' '` is a value the caller meant something by, and it is not a
number. The first draft collapsed both to the default, which would have quietly ignored a typo.

**A self-inflicted failure worth recording.** An edit of mine left an orphan `/**`, which
swallowed the cleanup function — so `afterEach` silently stopped running and history leaked
between tests. The symptom was an unrelated assertion failing. A test helper that stops existing
does not announce itself; the suite catching it is the only reason it did not ship.

**Tests: 11 added, suite 468 → 479.**

### Phase 5 — Documentation

**Two README sections rather than one.** "Reading what the runs did" covers `pipeline:history`
and the two new storage paths, and states the reports-versus-gates split against
`pipeline:verify` directly. "The page" covers the three gates, why none of the first two is
access control, and what polling can and cannot show.

**The `JsonReceiptStore` docblock is corrected, not deleted.** It said "one file rather than a
history" and gave the reason. It now says the file answers one question and points at
`JsonRunHistoryStore` for past runs — the single meaning `pipeline:verify` reads it for is still
the point, which is why history went into a separate store rather than a widened receipt.

**A row added to "What it deliberately does not do":** a walk abandoned while awaiting a skill
step. The live record has no timeout to expire against, so the package reports the wait rather
than guessing. That limitation was in the spec from the review rounds and belonged in the
consumer-facing table too.

**The UPGRADING entry claims parameter compatibility, and it was checked.** `Run::start()` keeps
`$pipeline` in seventh place with the two new dependencies appended, and `RunManager` appends
both after `$receipts` — so positional and named callers are unaffected. Verified against the
signatures rather than asserted.

**CHANGELOG deliberately untouched.** It is CI-managed from the release body; hand-editing it
here would fight the workflow.

**Tests: none for the docs, two added by the closing requirements walk.** Walking the Edge
Cases table against real code — requirement-down rather than diff-up — found two commitments with
no test behind them: an oversized log served truncated, and the command reporting with the page
disabled and outside a local environment. Both are the kind a phase checklist passes over,
because no task named them.

### Closeout — Codex review of the implementation

Codex reviewed all five phases once its quota reset. Six findings; five applied, one raised with
the user.

**Applied — two real correctness bugs.**

- **A failed live write stranded the record it meant to replace.** `writeLive()` adopted the new
  token before knowing whether the store had written it, and the store swallowed failures. So a
  failed replacement left the older record on disk while the run cleared a token that never
  landed — and an awaiting record never expires on age, so it outlived the run. The store now
  returns `bool` and the token is adopted only on success. Mutation-checked.
- **Unreadable files could evict every real run.** Retention counted any `*.json` as a run, so a
  handful of newer malformed files pushed valid records past the cap. It now validates before
  counting, and parses only once the directory is over the cap — so an ordinary write still pays a
  stat per file. A file it cannot read is left alone rather than deleted. Mutation-checked.

**Applied — three accuracy fixes.**

- "Every run writes two more records" was false. Both stores are optional dependencies, so code
  building a `Run` by hand records nothing. README and UPGRADING now say so.
- UPGRADING named "the `Run` constructor", which is private. It names `Run::start()` and the
  `RunManager` constructor, and the new `LiveProgressStore::write(): bool` return.
- The concurrency comment claimed more than the code delivers. `clear()` is read-compare-unlink,
  which a writer can interleave; the token removes the ordinary case but does not close the race.
  Said plainly now, consistent with the package's documented non-goal on concurrent callers.

**Applied — one overclaiming test.** "Executes the consumer config once per request" sent a single
request, so it could not have tested that. It now sends two, and is named for what it proves: one
load per application whatever the number of declared pipelines.

**Raised, not decided: a `SafeFilename` collision.** A rewritten id gets a hash suffix and an
already-safe one does not, so `a/b` encodes to `a-b-<hash>` — a literal a caller could also supply
as a run id, colliding on one history file. Real, but the only complete fix is to hash every id,
which changes every existing step-log filename. Reachability is very low (an id matching another
id's encoded form); the cost is visible. Left for the user.

**Round 2 — three findings, two applied.**

- **The loopback gate was optional at runtime.** The provider used the consumer-owned
  `ui.middleware` list as given, so a partial published config — or a value of the wrong type,
  which became an empty list — left all three routes serving raw command output with no requester
  check, while the flag and environment gates still passed. An absent or unusable list now takes
  a shipped default that includes the gate; only a list the consumer actually wrote replaces it,
  so deliberate replacement still works. Mutation-checked.
- The documented history filename said `<runId>.json` where the id is encoded. Corrected in both
  README and UPGRADING.
- **Dismissed:** "the log route has no direct loopback test". It has one, at
  `tests/Feature/PipelinePageRenderTest.php:271` — the review had only read the other test file.

**Round 3 — four findings, two applied, two raised.**

- **Bounded retention was not bounded any more, and that was my round-2 fix's fault.** Excluding
  unreadable files from the count stopped them evicting valid runs, and introduced the opposite
  failure: repeated partial writes would grow the directory forever. Both rules now hold — only a
  file holding a run counts toward the cap, and a file holding none is deleted, because an
  unreadable `*.json` in this store's own directory is its own failed write. Writes go through a
  temporary file and a rename, so a crash mid-write leaves the previous record intact rather than
  producing the corruption retention then has to clean up.
- **The page rebuilt the log URL from raw `ui.path`** while route registration used the normalised
  value, so a path the routes accepted could still break the page. The controller passes a template
  built from the named route, and the view reads no config at all.
- **Re-raised and still open:** the compare-and-delete race and the `SafeFilename` collision. The
  spec's own claim that "one server never deletes another's record" was stronger than the code, and
  is corrected above — the token narrows the window rather than closing it.

**Round 4 — the `SafeFilename` collision, resolved by decision.** The user accepted a one-time
change to existing step log filenames, so the encoding is now injective: every id carries its
digest rather than only a rewritten one. `r-4f2a-a1b2c3-pint-d4e5f6.log`. The digest is always the
last seven characters, so the sanitised part and the digest are recoverable from any result and two
ids collide only on a hash collision. Documented in UPGRADING as a visible change, and the README's
log-path line updated.

Six tests had hard-coded a filename. They derive it through `SafeFilename::for()` now, which is
also the honest way to write them — one of the six was silently creating stray empty files with
`touch()` because its literal no longer matched, and the real records were tying on mtime and
falling through to the filename tiebreak.

`JsonRunHistoryStore::pathFor()` lost its empty/`.`/`..` guards: with a digest always appended the
result cannot be a directory sentinel, so those branches were unreachable. The containment check
stays.

**Round 5 could not run: Codex reported model capacity on four attempts.** The rounds-3-and-4
changes are therefore reviewed by me and not independently. Reviewing that delta myself found two
residuals, both fixed:

- **The atomic write traded one unbounded directory for another.** A process dying between the
  write and the rename leaves a `.tmp` file, and retention only ever looked at `*.json` — so the
  fix for malformed files displaced the same growth onto temporaries. Retention now clears
  abandoned ones, and only those older than a minute, so a write in flight elsewhere is left alone.
  Mutation-checked.
- **The log URL substituted its placeholders left to right.** A run id is caller-supplied and
  survives `encodeURIComponent` intact, so one holding the step placeholder would have been hit by
  the step substitution. It goes backwards now: an injected placeholder can only land after the
  real one it imitates, and `replace()` takes the first occurrence. (A pipeline name cannot do this
  — `Pipelines` rejects anything but lowercase alphanumerics and dashes, starting alphanumeric.)

**Final gate: 491 tests, 1171 assertions, PHPStan 0 errors, Pint clean.**

<!-- Notes added during implementation. Do not remove this section. -->
