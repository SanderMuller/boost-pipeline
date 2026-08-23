# Changelog

All notable changes to `sandermuller/boost-pipeline` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

There is deliberately no `[Unreleased]` section. `update-changelog.yml` prepends each release body
on publish, so an entry written here before a release is duplicated by the section the workflow
adds — which happened at every release that had one. Unreleased work lives in the release notes
draft until it ships.

## v0.4.0 - 2026-08-23

<!-- verified-sha: 4c7295a3289be4775b51fe6cf24de9f125562651 -->
A run can now be read from outside the session that produced it, a skill step can prove it did the
work, and configuration surface that two consuming applications never touched is gone.

### Breaking

Pre-1.0, so these land in a minor. Every removal is configuration or contract surface that no known
consumer used; a `.config/pipeline.php` that only calls `withSteps()` needs no changes. See
[UPGRADING.md](https://github.com/SanderMuller/boost-pipeline/blob/main/UPGRADING.md) for the
migration of each one.

- **`Step::before()` and `Step::after()` are removed from the contract.** Every custom step had to
  implement both, and no shipped step ever put anything in either. The test written to cover "a step
  whose setup throws" recorded why: the runner refuses a non-`Shell` step before it reaches
  `before()`, so the path it asserted was already unreachable. Leaving the methods on a custom step
  is legal PHP and breaks nothing loudly — which is the reason to read the upgrade note, because the
  package stops calling them and setup written inside one quietly stops running.
  
- **`Steps::between()` is removed.** It anchored a step at the join between two phases.
  `prepend()` on the later phase puts the step at the same point in the walk; only the reported
  phase label changes. Most of what goes with it is the machinery that kept the position honest —
  the splice, the placed-transition bookkeeping, and the five match arms that each named a different
  way anchors could fail to describe a real join.
  
- **`Pipeline::withPhases()` and `Pipeline::phases()` are removed**, with `Phases::append()`,
  `prepend()`, `remove()`, `moveAfter()`, and the `PhasePosition` class. `Phases::DEFAULTS` is the
  whole set. A phase is only a named, ordered group of steps, so a step runs at the same point
  whether it gets its own phase or joins the one that already runs there.
  

### Added

- **A run leaves a receipt, and `php artisan pipeline:verify` turns it into an exit code.** Until
  now a run's verdicts were readable only by the MCP session that produced them, so a commit hook or
  a CI gate had nothing to ask. The command exits 0 only when a recorded run verified the tree that
  is on disk now, and it fails on four counts: no receipt, a different tree, a run that reports
  itself stale, and a run where not every step was verified.
  
  **No run is a failure.** That is the whole reason for the command — a gate that reads a missing
  answer as "nothing to check" passes exactly the run that never happened.
  
  A receipt is a file in the working copy, so anything that can run a shell step can write one. It
  is not evidence that a run happened and it closes no trust hole; an agent able to forge it could
  already claim a pass in prose. What it carries is the part prose cannot get right: which tree the
  verdicts describe.
  
- **A skill step can prove itself with `->proving()`.** A skill step is normally `acknowledged`,
  never `passed`, because the server cannot verify that the agent invoked anything. A declared proof
  changes what the server knows: it runs the command and reads an exit code, so the step reports
  `passed`. A failing proof blocks and returns the same step, so "I did it" without the artifact is
  not a way past the cursor.
  
  ```php
  $steps->in(Agent::class)->append(
      Skill::run('/eye-verification')
          ->proving('find storage/verify -name "*.png" -newer .git/HEAD | grep -q .')
  );
  
  ```
  A run whose skill steps all carry proofs can reach `all_verified`, which was impossible for any
  configuration with an `Agent` phase.
  

### Fixed

- **An invalid `.config/pipeline.php` no longer leaves an error on the protocol stream.** The server
  used to decline to register, and the resulting `ERROR ... not found` went to stdout — which for a
  stdio MCP server *is* the JSON-RPC channel. One consumer's client reported a confusing cause; the
  other's driver hung on the unparseable frame. A degraded server now registers in its place and
  returns the configuration error through the protocol, as an error to the tool call that asked.

### Documentation

- The Extending section described two extension points where the shipped runner only has one.
  `ProcessStepRunner` runs `Shell` and refuses everything else, and a step reporting
  `StepKind::Skill` is acknowledged by the agent rather than run — so a custom `Step` has nowhere to
  resolve until a custom `StepRunner` is bound too. The section now says which binding to replace.

### Verified

155 tests, 379 assertions. PHPStan level max, Rector and Pint clean. CI green on this commit across
all four matrix legs — PHP 8.4 and 8.5, Laravel 12 and 13, `prefer-lowest` and `prefer-stable`.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.3.2...v0.4.0

## v0.3.2 - 2026-08-23

Consumer feedback on 0.3.1. Two fixes for what a first-time adopter runs into, and the package now
applies to itself a check it had only been recommending.

### Fixed

- **A pipeline-config error no longer writes onto the protocol stream.** For a stdio MCP server
  stdout *is* the JSON-RPC channel, and an invalid `.config/pipeline.php` had the framework's
  exception renderer print a rendered trace there — a client received one valid frame followed by a
  run of malformed ones. What the operator then saw depended on their client: one surfaces
  unparseable stdout and reports the real cause, another only says the server failed to start.
  
  The message goes to stderr and the server is not registered. This applies to the validation
  errors this package raises; a syntax error or a `TypeError` in your config still fails loudly,
  because those are defects in your own code and hiding one behind a tidy message would be worse
  than the trace.
  
  Checked only when starting the server, so an unrelated artisan command does not execute your
  config — including when an artisan command *is* a pipeline step.
  

### Documentation

- **The `.config/` note now covers the formatter, which is the half that bites.** Pint skips
  dot-directories on its default scan, so `vendor/bin/pint --test` reports clean with a formatting
  violation sitting in `.config/pipeline.php`. The path has to be an argument:
  
  ```bash
  vendor/bin/pint --test . .config
  
  
  ```
  An `exclude` entry in `pint.json` is unrelated — there is no `include`. Naming the analyser
  alone, as the 0.3.1 note did, was half the advice.
  

### Internal

- This package now analyses and formats its own `.config/`, in the composer scripts and in CI,
  having recommended it without doing it.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.3.1...v0.3.2

## v0.3.1 - 2026-08-23

Consumer feedback on 0.3.0. Nothing here changes what a verdict means; it closes the gaps two
projects hit while wiring it up.

### Added

- **`Pipeline::configure()->withTimeout(seconds)`** sets the ceiling for every step that does not
  set its own. A per-step `->timeout()` already covered the one slow step, but a project whose
  suite is slow throughout had to repeat itself on every step, and the runner's own default was not
  reachable from configuration at all. A step's own value still wins.
  
- `withTimeout()` and `Shell::run(...)->timeout()` reject zero or less. The process runner reads
  zero as *no* limit, so what looks like tightening a cap removed it — and a step that never
  returns holds the tool call open until the client gives up.
  

### Changed

- **`all_verified` is answered from the first result onward, in any state**, rather than only once
  the walk finished. `blocked` and `halted` are both retryable, so a run sits in them while the
  agent decides what to do next — which is exactly when a consumer asks whether the run can be
  trusted, and an absent key left "absent" and "false" to be told apart. `acknowledged` and
  `stale` travel with it. A run with no results yet still omits all three: there is nothing to
  answer.

### Fixed

- **`all_verified` and `stale` come from a single reading of the tree.** Each used to read for
  itself, so a change landing between the two produced one response carrying `all_verified: true`
  beside a `stale` message. A payload that contradicts itself is worse than either field being
  wrong alone, because it leaves no way to decide which half to believe.
  
- `open_run` keeps its run id when the tree changes before any step has run. A run with no
  receipts has no verdict to lose, so it adopts the new tree instead of being replaced — opening,
  editing, and opening again used to hand back three different ids.
  

### Documentation

- Add `.config/` to the paths your static analyser and formatter cover. `.config/pipeline.php` is
  PHP that runs in your application but sits outside the paths most projects analyse, so a real
  config shipped a `shell_exec()` its own project bans — invisible to a full-project run.
  
- The empty-test-selection warning shipped in 0.3.0 but was missing from its notes: a selection
  command that prints nothing and exits 0 collapses `php artisan test $(...)` to the whole suite.
  

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.3.0...v0.3.1

## v0.3.0 - 2026-08-23

A run's verdicts now expire, and a session is no longer limited to a single run. Before this, a run
that went green stayed green while you edited the code it was about — so "this run passed" meant
"this passed at some earlier moment", which is not something a gate can act on. And because
`open_run` never started a second run, the fix loop that is the whole point of a verification
pipeline needed a server restart.

### Breaking

- `Step` gained `mutates(): bool`. `Shell` and `Skill` implement it; add it to your own
  implementations. `OpenRun` takes a `CommandPreflight` as a second constructor argument, which
  matters only if you build the tool yourself rather than resolving it from the container. See
  [UPGRADING.md](https://github.com/SanderMuller/boost-pipeline/blob/main/UPGRADING.md).

### Added

- **A pass records the tree it measured, and expires when that tree changes.** The fingerprint is
  the commit plus the contents of everything dirty or untracked, with ignored paths excluded so the
  run's own logs and caches never count. `all_verified` turns false once a pass no longer matches
  what is on disk, and a `stale` key names the step.
  
  Only a pass records a tree. A rewriting step is exempt — its verdict says the tool ran, not that
  the tree is in some state. An acknowledgement and a failure are exempt too, since neither claims
  verification. So fixing a blocked step and retrying it is not mistaken for tampering.
  
- **`open_run` starts a fresh run once the tree has changed**, and returns the run already open
  while it has not. Run, see a failure, change the code, verify again — without restarting anything.
  
- **A step declares whether it rewrites code**, with `->mutating()`. Attribution is by declaration,
  not by timing: "whatever step was running must have done it" would absorb an edit made *while* a
  step ran, and a blocked run is exactly when you go and change something. A change nothing
  declared is reported rather than explained away, which makes "a gate uses the tool's check mode,
  never its fix mode" something the package keeps rather than a note in a config file.
  
- **`Shell::run(...)->timeout(seconds)`** per step. One cap for every step has to be set for the
  slowest, which leaves it useless for the rest — a real suite measured 336s against the 540s
  default.
  
- **`open_run` warns about a missing binary**, so a walk no longer pays for every earlier step
  before discovering that step three cannot run.
  

### Changed

- **`next_step` retries a halted step** instead of refusing for the rest of the session. An
  `error` means the tool could not run, which is the kind of thing that then gets fixed; the
  cursor stays put, so only that step re-runs and earlier verdicts stand.
  
- **A step summary is readable for tools that draw.** Escape sequences and carriage-return redraws
  are stripped, and truncation keeps the head *and* the tail. A test-runner summary previously
  arrived as an escape-wrapped dot repeated to the limit with the verdict pushed out of view, and a
  refactoring tool's was almost entirely progress frames. The summary is the only step output
  visible without opening a log.
  

### Documentation

- The README warns that an empty test selection runs the whole suite. `php artisan test $(...)`
  with a selection command that prints nothing and exits 0 collapses to `php artisan test` — the
  entire suite, passing slowly. Shipped in this release; omitted from its release notes.

### Requirements

Unchanged: PHP 8.4+, Laravel 12.41+ or 13.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.2.0...v0.3.0

## v0.2.0 - 2026-08-23

A dogfooding release. Using the pipeline on a real change surfaced that a run's logs could not be
found from the run the server reported, and fixing that properly meant changing one interface.

### Breaking

- `StepRunner::run()` takes the run id as a second argument: `run(Step $step, string $runId)`.
  `ProcessStepRunner` no longer takes a `runId` constructor argument.
  
  This affects you only if you implement `StepRunner` or construct `ProcessStepRunner` yourself. A
  pipeline that configures steps in `.config/pipeline.php` needs no changes. See
  [UPGRADING.md](https://github.com/SanderMuller/boost-pipeline/blob/main/UPGRADING.md) for the
  before and after.
  

### Fixed

- A run's log files are named after the run id the server reports. The service provider minted an
  id of its own and handed it to the step runner, while `Run` minted the id every response
  carries — so each log was named after an id no response ever mentioned, and the path returned in
  a result could not be traced back to the run that produced it.
  
  That id was also scoped to the process rather than the run, so a second run through the same
  runner reused the first run's filenames. `RunManager` holds one run and its `open()` is
  idempotent, so the MCP path could not reach a second run — the collision was latent, and closing
  it now keeps a future reset or restart flow from making it live.
  
- A run id and step id are reduced to filename-safe text before reaching the log path. Only a
  derived step id was slugged, so an explicit `Shell::run(id: ...)` arrived verbatim and could put
  separators or `..` into the path. An id that gets rewritten also carries a short hash of the
  original, because `a/b` and `a b` reduce to the same text while the walk checks uniqueness on
  the raw values.
  
  Visible effect: if you pass an explicit step id containing characters outside `A-Za-z0-9._-`,
  that step's log filename changes. The step id itself is untouched, so `status` and every response
  still report what you configured.
  

### Documentation

- The README explains why the server hands over one step at a time, not just why it owns the
  verdict. A list of checks competes for attention with the work it arrives beside, and the checks
  near the bottom get whatever is left — the run comes back partial while the report says done.
- `UPGRADING.md`, and changelog and security pointers in the README.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.1.0...v0.2.0

## v0.1.0 - 2026-08-22

First release. A verification pipeline that runs as an MCP server: the server executes each
check and owns the verdict, and hands the agent one step at a time.

The guarantee is not that the agent cannot see the pipeline — `.config/pipeline.php` is a file in
your repository and the agent can read it. It is narrower: the server only ever executes the step
at the cursor, and the cursor only advances when that step resolves. Reading ahead tells the agent
what is coming; it does not let the agent obtain a receipt for it.

#### Added

- Pipeline configuration in `.config/pipeline.php`, with phases, ordered steps, and transitions
  between phases. A project without that file opts out entirely — the server registers no tools.
- Five default phases: Formatting, Refactoring, StaticAnalysis, Tests and Agent. Phases can be
  appended, reordered, and pinned last; steps can be prepended or appended within a phase.
- Two step kinds. `Shell` steps run a command and the server decides the verdict from the exit
  code. `Skill` steps are handed to the agent, which must acknowledge them with a summary — the
  server cannot verify a skill, and does not pretend to.
- Four MCP tools: `open_run`, `next_step`, `report_step` and `status`. Opening an already-open
  run resumes it where it stands rather than discarding verdicts.
- Per-step logs under `storage/logs/pipeline/`, with the path returned in the step's result.
  Output is summarised for the agent and truncated rather than flooding the context.
- Environment scrubbing, so a step re-reads the application's `.env` instead of inheriting the
  booted values, and `Shell::withEnv()` for a step that needs to pin its own — a test step
  setting its own database is the case that motivates it.
- Notices instead of silence when the configuration cannot be honoured: a transition whose anchor
  phase was removed, anchors that are registered but not adjacent, anchors in reverse order, and
  steps declared into a phase that is not registered.

#### Known limitations

Read "What it deliberately does not do" in the README before relying on this. It is a prototype,
wired into no existing skill, and several designed behaviours are deferred. `laravel/mcp` is
still pre-1.0, so its API may move between minor releases.

#### Requirements

PHP 8.4+, Laravel 12.41+ or 13.

### What's Changed

* Bump zizmorcore/zizmor-action from 0.6.0 to 0.6.2 by @dependabot[bot] in https://github.com/SanderMuller/boost-pipeline/pull/1

### New Contributors

* @dependabot[bot] made their first contribution in https://github.com/SanderMuller/boost-pipeline/pull/1

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/commits/v0.1.0
