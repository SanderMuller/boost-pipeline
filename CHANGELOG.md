# Changelog

All notable changes to `sandermuller/boost-pipeline` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
