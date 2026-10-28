# Slot a step only when its scope is whole, and free a slot when its process ends

<!-- spec:planned-at d9d95c18decc32abe33a660c97c0f7671f93772b 2026-10-27 +uncommitted -->

## Overview

`exclusive()` makes a step wait for a machine-wide slot before it starts. Some steps load the
machine only sometimes. An example is a test step that usually runs a small selection of tests in
seconds, and falls back to the full parallel suite when it cannot compute the selection. Today that
step always takes a slot. A ten-second selection then waits behind full suites from other projects,
and holds a slot of its own while it runs.

This spec adds two changes:

1. **Whole-scope slots.** A step can declare that it takes its slot only when its `inspecting()`
   scope command reports "everything". The scope command reports that with a marker line. The step
   gets the scope output through a file, so it can run exactly the selection the slot decision was
   based on.
2. **Free a slot when the process ends.** Today a finished step frees its slot only when a
   `next_step` call collects its verdict. An agent that stops calling after a `pending` answer keeps
   the slot taken until its server exits. With `ext-pcntl`, the server frees the slot as soon as the
   step's process ends.

## Assumptions

<!-- Skim ledger. One bullet per inference the spec makes. Load-bearing bullets are marked. -->

- **Signal is a marker line.** A scope stdout line that is exactly `*` means "everything". Decided
  by the user over a line-count threshold and a reserved exit code. The `*` text is confirmed; a
  file literally named `*` is reserved.
- **The step gets the scope output.** Decided by the user, to close the gap between the scope
  decision and what the step runs.
- **Release mechanism is a SIGCHLD handler.** Decided by the user over handing the lock to the
  step's process. Without `ext-pcntl` the current behaviour stays.
- **API shape:** `exclusive(string $name, int $capacity = 1, bool $onlyWhenWhole = false)`. A named
  argument keeps one method for one concept. Confirmed by the user.
- **Env var name:** `BOOST_PIPELINE_SCOPE_FILE`. No `BOOST_PIPELINE_*` var exists today
  (`grep -rn BOOST_PIPELINE src` is empty). Confirmed by the user.
- **The scope file is given to every step that declares `inspecting()`,** not only to a step with
  `onlyWhenWhole`. It costs one temp file per scoped step, and the race it closes exists for any
  scoped step. Confirmed by the user.
- **A whole scope reports `files_inspected: null`,** the same value as a step without a scope. The
  marker is never counted as a file. The "Inspected 0 files" note never fires for a whole scope.
- **`ext-pcntl` stays optional.** `composer.json` does not require it today, and Windows has no
  pcntl. The spec adds no `require` entry.
- **Load-bearing: the stdio read loop survives a signal.** Traced:
  `vendor/laravel/mcp/src/Server/Transport/StdioTransport.php:31-45` sets STDIN non-blocking and
  loops on `fgets()` plus `usleep(10000)`. An interrupted `usleep` returns early, and the loop
  continues. A `fwrite(STDOUT, ...)` interrupted by a signal is NEEDS-CONFIRMATION.
- **The env var reaches the step.** Traced: `processFor()` (`src/Runner/ProcessStepRunner.php:595-603`)
  passes the step env through `EnvironmentScrubber::forStep()`, where overrides win
  (`src/Runner/EnvironmentScrubber.php:34-45`). The pipeline sets `BOOST_PIPELINE_SCOPE_FILE` after
  the step's own `withEnv()` values, so a step cannot override it by accident.
- **Load-bearing: the handler never runs inside other code that reads the same `Process`.** With
  async signals, a handler can run between any two opcodes, for example inside Symfony's
  `updateStatus()` for the same process. A nested read can then overwrite the exit code. So every
  path that reads a running step's `Process` blocks SIGCHLD with `pcntl_sigprocmask()` while it runs.
  Traced: outside `ProcessStepRunner`, `ProcessPendingSteps` and `ProcessTree`, no code in `src/`
  calls `isRunning()`, `getPid()` or `getExitCode()`.
- **Load-bearing: the handler does not steal an exit status.** The handler only calls
  `Process::isRunning()`, which reads `proc_get_status()`. Symfony caches the exit code it reads
  there. The handler never calls `pcntl_waitpid()`. NEEDS-CONFIRMATION by test: the verdict of a
  step whose process was first seen ended inside the handler keeps its real exit code.
- **Slot config stays out of the pipeline fingerprint.** `PipelineFingerprint` hashes `scope` but
  not the slot name or capacity today (`src/Config/PipelineFingerprint.php:234-244`). The fingerprint
  feeds `Walk::resolve()` and the receipt config match in `pipeline:verify` and the overview. This
  spec does not add `onlyWhenWhole` to it. Whether slot settings belong there is a separate question.

---

## 1. Current State

### Scope

- `Shell::inspecting(string $command)` (`src/Steps/Shell.php:77`) stores a command whose stdout
  lines list what the step inspects.
- `ProcessStepRunner::resolveScope()` (`src/Runner/ProcessStepRunner.php:438-468`) runs it with a
  60-second cap (`SCOPE_TIMEOUT_SECONDS`, line 39). It returns `null` for no scope, the count of
  non-empty lines, or an error `Result` when the command fails or exits non-zero.
- The count reaches `Result::$filesInspected`. A count of 0 adds the "Inspected 0 files" note in
  `describePass()` (line 623-640). The step command always runs, whatever the count.
- The scope output itself is not kept. The step cannot read it.

### Slots

- `Shell::exclusive()` (`src/Steps/Shell.php:206-222`) sets a slot name and capacity.
- Both run paths resolve the scope first and take the slot second:
  - `begin()`: scope at line 142, `MachineSlots::take()` at line 153, queue at line 160-178.
  - `execute()` (blocking path): scope at line 286, `waitForSlot()` at line 293.
- `launch()` (line 204-250) gives the hold to a `$discard` closure. `$discard` runs when the verdict
  is built or the run stops.
- `ProcessPendingSteps::poll()` (`src/Runner/ProcessPendingSteps.php:130-185`) builds a verdict only
  when something calls `await()`. Between two tool calls nothing calls it. So the slot of a finished
  step stays taken until the next `next_step`, or until the server exits.
- `SlotHold::release()` (`src/Runner/SlotHold.php`) is idempotent: it returns early when the handle
  is already closed.

## 2. Whole-Scope Slots

### Marker

A scope stdout line equal to `*`, after `trim()`, marks the scope as whole. Other lines may appear
next to it. They are ignored for the count.

Replace the `int|null` scope value with a small value object, for example
`SanderMuller\BoostPipeline\Runner\Scope` (`@internal`, `final readonly`):

```php
final readonly class Scope
{
    /** @param int|null $count non-empty lines, null when the scope is whole */
    public function __construct(public ?int $count, public bool $whole, public string $file) {}
}
```

`resolveScope()` returns `Scope|null|Result`. `$count` feeds `filesInspected` as today. A whole
scope gives `filesInspected: null`, and `describePass()` adds no "Inspected 0 files" note.

### Declaring it

```php
Shell::run('composer test:affected', id: 'tests')
    ->inspecting('composer test:affected -- --list')
    ->exclusive('tests', 2, onlyWhenWhole: true);
```

- `onlyWhenWhole: true` without `inspecting()` is a config error. Add
  `InvalidPipelineConfigException::wholeSlotNeedsScope(string $stepId)`. Check it where
  `slotNeedsResponseWindow` is checked (`src/Config/Pipeline.php:228`), so both refusals surface at
  config load.
- `onlyWhenWhole: false` (the default) keeps today's behaviour: the step always takes a slot.
- Add `Shell::slotOnlyWhenWhole(): bool`.

### Taking the slot

Add one private helper, used by `begin()` and `execute()`:

```php
private function slotFor(Shell $step, ?Scope $scope): ?string
{
    $slot = $step->slotName();

    if ($slot === null || ! $step->slotOnlyWhenWhole()) {
        return $slot;
    }

    return $scope?->whole === true ? $slot : null;
}
```

`waitForSlot()` takes the slot name from this helper instead of `$step->slotName()`. A step that
takes no slot gets `slotWaitMs: null`, the same as a step without `exclusive()`.

### Handing the scope to the step

- `resolveScope()` writes the scope stdout to a temp file (`tempnam(sys_get_temp_dir(),
  'bp-scope-')`) and records its path on `Scope::$file`.
- `start()` adds `BOOST_PIPELINE_SCOPE_FILE=<path>` to the step's env when the step has a scope.
  The file holds the scope stdout as printed, marker line included.
- The file is deleted with the output files: in `launch()`'s `$discard`, and at the end of
  `execute()`.
- A file that cannot be created makes the step an error, as a missing output file does
  (`launch()`, line 212-216). A scope that cannot be handed over is a broken scope.

### Documentation

README, section "Steps that load the whole machine" (line 170-191):

- `onlyWhenWhole`, the `*` marker, and that the step reads `BOOST_PIPELINE_SCOPE_FILE` to run
  exactly that selection.
- One sentence on why: a scope command and a step that each compute the selection can disagree, and
  a full suite then runs without a slot.

README, section on `inspecting()` (line 87-89): one sentence that a `*` line means "every file" and
reports no count.

## 3. Free a Slot When the Process Ends

### Handler

`ProcessPendingSteps` installs a SIGCHLD handler while it has at least one running step that holds a
slot, and only when `function_exists('pcntl_signal')` and `function_exists('pcntl_async_signals')`.

- Turn on `pcntl_async_signals(true)`. Use `pcntl_sigprocmask()` too, see "Masking" below. Keep the previous `pcntl_async_signals()` value and the
  previous handler (`pcntl_signal_get_handler(SIGCHLD)`).
- The handler calls the previous handler when it is callable, then frees the slot of every slotted
  running step whose process no longer runs. It builds no verdict, and it never calls
  `pcntl_waitpid()`.
- When no slotted step runs any more (after `poll()` or `stop()`), restore the previous handler and
  the previous async setting.

### Masking

An async handler can run in the middle of `poll()`, `stop()`, `__destruct()`, a verdict closure, or
`launch()`, while Symfony updates the status of the same `Process`. So these paths block SIGCHLD with
`pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $old)` on entry and restore `$old` on exit, in a
`finally`. A SIGCHLD that arrives while blocked is delivered on restore, so no exit is missed. Put the
mask in one private helper, for example `withSignalsHeld(Closure $work)`, so every path uses the same
code.

A test can show that the mask is in place, but it cannot force the interleaving it guards against.
Code review must check that every `Process` read in these classes runs inside the helper.

### Wiring

The `$running` tuple (`ProcessPendingSteps.php:45`) gains a fifth closure, `freeSlot`. `launch()`
returns it as `static fn () => $hold?->release()`. `$discard` keeps its own release call, which is
then a no-op.

SIGCHLD also arrives for scope commands and unslotted steps. The handler checks only slotted entries,
so those signals cost one loop over the running steps.

### Documentation

README, section "Steps that load the whole machine": the slot frees when the step's process ends,
not when its verdict is collected. Without `ext-pcntl` it frees when the verdict is collected.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Scope prints only `*` | Whole. Slot taken. `files_inspected: null`. Phase 1 tests |
| Scope prints `*` plus file lines | Whole. File lines are not counted. Phase 1 tests |
| Scope prints file lines, no `*` | Not whole. No slot with `onlyWhenWhole`. Count as today. Phase 1 tests |
| Scope prints nothing | Not whole, count 0, no slot, "Inspected 0 files" note as today. Phase 1 tests |
| Scope command exits non-zero or times out | Step is an error, as today. No slot taken, no scope file left behind. Phase 1 tests |
| `onlyWhenWhole` without `inspecting()` | Config refused at load. Phase 1 tests |
| `onlyWhenWhole: false` (default) | Slot taken whatever the scope, as today. Existing slot tests stay green |
| Blocking path (`execute()`, runner called directly) | Same slot decision through `slotFor()`. Phase 1 tests |
| Whole-scope step queued behind a full slot | Queues and reports `waiting_for_slot` as today. Phase 1 tests |
| Scope file cannot be created | Step error, no process started, no slot left taken. Phase 1 tests |
| Run stopped or abandoned before the step ends | `$discard` deletes the scope file and frees the slot, as for output files. Phase 1 tests |
| Slotted step ends, no `next_step` follows | With pcntl: slot free within one signal. Without pcntl: freed at collection, as today. Phase 2 tests |
| Verdict collected after the handler freed the slot | `$discard` release is a no-op. Verdict carries the real exit code. Phase 2 tests |
| Step killed on timeout by `poll()` | Freed by `$discard` as today. The handler may fire first; both are safe. Phase 2 tests |
| Two slotted steps in one parallel group, one ends | Only the ended step's slot frees. Phase 2 tests |
| Another SIGCHLD handler was installed before | Chained, then restored when no slotted step runs. Phase 2 tests |
| SIGCHLD arrives while `poll()` reads a `Process` | Blocked by the mask, delivered when `poll()` ends. Phase 2 review; test asserts the mask helper wraps `poll()` |
| No `ext-pcntl`, or Windows | No handler. Behaviour as today. Tests skip without pcntl |
| Scope line is a file literally named `*` | Read as the marker. Documented in the README as reserved |

## Implementation

### Phase 1: Whole-scope slots (Priority: HIGH)

**ID:** whole-scope-slot · **Depends:** none

- [x] Add `Runner\Scope` value object — count, whole flag, scope file path
- [x] `resolveScope()` returns `Scope|null|Result` — parses the `*` marker, writes the scope file, deletes it on every error path
- [x] Feed `Scope::$count` to `filesInspected` and `describePass()` — whole gives `null` and no "Inspected 0 files" note
- [x] `Shell::exclusive()` gains `bool $onlyWhenWhole = false`, plus `slotOnlyWhenWhole()` — docblock states the marker and the env var
- [x] `InvalidPipelineConfigException::wholeSlotNeedsScope()` and the check in `Pipeline` next to `slotNeedsResponseWindow` — refuse at config load
- [x] `slotFor()` helper used by `begin()` and by `waitForSlot()` in `execute()` — one slot decision for both paths
- [x] `start()` sets `BOOST_PIPELINE_SCOPE_FILE` for a scoped step; `$discard` and `execute()` delete the file
- [x] README: `onlyWhenWhole`, the marker, the env var, and the reason to read the file; a scope command that cannot compute its selection must print `*` and exit 0, because a non-zero exit errors the step
- [x] Tests — every Phase 1 row of `## Edge Cases`, in `tests/Feature/MachineSlotsTest.php` and `tests/Unit/ProcessStepRunnerTest.php`; a step reads `$BOOST_PIPELINE_SCOPE_FILE` and echoes it; no `bp-scope-*` file remains after a pass, a failure, an error and a stop

### Phase 2: Free a slot when its process ends (Priority: HIGH)

**ID:** release-on-exit · **Depends:** whole-scope-slot

<!-- Depends edge: both phases edit ProcessStepRunner::launch(). -->

- [x] `launch()` returns a fifth `freeSlot` closure; update the `$running` and `$queued` docblock shapes in `ProcessPendingSteps`
- [x] Install the SIGCHLD handler while a slotted step runs — guard on `pcntl_signal` and `pcntl_async_signals`, chain the previous handler, keep the previous async flag
- [x] Handler frees the slot of each slotted step whose process no longer runs — no verdict, no `pcntl_waitpid()`
- [x] `withSignalsHeld()` helper; wrap `poll()`, `stop()`, `__destruct()` and the `launch()` start in it — no handler runs inside a `Process` status read
- [x] Restore the previous handler and async flag when the last slotted step settles or `stop()` runs
- [x] Update the test "holds the slot while the step runs, and frees it once the verdict is in" — the slot frees when the process ends
- [x] README: when a slot frees, with and without `ext-pcntl`
- [x] Tests — begin a slotted `sleep 0.2` step, never call `await()`, wait ~1s (`Sleep::usleep`), and `take()` succeeds; the later `await()` verdict keeps the real exit code (use `exit 3`); a previous SIGCHLD handler still runs and is restored; parallel group frees only the ended step; skip when pcntl is missing

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The handler does not steal the exit status.** If a step first seen ended inside the handler
   reports a wrong or missing exit code at collection, the handler design is unsafe. Stop and report;
   do not add `pcntl_waitpid()` or cache exit codes by hand.
2. **The stdio loop and `fwrite(STDOUT)` survive SIGCHLD.** If an async signal makes the MCP server
   drop or truncate a response, stop and report.
3. **Every `Process` read in the pending-steps path can run inside the mask.** If a read sits where
   the mask cannot wrap it, for example inside vendor code called from outside these classes, stop and
   report. Do not ship the handler without the mask.
4. **Both run paths resolve the scope before the slot.** Traced at the planned-at commit. If the
   order changed, stop: the slot decision needs the scope first.

---

## Open Questions

None.

---

## Resolved Questions

1. **How does a scope command say "everything"?** **Decision:** A stdout line that is exactly `*`. **Rationale:** Exit 0 stays the only success code, so existing scope commands keep their meaning. A threshold forces the fail-safe branch to print a long list.
2. **The scope command and the step can disagree. How is that handled?** **Decision:** The step gets the scope output in `BOOST_PIPELINE_SCOPE_FILE`. **Rationale:** A step that runs that exact selection cannot run a full suite without a slot.
3. **How does a finished step free its slot before collection?** **Decision:** A SIGCHLD handler, with `ext-pcntl` only. **Rationale:** Handing the lock to the step leaves a hung or orphaned tree holding the slot with no timeout, and reverses the slot-dies-with-holder guarantee.
4. **API shape?** **Decision:** `exclusive($name, $capacity, onlyWhenWhole: true)`. **Rationale:** One method for one concept; the default keeps current behaviour.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **Phase 1 — scope file written at launch, not in `resolveScope()`.** `Scope` keeps the scope
  output; `Scope::toFile()` writes it when the step starts (`launch()`, `execute()`). A step still
  queued for its slot then has no file, so `stop()` on a queued step cannot leak one. `toFile()`
  returns `false` on failure.
- **Phase 1 — class complexity.** `ProcessStepRunner` sat just under PHPStan's class cognitive
  complexity cap of 80. To stay under it, the queued-start closure moved out of `begin()` into
  `startOnceFree()`, the line helpers moved into `Scope::fromOutput()`, and temp-file removal shares
  one `remove()` helper. No behaviour change.
- **Phase 1 — marker match trims the line.** A line `  *  ` counts as the marker. A line such as
  `src/*.php` does not.
- **Phase 2 — mask covers status reads only, not the whole poll.** A process started while SIGCHLD
  is blocked inherits the mask. So `poll()` starts queued steps and builds verdicts outside the
  mask, and holds SIGCHLD back only in `ended()`, where Symfony reads and closes each process. A
  process found ended there is closed, so later reads of it are safe from a nested handler.
- **Phase 2 — `stop()` removes the handler first** instead of masking. It then runs `onAbort`
  commands without SIGCHLD blocked, and no handler reads a process while it is terminated.
- **Phase 2 — an ended step leaves the handler's sight while SIGCHLD is still held.** `ended()`
  clears the step's `freeSlot` under the mask; the step stays in `$running` until its verdict is
  built, so `stop()` still cleans it up if a verdict throws. Linux delivers the SIGCHLD of a
  process reaped under the mask when the mask is released, so without this a timed-out step loses
  its slot before its `onAbort` command runs. macOS drops that signal. The test "keeps the slot of
  a step stopped on its timeout until its onAbort command has run" fails on Linux (php:8.4-cli
  container) without the clear, and passes with it. On macOS it cannot tell the two apart.
- **Phase 2 — one handler for all instances.** Several runs can have slotted steps at once. One
  handler per instance restored the others' handlers out of order, so a second run lost early
  release. A static registry of weak references now shares one handler, restored when the last
  instance stops watching.
- **Phase 2 — restore unblocks only what it blocked.** `pcntl_sigprocmask()` returns the old mask
  as `mixed` to PHPStan, so the helper unblocks SIGCHLD unless it was already blocked, instead of
  restoring the whole old mask.
- **Phase 2 — STOP condition 2 traced.** `pcntl_signal()` sets `SA_RESTART` by default
  (`restart_syscalls: true`), so an interrupted `write()` to STDOUT restarts.
- **Phase 2 — no test proves the mask wraps `ended()`.** It cannot force the interleaving. Review
  checks it.
- **Phase 2 — the handler holds the instance through a `WeakReference`.** A handler closure bound to
  `$this` keeps a dropped `ProcessPendingSteps` alive, so its `__destruct()` never stops the steps
  it started. Not in the spec; found in review. A test drops a run with a slotted step running and
  checks the slot frees and the old handler is back.
- **Phase 1 — untested: a scope file that cannot be created.** `tempnam()` falls back to the system
  temp dir, so no test can force the failure. The same holds for the existing output-file error.
