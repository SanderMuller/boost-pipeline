# Invariants, and the ways they have already been broken

A green run has to mean something, or this package is worse than the prose skills it came from.
Everything below is load-bearing. Each entry says what must hold, and most of them say how it
was already broken once — because every one of these defects was written by someone who
understood the design and still shipped the bug.

If a change makes one of these hard to keep, stop and rethink the change. Do not weaken the
invariant.

## The five that cannot bend

### 1. `error` is never reportable as a pass

A tool that **did not run** is not a tool that found nothing. Binary missing, timeout, thrown
exception, exit 126 or 127 — all of these are `Verdict::Error`, and an `error` halts the run.
Conflating them with `passed` would make the pipeline worse than useless.

### 2. `acknowledged` is never reported as `passed`

An agent step with no proof is a self-report. The server cannot verify that `/evaluate` ran.
Reporting that as a pass would launder a claim into a receipt, which is the exact fault this package
exists to remove.

A declared proof (`Skill::proving()`) does not bend this. The server runs a command and reads an exit
code, so the step is `passed` because something was verified — never an `acknowledged` result
relabelled. `proveOrAcknowledge()` returns one or the other and never converts between them. What a
proof cannot do is make judgement checkable: it checks for an artifact, so a step whose work leaves
no trace has nothing to prove and keeps `acknowledged`. A proof over an artifact that exists only
because someone wrote the proof command is the laundering this invariant forbids, wearing the
feature's clothes.

`Verdict::isVerified()` is true for `Passed` **only**. Every pass/fail count goes through it, so
no caller has to remember the rule.

Keep that distinct from `Result::serverRun()`, which answers *who produced the verdict* and is
therefore true for `failed` and `error` as well. Two different questions, two different methods.
They live on different classes on purpose — putting them side by side recreates the ambiguity a
Codex review pass already found once.

### 3. `complete` never implies green

`state: "complete"` means the walk finished. Nothing more. A run of nothing but
acknowledgements reaches `complete`, and there is a test for exactly that.

`all_verified` is the honest signal, it is mandatory on every terminal response, and it is
`true` only when every result was a server-verified pass **and** the walk dropped nothing.

### 4. The cursor advances in exactly one place

`Run::resolveCurrent()` and `Run::settleState()` are the only code that moves the cursor, and
`settleState()` moves it by the width of the position so a parallel group advances or holds as one
unit. The guarantee is only as strong as its weakest copy, so if that logic ends up duplicated per
tool, stop and restructure.

### 5. An advisory tool is never a strict gate without its fail flag

The question to ask of every step: *if this tool finds a problem, does the process exit
non-zero?* Two tools in the consuming repo answer no.

- `yarn lint` is git-diff-scoped. With no changed TypeScript it exits 0 without linting
  anything. A consuming project should point the step at an unscoped `lint-all` script instead.
- `richter:detect-changes` is advisory by default. It exits 0 whatever it finds unless
  `--fail-on=<level>`, `--fail-on-unresolved` or `--fail-on-hazard=<tier>` is passed.

Adding a step is one line, which is exactly what makes it easy to add a step whose exit code is
decorative. `files_inspected` catches the first shape (a step that declares its scope reports 0)
and does **not** catch the second.

## Every way a run could still be green and wrong

Gathered in one place, because a reader deciding whether to trust a run needs the whole list at
once rather than scattered across a limitations section.

| # | False green | Live? | What closes it |
|---|---|---|---|
| 1 | A step's exit code does not reflect its finding | **Yes** | Reviewing each step's exit semantics; `files_inspected` catches one shape of it |
| 2 | An `acknowledged` step is read as verified | **Yes**, if a consumer totals the counts | `isVerified()`, separate keys, and a report that never merges them |
| 3 | A step passed, then the code changed | **Yes** | Fingerprint invalidation (v2). Receipts never expire in v1 |
| 4 | The agent walks away mid-run and opens the PR anyway | **Yes** | Hook hardening, not built |
| 5 | **The run was never opened at all** | **Yes** | Nothing here can close it |
| 6 | A tool did not run and that read as "found nothing" | No | The `error` verdict |
| 7 | Steps ran out of order, or a receipt was fabricated | No | The server owns execution; the cursor advances in one place |
| 8 | Pre-existing failure blocks, so someone loosens the step to get green | **Live risk** | Use the tool's own baseline, or fix it. Do not weaken the step |
| 9 | A consumer reads `complete` as green | **Yes**, if `all_verified` is ignored | `all_verified` on every terminal response |
| 10 | A scoped run reads as a full one | No | The receipt records the scope, and a bare `pipeline:verify` fails on a scoped receipt rather than answering a question the run cannot |
| 11 | `--server-verified` passes a run the server verified nothing of | No | The empty set is rejected before the predicate, because "every server verdict passed" is vacuously true over it |
| 12 | `--server-verified` passes a walk that never finished | No | The state guard. A receipt is written after every resolution, so an abandoned walk leaves a readable receipt holding one pass |
| 13 | `--server-verified` passes a run that dropped a declared gate | No | The `coverage` key. `all_verified` conflates a dropped gate with an acknowledgement, and this flag accepts the second — so the receipt has to record the first separately, and an absent key fails closed |
| 14 | A selection matches nothing, so only untagged steps run and pass | No | Blocking on its own, through `Walk::$selectionCarriedNothing` rather than through notices wholesale. It drops NOTHING — the walk becomes every untagged step, which pass — so when `all_verified` became scope-accurate and stopped reading notices, reading the dropped list alone would have deleted this guard silently. Measured separately for exactly that reason |
| 15 | `--server-verified` passes a run whose passes only rewrote the tree | No | The `asserted` key. A `->mutating()` step produced the tree rather than reading it, so its pass describes the code it was handed. `Run` already excludes such a step from staleness; the receipt now records the same distinction, and an absent key fails closed |
| 16 | `--server-verified` answers with no tree to answer about | No | Both fingerprints are required. The bare call deliberately tolerates a missing one and answers from the receipt; this flag cannot, because it exists so a caller can skip work on the strength of the tree still matching |
| 17 | A malformed receipt field reads as the permissive value | No | `Receipt::fromArray()` rejects a present-but-wrong-typed `tree`, `stale`, `scope`, `coverage`, `recorded_at`, `all_verified` or `asserted`. Coercing to null read as not stale, unscoped, and unfingerprinted — every one of them the permissive direction |
| 18 | Exit 0 is read as "the checks I care about ran" | **Live risk** | Partly closed: the success message names the step ids it counted, so a caller can see what the pipeline actually holds. Nothing stops a pipeline that declares no static analysis from exiting 0 — it verified what it ran |
| 19 | A receipt holding no verdicts reads as verified | No | Both calls refuse an empty verdict map. `all_verified` is a claim the receipt makes about itself and is vacuous over nothing, and guarding the predicate closes an absent key, an explicit null and an empty map at once — one JSON shape at a time does not |
| 28 | A scoped run reads as unverifiable because another scope is broken | No | `all_verified` and `coverage` read the walk's own dropped steps, filtered by its selection, rather than the unfiltered notices. A run answers for the scope it claimed. The tag-carries-nothing case is measured separately and still fails `pipeline:verify` for every run, because it drops nothing and would otherwise let a mistyped tag report a verified run for a scope never checked |
| 20 | One pipeline's pass reads as the project's | No | A bare `pipeline:verify` refuses once a project declares several, naming them. There is deliberately no aggregate answer: a project that routinely runs one pipeline could never reach exit 0 through it, and a gate that cannot pass is one people learn to skip |
| 21 | A tool acts on a pipeline nobody named | No | `RunManager` refuses a null name whenever the config NAMES its pipelines — a map of one included, so adding a second later breaks no call site that was already correct. Never the most recently opened run — that would advance the wrong cursor, run the wrong steps and write a verdict into the wrong receipt, silently. The schema declares the argument required; the refusal is the guard |
| 22 | `--server-verified` is reached for on a walk it cannot answer | **Live risk** | Not closeable in code: a walk whose mechanical steps are the likely failure never reaches `complete`, so the flag is silent exactly when an answer was wanted. It is a pipeline-shape question — put the fast checks ahead of the judgement step, or accept that this flag is not for that walk. Documented next to the flag |
| 23 | `pipeline:history` exit 0 is read as a pass | No | It reports rather than gates, and exits 0 for every answer it can give — an empty history, a stale run, a failed run — so nobody can read a zero as a verdict. Non-zero means only that it could not answer. `pipeline:verify` owns the gate exit code, and the README says so beside both commands |
| 24 | A live record left by a dead server reads as a run in flight | **Live risk** for an awaiting one | A `running` record past the ceiling its runner enforces reads as interrupted, because that runner kills a step at the timeout. An `awaiting` record has no ceiling to measure against — the package deliberately does not time out a skill step — so nothing distinguishes a slow agent from a dead server. The page and the command report how long it has waited and leave the judgement to the reader, which is the same answer the package already gives for a wait that is genuinely long |
| 25 | A page reads as verified because it rendered | No | Every surface reports `all_verified` and whether the receipt's tree still matches the code on disk. The page is a projection of the receipt, never a second source of truth: the read model resolves one walk per receipt and two readers share it, so a terminal and a browser cannot disagree about a run |
| 26 | A step the server never loaded reads as covered | No | `coverage` is written from the walk's own dropped steps and its tag selection, so it reports a step the server LOADED and dropped, within the scope the run was about. A step the server never loaded raises no notice, leaves no verdict, and lands in a receipt calling itself `complete` — and the tree fingerprint matches, because the run ran against the tree that already held the new step. The MCP server resolves the config once at process start, so this is reachable by editing config mid-session. `pipeline:verify` runs in its own process against the config as it stands now, and refuses a run missing any step declared in the scope the answer is about — the receipt's own for a scoped run, otherwise the one the caller asked about. Every call also refuses a config that declares a step no phase registers, which would otherwise be missing from the comparison as well as from the run. That was a whole-tree check only while the walk described a drop in prose, which cannot say which scope the step belonged to; the walk now reports its drops as data, filtered during resolution by the same predicate it selects steps with, so a scoped call refuses a drop inside its own scope and ignores one outside it. A run whose scope declared four steps and recorded three exited 0 |
| 27 | A step the server heard of DIFFERENTLY reads as verified | No | Row 26 catches a step the server never loaded. This is the other half: the server loaded an older DEFINITION of the same step id — an old command, an old skill proof, a `->mutating()` flag since added — and recorded it as a pass. The verdicts are keyed by step id, so nothing looks missing, and the tree fingerprint matches because the run ran against the tree that already held the change. A run now records a digest of the whole declaration it walked, and `pipeline:verify` refuses a run whose digest is not the one the config produces now. The refusal names three causes rather than blaming the server, because a config git cannot see and a config that computes itself at load time both produce the same mismatch — and the third cannot be fixed by reconnecting anything |

**Row 5 is the one no mechanism addresses.** A run that never happened produces no false green —
it produces *no signal*, which a reader may mistake for one. Anything consuming this (a PR
checklist, `pr.gates`) must treat "no run" as failing, not as absent. That is a requirement on
the **consumer**, and it is why wiring into `pull-requests` was deferred rather than half-done.

## The near misses

### A false-green generator in the runner, with a test defending it

The first `ProcessStepRunner` returned a pass *before running the command* whenever a declared
scope resolved to zero files. Probing it found three separate faults: a step exiting 9 reported
`passed` with the command never run; a typo'd scope glob disabled the gate permanently; and a
**broken** scope command produced zero lines, read as "empty scope", and also handed back a
pass.

Worse, the test suite asserted `exit 1` + empty scope → `passed`. The test defended the bug.

Fixed: the command **always** runs, an empty scope only annotates the verdict, and a scope
command that cannot run or exits non-zero yields `Verdict::Error` — a declared-but-uncomputable
scope is a broken config, not an empty one.

### A declared gate vanishing without trace

The first walk resolver only reported a dropped transition step when an anchor phase was
*missing*. Anchors that were both registered but **not adjacent**, or in reverse order, were
dropped with no notice at all. A gate the config declared would simply not run, silently.

Now every unplaced transition is reported with the specific reason: missing anchor, wrong order,
or which phases sit between them.

### `all_verified` ignoring the notices

Found by a Codex review pass. A run could finish `all_verified: true` while a declared gate never
ran, because a dropped step produced only a notice and `allVerified()` never looked at notices.
The guard is now `$this->results === [] || $this->walk->notices !== []`.

### `files_inspected` defaulting to 0

`Result::$filesInspected` was an `int` defaulting to `0`, which made *every* step look like it
inspected nothing — inverting the vacuous-pass signal the field exists to raise. It is `?int`
now: `null` means unknown and is omitted from the payload, and only a step that declares its
scope reports a number.

### A tally with a dynamic key

`serverRunTally()` counted with `$tally[$result->verdict->value]++`. It never fired, because the
`serverRun()` guard skipped acknowledged results — but the type safety rested entirely on that
guard staying correct, and `server_run` exists precisely to hold server-produced verdicts only.
It is an exhaustive `match` on the enum now, so a future verdict cannot silently open a fourth
bucket.

### Two ids for one run

Found by dogfooding the first release. Every response reported one run id while the log files
were named after a different one, because the service provider minted a second id of its own and
handed it to the step runner. The id an agent was given could not be used to find that run's
logs.

The latent half was worse. The provider's id was generated once per **process**, not once per
run, so a second run in the same process overwrote the first run's logs step for step. Only
in-process state, which allows one run per process, kept it hidden.

`StepRunner::run()` now takes the run id as a parameter, so it flows from `Run` — which owns it —
to the only place that uses it. There is no second generator to drift.

Two lessons generalise. **A value with one owner should have one generator**: the duplicate was
not a typo, it was two reasonable-looking lines written in different files at different times.
And **a fake runner cannot see this class of defect at all**, because it writes no logs. The
regression test in `tests/Feature/RunLogNamingTest.php` goes through a real `ProcessStepRunner`
for exactly that reason.

### Two tasks checked off without being implemented

A review pass found `outputSchema` and `shouldRegister()` both ticked while neither existed in
`src/`. Checkbox progress is not evidence. Grep for the thing before believing the tick.

### The tool names were wrong everywhere

Caught only by driving the real server. See
[laravel-mcp-notes.md](laravel-mcp-notes.md#tool-names-default-to-kebab-case).

## Test discipline that caught the above

### A test file never imports a global class

Pest test files sit in the global namespace, so `use Closure;` or `use RuntimeException;` in one is
a redundant import. PHP raises a compile warning, `phpunit.xml` sets `failOnWarning`, and the suite
exits 1.

The trap is what that looks like: the agent output formatter still prints
`{"tool":"pest","result":"passed"}` with the real failure only in `warnings`, so reading the verdict
instead of the exit code says the suite is green. This has turned the build red three times.

Reference a global class directly in a test file, and check `$?` rather than the formatter's
`result` key.


**Mutation-check the guards; do not just run the tests.** A test that passes without exercising
the change is not coverage. Every invariant above has a test whose failure was confirmed by
breaking the code on purpose:

- Advancing the cursor on a `failed` verdict → 3 tests red
- Counting acknowledgements as verified → 2 red
- Letting the server execute a skill step → 1 red
- Merging acknowledged into the `server_run` tally → 1 red
- Making exit 127 a plain failure, removing the vacuous-pass branch, downgrading a timeout to
  `failed` → 1 red each
- Removing the duplicate-id guard, promoting orphaned transitions → 1 red each
- Renaming `all_verified` in the output schema → 4 red
- Naming a log file after anything but the run id → 2 red

If a guard's mutation turns nothing red, the guard is not tested — whatever the coverage number
says.

**Drive the real server, not only the harness.** Unit tests missed a defect that broke the whole
flow, because they never spoke the protocol. The end-to-end walks that caught it were: a green
walk, a red walk with a deliberately introduced error (asserting the *same* step comes back and
that fixing it advances), and an error walk with the binary moved aside (asserting `isError`,
exit 127 and `halted`).
