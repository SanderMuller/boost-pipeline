# Error paths lose the output contract

<!-- spec:planned-at c1b3d7d6150c474a65527e02ffb8aa7c4ac6d537 2026-08-28 +uncommitted -->

## Overview

`.ai/docs/laravel-mcp-notes.md:125-128` states the runner's contract: *"Every shell step writes
its full output to `…/<run>-<step>.log` and returns a deterministic truncation."* The verdict
path honours it — `ProcessStepRunner::verdictFor()` logs at `:149` and summarises at `:160`.
Six error sites do not. The worst discard a timed-out process's entire buffered output — the
single most useful diagnostic a timeout can produce — and one writes its log but still puts raw,
unbounded output into the agent payload.

This spec makes the contract hold on every path that produces a `Result`, not only the two that
produce a verdict.

## Assumptions

Recorded per the Assumptions Audit. Items I decided are marked with the evidence; items needing
a human call are in the questions section at the end rather than invented here.

- **Both halves ship together, or neither.** Truncating an error string without first writing a
  log is a net loss: on these paths the error string is the only diagnostic that exists, because
  no verdict log was ever written. Load-bearing, so it also appears in
  STOP Conditions.
- **The log filename `<run>-<step>.log` is unclaimed on these paths.** A scope failure
  short-circuits before `start()`, and a timeout/start failure means `verdictFor()` never runs,
  so nothing else writes that name for this step. No `-scope` suffix needed. Verified against
  `ProcessStepRunner.php:78`, `:119`, `:149`.
- **Truncation uses the existing `OutputSummariser` with its current defaults** (20 lines, 400
  chars/line, `src/Runner/OutputSummariser.php:26-31`). Not introducing a second truncation
  policy.
- **The message prefixes stay byte-identical.** Three assertions pin them, not one:
  `tests/Unit/ProcessStepRunnerTest.php:171` (`'scope'`),
  `tests/Unit/ProcessStepRunnerTest.php:92` (`'Timed out'`), and
  `tests/Feature/ParallelExecutionTest.php:155` (`'Timed out'`). An earlier draft named only the
  first, so a reworded timeout message would have broken two tests the spec never warned about.
- **`summary`/`reason` duplication is OUT of scope.** Every `Result::error` stores the same
  string twice (`src/Results/Result.php:38-41`, emitted by `:62-74`). That is universal to
  `Result::error`, not specific to these paths, and removing a published payload key is a
  separate semver-bearing decision.

---

## 1. Current state

`src/Runner/ProcessStepRunner.php`. The only `logs->write()` call in the file is at `:149`; the
only `summariser->summarise()` call is at `:160`. Both are inside `verdictFor()`.

| Site | Code today | What is lost |
|---|---|---|
| `settle()` timeout, `:232-233` | `catch (ProcessTimedOutException) { return Result::error($stepId, "Timed out after {$timeout}s."); }` | **The entire buffered output.** `$process` is in scope and holds everything printed before the hang. Never logged, never summarised. |
| `settle()` throwable, `:234-235` | `catch (Throwable $exception) { return Result::error($stepId, "Could not run: {$exception->getMessage()}"); }` | Same — `$process` is in scope and discarded. |
| `start()` catch, `:220-221` | `catch (Throwable $throwable) { return Result::error($step->id(), "Could not run: {$throwable->getMessage()}"); }` | No process exists yet, so there is no output to log — but the error itself is never logged either. |
| `resolveScope()` exit-code, `:201-206` | Interpolates `$this->orElse(trim($this->combinedOutput($process)), 'no output')` | Raw, **untruncated** output into the payload; no log written. |
| `verdictFor()` exit 126/127, `:152-157` | `Result::error(..., sprintf('Command did not run (exit %d): %s', $exitCode, $this->orElse(trim($output), 'no output')), logPath: $logPath)` | Log IS written, but **raw `$output`** — not `$summary` — enters the payload. A command can print megabytes and exit 127. |
| `process()` timeout, `:247-248` | `catch (ProcessTimedOutException) { ... }` | `$process` **is** assigned (`:243`) before `run()` (`:244`) throws, so it is in scope and holds the buffered scope output. Discarded. Recoverable. |
| `process()` throwable, `:249-250` | `catch (Throwable $exception) { ... }` | **NOT the same shape — do not treat it as such.** `processFor()` (`:243`) can throw *before* `$process` is assigned, so `$process` may be undefined here. See the required handling below. |

**Correction — the 126/127 branch is NOT the good example.** An earlier
draft of this spec cited `:152-158` as the well-behaved contrast because it passes `logPath`. It
does write the log, but it interpolates raw `$output` into the `Result`, not the summarised text.
It therefore violates the invariant this spec proposes and is in scope, not a counter-example.

**Correction — `resolveScope()` cannot be fixed from `resolveScope()` alone.**
The scope path calls `process()` (`:195`), whose catches discard the `Process`. Threading
`$runId` into `resolveScope()` does not reach that loss; `process()` must preserve the output.

**Critical distinction — `settle()` and `process()` are NOT symmetric.** An earlier draft of this spec
asserted they were:

- In `settle()`, `$process` is a **parameter** (`:227`), always defined in both catches. Treat both identically — safe.
- In `process()`, `$process` is **assigned inside the `try`** (`:243`). The `ProcessTimedOutException` catch is safe, because that exception can only come from `run()` at `:244`, after assignment. The `Throwable` catch is **not**: `processFor()` itself can throw, leaving `$process` undefined. `phpstan.neon.dist` runs `level: max`, so referencing it unguarded is a hard analysis failure, not a latent bug.

**Required handling for `process()`'s `Throwable` catch:** make the recoverable case explicit —
either initialise `$process = null` before the `try` and guard the log write on
`$process instanceof Process`, or split the `try` so `processFor()` has its own catch that keeps
today's bare message. Never reference `$process` unguarded there.

Two further corrections to earlier framing, carried here so they are not re-derived:

- `resolveScope()` has **two** error sites, not three. The other (`:198`) interpolates
  `$process->summary` — a bounded timeout or exception string from `process()` — so it is not a
  bloat risk.
- `OutputSummariser::readable()`'s 2 MB pre-cap is **never reached** on any of these paths.
  `readable()` is private, called only from `split()`, called only from `summarise()`
  (`src/Runner/OutputSummariser.php:36`, `:56`, `:87`).

Severity ordering, highest first:
1. **`settle()` / `process()` timeout** — fires on every timeout and DESTROYS output that existed.
2. **`verdictFor()` 126/127** — a missing or non-executable binary is common, and the payload is unbounded even though the log is written.
3. **`resolveScope()` exit-code** — narrow trigger (a scope command that prints a lot *and* exits non-zero), bloats the payload, no log.

## 2. Proposed changes

One rule: **every string that leaves the runner inside a `Result` and derives from CAPTURED
PROCESS OUTPUT has passed through `OutputSummariser`, and that output has been written by
`LogWriter`.** Exception messages that never had a process attached (`start()`'s catch) are out
of scope — there is nothing captured to bound or preserve.

`settle()` and `resolveScope()` need `$runId` to build a log path; neither takes it today.

```php
// settle() — currently: settle(Process $process, string $stepId, float $timeout): ?Result
// becomes:               settle(Process $process, string $stepId, float $timeout, string $runId): ?Result

} catch (ProcessTimedOutException) {
    // A timeout is exactly when the output matters: it shows where the command
    // stalled. Log it in full, hand back a bounded summary.
    $output = $this->combinedOutput($process);
    $logPath = $this->logs->write($runId, $stepId, $output);

    return Result::error(
        $stepId,
        sprintf(
            'Timed out after %ss. %s',
            $timeout,
            $this->orElse(trim($this->summariser->summarise($output)['summary']), 'no output'),
        ),
        logPath: $logPath,
    );
}
```

Three things this snippet fixes from an earlier draft: it does **not**
call a `describeTimeout()` helper (no such method exists — only `describePass()` at `:274` and
`describeFailure()` at `:286`); it passes the log path as the `logPath:` **argument** rather than
interpolating it, matching the phase task lists; and it interpolates `summarise(...)['summary']`,
not the whole array.

**The `Timed out` prefix is load-bearing** — asserted by `tests/Unit/ProcessStepRunnerTest.php:92`
and `tests/Feature/ParallelExecutionTest.php:155`. Keep it as the first token.

The same shape for `settle()`'s `Throwable` branch, for `process()`'s two catches, and for
`resolveScope()`'s exit-code branch. For exit 126/127 in `verdictFor()` the log already exists —
only swap raw `$output` for the summarised text. **`start()`'s catch is deliberately excluded:**
no process was ever created, so there is nothing captured to preserve or bound.

Call sites to update: `settle()` at `:101` (in `runBatch`) and `:137` (in `execute`);
`resolveScope()` at `:78` and `:119`; `process()` at `:195` — its only caller, inside
`resolveScope()`. It already receives `$stepId`, so `$runId` joins it.

(An earlier draft cited `process()` at `:119`. That line is the `resolveScope()` call inside
`execute()`, a different function — corrected.)

> **Interaction with the plan-008 branch and audit finding F7.** A separate finding proposes
> deleting `execute()` so `run()` delegates to `runBatch()`, which would remove one of the two
> `settle()` call sites. If that lands first, this spec has one call site to update instead of
> two. Neither blocks the other; re-grep before editing.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Timeout with empty output | `LogWriter::write()` is still called with an empty string; it returns a path or `null`. The message falls back to the existing no-output wording. Covered by Phase 2 Tests. |
| Log directory unwritable during an error path | `LogWriter` already swallows write failures and returns `null` (`src/Runner/LogWriter.php:16`, `:28`). The `Result` still carries the summarised text with no path. Must not throw — that would turn a real error verdict into an exception. Covered by Phase 2 Tests. |
| Scope command fails after printing megabytes | Full text to the log; bounded summary to the payload. This is the case the finding was raised for. Covered by Phase 1 Tests. |
| Two error paths fire for one step in one run | Cannot happen — a scope failure short-circuits before `start()`, and a `settle()` failure means `verdictFor()` never runs. The `<run>-<step>.log` name is written at most once per step. Asserted in Phase 1. |
| A step with a numeric id (`'123'`) hits an error path | `LogWriter::filenameSafe()` already takes `string $stepId` and is called with `$step->id()`, so no array-key coercion occurs here. No new exposure. |

## Implementation

### Phase 1: Thread `$runId` and log on the scope-failure path (Priority: HIGH)

**ID:** scope-path · **Depends:** none

- [x] Add `string $runId` to `resolveScope()` and update both call sites (`:78`, `:119`) — the parameter is what makes logging possible.
- [x] In the exit-code branch (`:201-206`), write the full combined output via `LogWriter` and interpolate `summarise(...)['summary']` instead of raw output — keep the `sprintf` prefix byte-identical.
- [x] Pass `logPath:` to that `Result::error()` so the operator can find the full text.
- [x] Leave `:198` alone — it interpolates an already-bounded string. **Deviated:** the interpolated string is untouched, but the branch now propagates `logPath`. See the notes section at the end.
- [x] Tests — a scope command emitting far more than 20 lines and exiting non-zero: assert the `Result` summary is bounded, `logPath !== null`, the log file holds the full output, and `$result->reason` still contains `'scope'` (pins `tests/Unit/ProcessStepRunnerTest.php:171`).

### Phase 2: Preserve output on the timeout and throwable paths (Priority: HIGH)

**ID:** settle-path · **Depends:** scope-path

Phase 1 changes `resolveScope()`'s signature and Phase 2 changes its call to `process()`, so they
are NOT write-disjoint. The edge serialises them; an earlier draft marked both `Depends: none`
and relied on prose, which the DAG does not read.

Covers BOTH `settle()` (the async path used by `runBatch`) and `process()` (the synchronous path
used by `resolveScope`). `process()` is the one most easily missed: its catches
discard an already-assigned `$process`.

Write-disjoint from Phase 1 in intent but **same file**, so treat as serialised: do not run these
concurrently.

- [x] Add `string $runId` to `settle()` and update both call sites (`:100`, `:137`).
- [x] `settle()` timeout branch: log the full combined output, summarise what enters the `Result`, attach the log path.
- [x] `settle()` `Throwable` branch: same treatment — `$process` is in scope and currently discarded.
- [x] `process()` timeout and `Throwable` branches (`:246`, `:248-249`): same treatment. `$process` is assigned before `run()` throws, so the buffered output is recoverable. `process()` needs `$runId` too — it already takes `$stepId`.
- [x] Exit 126/127 in `verdictFor()` (`:152-157`): swap raw `$output` for `$this->summariser->summarise($output)['summary']`. The log is already written at `:149`; only the payload is unbounded. Keep the `Command did not run (exit %d):` prefix.
- [x] `start()` catch (`:220-221`): **Deviated — see the notes section.** The spec's reasoning below rests on a premise that turned out to be false, so this branch now preserves output when the process was started. The original task text is kept for the record: **do NOT add logging.** No process exists, so there is no output to preserve — only an exception message that already reaches the payload in full. A log file holding one line is noise in `storage_path('pipeline/logs/')` (note the order — the design doc is `pipeline/logs`, not `logs/pipeline`). Summarise nothing; leave this branch as it is. (This resolves the contradiction between the old Phase 2 task and the second question below — the question is now decided here, and the "one rule" in section 2 is scoped to paths that HAVE captured output.)
- [x] Tests — a step that exceeds its timeout after printing more than 20 lines: assert the log holds the full pre-timeout output, the `Result` summary is bounded, and `logPath !== null`.
- [x] Tests — the `process()` timeout path. **Read this constraint first:** `SCOPE_TIMEOUT_SECONDS` is a `private const float = 60.0` (`:33`) used via `self::` at `:195`, with no injection point — unlike `$timeoutSeconds`, a constructor argument existing tests already vary (`ProcessStepRunnerTest.php:86` uses `0.3`). A literal scope-timeout test would burn 60+ seconds per run. Cover the `Throwable` branch instead, and record in the notes section that the scope-timeout branch is unpinned. **Do NOT make the constant configurable** — that is a constructor-signature change outside this spec's scope. If you conclude it is required, STOP and report.
- [x] Tests — a command printing more than 20 lines and exiting 127: assert the payload text is bounded and the log still holds everything.
- [x] Tests — an unwritable log directory: no throw, and the `Result` still carries its summary.

### Phase 3: Record the contract where it is enforced (Priority: MEDIUM)

**ID:** docs · **Depends:** scope-path, settle-path

- [x] Add one line to `.ai/docs/laravel-mcp-notes.md` beside the existing contract sentence stating that error paths honour it too — the doc currently reads as if only the verdict path does.
- [x] Tests — none; documentation only.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **Truncation without logging is being shipped.** If for any reason the log write cannot be added on a path, do **not** truncate that path's output — leave it raw and report. On these paths the error string is the only diagnostic that exists; truncating it without a log destroys information rather than bounding it.
2. **`<run>-<step>.log` turns out to be claimed on an error path.** The design assumes a step that hit an error path never also produced a verdict log. If you find a path where both run, stop — the filename needs a suffix and that changes `LogWriter`'s contract.
3. **A message prefix must change to make a test pass.** The prefixes are asserted; changing one means the behaviour changed. Report the assertion rather than editing it.

---

## Resolved Questions

1. **Should the timeout message name the log path inline, or only attach it as `logPath`?**
   **Decision:** attach it as the `logPath:` argument only; do not interpolate it into the
   message. **Rationale:** the normative snippet in section 2 already does this, and section 2
   records that an earlier draft interpolating the path was corrected to match the phase task
   lists. `Result::error()` publishes the path as the `log` key, so an operator still reaches the
   full text. The verdict path embeds it only inside the "… N more line(s)" tail, which these
   messages do not have.
2. **Should `start()`'s catch write a log at all?** **Decision:** originally no; **overturned
   during evaluation — it now preserves output when the process was started.** **Rationale:**
   the original "no process exists at that point" was a false premise. `Process::start()` sets
   the status to started and reads the pipes BEFORE it calls `checkTimeout()`
   (`vendor/symfony/process/Process.php:421-422`), so a small enough step timeout throws
   `ProcessTimedOutException` from `start()` with the process started. The catch now splits the
   same way `process()` does: `processFor()` failures and launch failures keep the bare message,
   a started process goes through `preserving()`. See the notes section.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **`run()` can fail with the process assigned but never started, and the spec did not cover it.**
  The spec warned only that `processFor()` could throw before `$process` was assigned. There is a
  second unstarted-process path: `Process::run()` is `start()` plus `wait()`, so a `start()`
  failure (an absent working directory, for example) throws with `$process` assigned but never
  started — and `Process::getOutput()` on an unstarted process throws
  `Process must be started before calling "getOutput()"` (`vendor/symfony/process/Process.php:1655`).
  Preserving output there would have thrown out of the catch, turning an error verdict into an
  escaping exception — the one thing the runner's docblock forbids. `process()`'s `Throwable`
  catch therefore guards on `$process->isStarted()`. Pinned by
  *"does not read the output of a process that never started"*; removing the guard makes that test
  error with the message above. `settle()` needs no such guard — it only ever receives a process
  `start()` already succeeded on.

- **Deviation: `resolveScope()`'s `:198` branch now propagates `logPath`.** The spec said to leave
  that line alone, scoped to *summarisation* — the string it interpolates is already bounded.
  But once `process()` writes a log, dropping the path there orphans the log against the spec's
  own "so the operator can find the full text" intent. The change is one added argument, no
  wording change, and it is pinned by *"keeps the output of a scope command killed by a signal,
  and names its log"*.

- **Two helpers rather than an inline `sprintf` per site.** `preserving()` (log the full output,
  return a bounded error carrying the path) and `summarised()` (the bounded form of captured
  output) replace what would have been five copies. The message prefixes are unchanged and still
  built at the call site, so the three assertions that pin them — `ProcessStepRunnerTest.php:92`
  and `:171`, `ParallelExecutionTest.php:155` — were never touched.

- **The scope-timeout branch stays unpinned, as the spec instructed.** `SCOPE_TIMEOUT_SECONDS` is
  a `private const float = 60.0` with no injection point, so a literal test would burn 60 seconds
  per run. The `Throwable` branch is covered instead, using `kill -9 $$` to make a started process
  die to a signal — `wait()` then throws `ProcessSignaledException`, which is not a timeout, with
  the output already buffered. The constant was NOT made configurable.

- **STOP condition 2 holds and is asserted.** A scope failure short-circuits before `start()`, so
  `verdictFor()` never runs and nothing else claims `<run>-<step>.log` for that step. The Phase 1
  test asserts the log directory holds exactly one file.

- **Deviation: `start()`'s catch now preserves output for a started process.** The spec excluded
  `start()` on the grounds that "no process was ever created". That is false for one branch:
  `Process::start()` sets the status to started and calls `updateStatus()` before
  `checkTimeout()` (`vendor/symfony/process/Process.php:420-422`), so a small enough step timeout
  throws `ProcessTimedOutException` from `start()` with the process already started. Traced to the Symfony
  source before acting. `start()` now splits its `try` the
  way `process()` does — construction and launch failures keep the bare message, a started
  process goes through `preserving()`. Both halves are pinned, and both guards are mutation-
  checked: removing either `isStarted()` makes its test error with
  `Process must be started before calling "getOutput()"`.

  **Empirically, the preserved output on this branch is almost always empty.** `start()` checks
  the timeout microseconds after `proc_open`, so the child has not printed yet — the first draft
  of the test asserted 50+ logged lines and failed with 0. The test now pins what is actually
  true: the path reads the process instead of treating it as never started. The value of the fix
  is that it cannot throw and it makes the design doc's claim true, not that it recovers output.

- **Dismissed review finding: "leave the output raw when the log write fails".** One reading of STOP condition 1
  takes it for a runtime branch — if `LogWriter::write()` returns `null`,
  return the raw output instead of the summary. Dismissed on two grounds. The Edge Cases table
  already decides the opposite ("the `Result` still carries the summarised text with no path"),
  and STOP condition 1 is a design-time tripwire about a path where a log write *cannot be
  added*, not about a write that fails at runtime. Acting on it would also put unbounded output
  into a payload MCP caps at 25,000 tokens, so a full disk would break the response rather than
  degrade it. `preserving()`'s docblock was reworded to say this outright, because its earlier
  phrasing ("the two always happen together") is what invited the misreading.

- **Process note: this file was briefly corrupted during implementation and rebuilt from the
  pre-edit copy.** A scripted edit resolved `## Open Questions` and `## Findings` by first
  occurrence, and both strings also appear inside backticks earlier in the prose, so the section
  swap excised the body between them. The prose mentions are now phrased without the literal
  headings. Nothing was lost; the restored text carries the same content plus the ticks and the
  notes above.
