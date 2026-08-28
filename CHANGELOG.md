# Changelog

All notable changes to `sandermuller/boost-pipeline` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

There is deliberately no `[Unreleased]` section. `update-changelog.yml` prepends each release body
on publish, so an entry written here before a release is duplicated by the section the workflow
adds — which happened at every release that had one. Unreleased work lives in the release notes
draft until it ships.

## v0.10.5 - 2026-08-28

### Fixed

- Error paths threw away the output they had already captured. A timeout, a signal and a failing scope command all discarded the process buffer — the single most useful diagnostic a timeout can produce — and exit 126/127 wrote its log but put the raw, unbounded text into the payload. Every path that has captured output now writes it to `storage/logs/pipeline/<run>-<step>.log` and returns a bounded summary carrying that log path. Two paths stay bare because they have nothing captured: a process that could not be built, and one whose launch failed.
- A phase named but never appended to made every run unverifiable. `Steps::in()` registers a phase on access, so `$steps->in(SomePhase::class);` with nothing appended left a phase holding no steps. If that phase was not also registered in the pipeline, the walk emitted a dropped-steps notice naming no steps, and that notice pinned `all_verified` to false and coverage to `incomplete`. The run could never exit 0, and the message could not tell you what to fix, because nothing had been dropped. A phase holding at least one step still produces its notice with its step ids, unchanged.

### Changed

- The README is about a third of its former length. It now covers what the package does and how to configure it; the reasoning behind the design moved to the design notes that ship in `.ai/docs/`.

### Internal

- Three documented paths did not match the code. The design notes and the design history both put step logs at `storage/pipeline/logs/`, and the provider binds `storage/logs/pipeline/`. The README still named the receipt file as it was before 0.10.0, when receipts moved to `storage/logs/pipeline/receipts/<name>.json`.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.10.4...v0.10.5

## v0.10.4 - 2026-08-27

### Fixed

- A `laravel/mcp` 0.x update that moved or removed a symbol this package binds at boot produced a raw PHP fatal on stdout — the JSON-RPC channel a client cannot parse a fatal from, which reads as a broken server rather than a version problem. The service provider now checks that surface before registering, and on a miss writes one line to stderr naming the missing symbol and declines registration. The `InvalidConfigServer` fallback is skipped deliberately in that case: it needs the same surface.

### Internal

- `.ai/docs/laravel-mcp-notes.md` claimed the provider already performed this check. It did not. The design record now describes what ships, including which symbols are not checkable — `Tool::shouldRegister()` and the `Mcp` facade's `local()` are resolved dynamically, so neither answers `method_exists`.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.10.3...v0.10.4

## v0.10.3 - 2026-08-26

Two crash fixes for pipelines that use a numeric step id, and one that could
discard a verdict the server had already earned.

### Fixed

- A step declared with a numeric id (`Shell::run(..., id: '123')`) crashed with a `TypeError`. PHP coerces a numeric-string array key to an int, which the batch runner then passed to `settle(string $stepId)` and the staleness check passed to `Walk::isGrouped(string $stepId)`. Both now carry the id as a string. The staleness path was the worse of the two: it failed exactly when the run needed to report that its verdicts had expired, and the receipt for that resolution was never written.
- An unwritable log directory turned a real verdict into an error. `LogWriter::write()` suppressed a failing `mkdir` but not the following `file_put_contents`, so a read-only mount or a bad owner after a deploy raised an `ErrorException` that escaped the runner and failed the whole tool call — discarding a verdict the step had already produced. Losing the log now loses only the log.

### Internal

- The CI matrix gained a Laravel 12 `prefer-stable` cell. Only the declared floor (12.41.1) was exercised before, leaving every later 12.x release untested against the `^12.41.1||^13.0` constraint.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.10.2...v0.10.3

## v0.10.2 - 2026-08-26

Consumer applications running under a standard production PHP configuration
(`register_argc_argv = Off`, the `php.ini-production` default) hit a 500 on every
web request once `.config/pipeline.php` existed. Upgrade if you have installed
this package into an application that serves web traffic.

### Fixed

- `ConsoleServerProcess::isStarting()` read `$_SERVER['argv']` before checking whether the process was running in console. With `register_argc_argv` off the key is absent, so the warning became an `ErrorException` thrown from service-provider boot. The console check now runs first and the read is null-coalesced, keeping the package inert outside the MCP server process.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.10.1...v0.10.2

## v0.10.1 - 2026-08-25

Three pieces of adoption feedback on 0.10.0. No API changes.

### Fixed

- **A moved receipt no longer reads as a broken gate.** Receipts moved to
  `storage/logs/pipeline/receipts/<name>.json` in 0.10.0 and the old `receipt.json` is deliberately
  not read, so the first `pipeline:verify` after upgrading answered "No pipeline run has been
  recorded. Nothing has been verified." True, and useless — the reader diagnoses a move the command
  already knows about.
  
  ```
  ERROR  No pipeline run has been recorded here. A receipt written before 0.10.0 is still at
  [storage/logs/pipeline/receipt.json], and is deliberately not read: it predates the keys this
  command needs, and unknown is not clean. Open a new run — then that file is safe to delete.
  
  
  
  
  
  ```
  Unchanged when there is no legacy file: a project that never ran an older version still gets the
  short message, because for it nothing has genuinely been verified.
  

### Documentation

- **When `--server-verified` is worth having, and when it is not.** The flag pays off on a walk
  whose mechanical steps sit ahead of the acknowledged one and can pass on their own — that walk
  reaches `complete` routinely, so the flag routinely has something to report.
  
  It gives you nothing on a walk whose mechanical steps are the likely failure. A failing step
  leaves the run `blocked` and an erroring one `halted`, never `complete`, so the second guard
  refuses before any verdict is read. Put a slow suite ahead of a judgement step and the flag is
  silent exactly when an answer was wanted: if the suite failed you never reached the agent step,
  and if it passed you had already paid for it.
  
  That is a pipeline-shape question rather than a defect, and it is the practical reason to name
  pipelines — a quick `spec` walk can be built for the flag without a `closeout` walk having to be.
  Now a section beside the flag, and a row in the invariants record.
  
- **The pipeline name goes on every tool call, not just `open_run`.** The 0.10.0 notes showed it on
  `open_run` and `pipeline:verify`, which reads as the rule rather than an example. Each pipeline
  has its own cursor, so a `next_step` with no name has no run to advance and refuses — correct, and
  a surprise to anyone who named it once. The README now shows all four calls carrying it.
  

### Verified

344 tests, 818 assertions. PHPStan level max, Rector and Pint clean. CI green across all four matrix
legs: PHP 8.4 and 8.5, Laravel 12 and 13, `prefer-lowest` and `prefer-stable`.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.10.0...v0.10.1

## v0.10.0 - 2026-08-24

A project asks its code more than one question. Is this ready for a PR, is it ready to release,
what does an evaluation loop find — different steps, in a different order. Until now one pipeline
had to answer all of them.

### Added

- **`.config/pipeline.php` may return a map of named pipelines.**
  
  ```php
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
  A file that returns a single `Pipeline` keeps working and is named `default`.
  
  Each pipeline is independent: its own phases, its own steps, its own cursor, its own receipt.
  Declaring `phpstan` in two of them is expected rather than a clash — they are separate walks, and
  step ids only have to be unique within one.
  
  ```
  open_run(pipeline: "release")
  php artisan pipeline:verify --pipeline=release
  
  
  
  
  
  
  ```
- **Each pipeline keeps its own cursor**, so an agent can leave the release pipeline part-walked,
  work in the PR pipeline, and come back to where it was. Coming back is not a promise that
  survives an edit: a run measured before a change cannot describe the tree after one, so it is
  discarded and restarted — the rule a single run has always followed, now applied per pipeline.
  
- **Two answers can be true at once.** One receipt per pipeline is what separates a name from a
  tag. A scoped run replaces the previous receipt, so scopes never accumulate; `pr` and `release`
  hold their verdicts side by side.
  

### What keeps it honest

- **The name is required and never guessed**, from the moment the config names its pipelines — a
  map holding a single pipeline included. Returning the most recently opened run instead would
  advance the wrong cursor, run the wrong steps and write a verdict into the wrong receipt, and
  none of it would be visible. Keying the requirement off the pipeline count rather than the
  declaration would have been its own trap: every call site that omitted the name would break the
  day a second pipeline arrives, and the ones still working would be the ones that had been
  guessing.
  
- **A bare `pipeline:verify` refuses once several are declared**, and names them. The rule a scoped
  receipt already follows, one level up. There is deliberately no aggregate "every pipeline is
  green" answer: a project that routinely runs only its PR pipeline could never reach exit 0
  through it, and a gate that cannot pass is one people learn to skip.
  
- **A pipeline name is validated, not sanitised.** It becomes a receipt filename, so a name outside
  `^[a-z0-9][a-z0-9-]*$` is a config error. Rewriting `../escape` into something safe would hide
  the mistake and still not be the pipeline the caller asked for.
  
- **Config validation now builds every pipeline's walk.** A duplicate step id used to surface when
  a run opened; it fails at server start now, for every declared pipeline rather than whichever one
  a session happens to reach — and for single-pipeline projects too.
  

### Breaking

Pre-1.0, so this lands in a minor. See
[UPGRADING.md](https://github.com/SanderMuller/boost-pipeline/blob/main/UPGRADING.md) for the
migration.

- **`PipelineLoader::load()` returns `?Pipelines`**, not `?Pipeline`. Adapt a caller with
  `->sole()`, which returns the only pipeline and throws when several are declared.
  
- **Receipts moved to `storage/logs/pipeline/receipts/<name>.json`.** The old `receipt.json` is not
  read, so the first `pipeline:verify` after upgrading reports no run recorded until the pipeline
  runs once. Unknown is not clean. The old file is left alone and is safe to delete.
  
- **`Pipeline::class` and `ReceiptStore::class` throw when several pipelines are configured.** They
  still resolve for a project declaring one. Resolve `Pipelines`, `StepRunnerFactory` or
  `ReceiptStoreFactory` and ask for one by name.
  
- **Adopting a map turns a bare `pipeline:verify` into an error.** Update anything that gates on
  the bare call — a CI job, a PR gate, a skill — before converting the config.
  

### Unchanged, deliberately

- **A custom `StepRunner` still reaches every step**, in every pipeline. Binding your own over the
  container's is a documented seam and stays one.
- **A custom `ReceiptStore` is honoured while the project declares one pipeline.** Once it declares
  several it is not consulted: one store cannot serve them all without collapsing every receipt
  into one, which is the problem named pipelines exist to solve. Such a project binds
  `ReceiptStoreFactory`.

### Verified

342 tests, 811 assertions. PHPStan level max, Rector and Pint clean. CI green across all four
matrix legs: PHP 8.4 and 8.5, Laravel 12 and 13, `prefer-lowest` and `prefer-stable`.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.9.0...v0.10.0

## v0.9.0 - 2026-08-24

A verdict says a step succeeded. It does not say anything was checked about the code on disk, and
`--server-verified` was reading the first as the second. Four false greens close here, all of them
found by an independent review and each confirmed against a real receipt before it was fixed.

### Fixed

- **A run whose passing steps only rewrote the tree exited 0.** A step declared `->mutating()`
  produced the tree rather than reading it, so its pass describes the code it was handed, never the
  code left behind. A walk holding nothing but a passing formatter reported one verified step, and
  the only thing that ran had checked nothing.
  
  `Run` already knew the difference — it excludes such a step from staleness for the same reason —
  and the receipt threw it away. The receipt now records `asserted`, and the guard that exists to
  reject a vacuous set rejects this one too.
  
- **`--server-verified` answered with no tree to answer about.** Fingerprints were compared only
  when both were present, so a receipt with none and a working tree that cannot be fingerprinted
  exited 0 claiming it had verified "against this tree". Both are required now. The bare call still
  tolerates a missing fingerprint and answers from the receipt alone, which is a recorded decision
  and stays: that call is a gate, and this flag exists so a caller can skip work on the strength of
  the tree still matching.
  
- **A receipt holding no verdicts read as verified.** `all_verified` is a claim the receipt makes
  about itself, and over an empty map it is vacuous — the bare call answered "verified this tree:
  0 step(s)". Both calls refuse it now. The guard sits on the predicate rather than on the JSON, so
  an absent key, an explicit null and an empty map close together, and it answers before the tree
  check so the message names the empty receipt rather than a tree it never described.
  
- **Malformed receipt fields coerced to the permissive value.** A bad `stale` read as not stale, a
  bad `scope` let a partial run answer a whole-tree question, and a bad `tree` removed the
  fingerprint comparison. `Receipt::fromArray()` rejects `tree`, `stale`, `scope`, `coverage`,
  `recorded_at`, `all_verified` and `asserted` when present and wrong-typed, and rejects a
  `verdicts` key that is not a map. Absent and explicitly null still mean "not set".
  

### Added

- **`--server-verified` names the step ids it counted.**
  
  ```
  Run [r-4f2a] passed all 5 step(s) the server verified against this tree: [phpstan], [pint-test],
  [typecheck], [test-js], [lint-all]. 1 step(s) rewrote the tree rather than checking it and are not
  counted. 2 step(s) were only acknowledged and are not counted, so this is not a claim that the
  tree is verified.
  
  
  
  
  
  
  
  ```
  Exit 0 alone never said which checks ran, so a caller skipping work on the strength of it could be
  skipping a check the pipeline does not hold. This does not close that gap — a pipeline declaring
  no static analysis still exits 0 without any — but it makes it visible rather than silent, and it
  is now a row in the limitations table.
  

### Changed

Additive for anyone who only configures steps. See
[UPGRADING.md](https://github.com/SanderMuller/boost-pipeline/blob/main/UPGRADING.md).

- `Receipt` gains an `asserted` constructor parameter, appended last with a default, so a
  positional caller keeps working. It lists the step ids whose pass asserted the state of the tree:
  every passing step not declared `->mutating()`. A receipt written before it existed reads as
  unknown, and `--server-verified` refuses it, because unknown is not clean.

### Verified

275 tests, 647 assertions. PHPStan level max, Rector and Pint clean. CI green across all four matrix
legs: PHP 8.4 and 8.5, Laravel 12 and 13, `prefer-lowest` and `prefer-stable`.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.8.0...v0.9.0

## v0.8.0 - 2026-08-24

`pipeline:verify` had one question, and the pipeline shape this package exists for could never
hear yes to it. This release adds the narrower question, with the guards that answer needs.

### Added

- **`pipeline:verify --server-verified`** asks whether every verdict the server produced is a pass,
  setting aside the steps it could only acknowledge.
  
  ```bash
  php artisan pipeline:verify --server-verified
  
  
  
  
  
  
  
  
  ```
  ```
  Run [r-4f2a] passed all 6 step(s) the server verified against this tree. 2 step(s) were only
  acknowledged and are not counted, so this is not a claim that the tree is verified.
  
  
  
  
  
  
  
  
  ```
  A run that sequences agent work holds acknowledged steps, so `all_verified` stays false however
  green the shell steps are. That answer is correct and never changes, which makes it useless for
  the question a downstream check actually has: were the mechanical steps already run against this
  exact tree, so it can skip them?
  
  The second sentence of the output is the safety margin. A caller reading only the exit code would
  take it for the whole run, so the answer states what it set aside.
  

### What keeps it honest

`all_verified` carries three questions at once. This flag drops exactly one of them, so the other
two become explicit guards. Both were live false greens in the first cut, each confirmed against a
real receipt.

- **The walk covered the config that declared it.** `all_verified` goes false both for an
  acknowledgement and for a declared step dropped before the walk began, and the verdict map cannot
  show the difference, because a dropped step leaves no verdict. A run whose walk had lost a
  declared gate exited 0 without it.
- **The cursor finished.** A receipt is written after every resolution, deliberately, so a walk
  abandoned at step one leaves a readable receipt holding one pass. That run exited 0 too.
- **Something was verified.** "Every server verdict passed" is vacuously true over an empty set, so
  a walk of nothing but acknowledgements would pass having verified nothing at all.

The flag narrows which verdicts count, never which tree the run covered. A stale receipt still fails
on staleness, and a scoped receipt still cannot answer for the whole tree. Combine it with `--only`
to ask both at once.

### Changed

Additive. See
[UPGRADING.md](https://github.com/SanderMuller/boost-pipeline/blob/main/UPGRADING.md).

- **The receipt records `coverage`.** The first guard needs a fact the receipt did not hold: the
  notices that name a dropped gate died with the session. `Receipt` gains a `coverage` constructor
  parameter, appended last with a default, so a positional caller keeps working.
  
- **An older receipt has no `coverage` key and reads as unknown.** The bare call answers from it
  exactly as before. Only `--server-verified` refuses it, because unknown coverage is not clean
  coverage.
  
- **`Receipt::fromArray()` rejects a malformed verdict map** instead of dropping the bad entries.
  Dropping them handed back a receipt holding only what survived, which this predicate would then
  pass. An unreadable receipt now reads as no receipt, which the command already reports as no run
  recorded. A numeric step id is legal and decodes as an int, so it is cast rather than rejected.
  

### Why this name

`serverRun()` answers who produced a verdict, and it is true for `failed` as well. The README calls
conflating those the easiest way to launder a claim. `isVerified()` is the predicate the flag
actually applies, so the flag says that.

### Verified

247 tests, 603 assertions. PHPStan level max, Rector and Pint clean. CI green across all four matrix
legs: PHP 8.4 and 8.5, Laravel 12 and 13, `prefer-lowest` and `prefer-stable`.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.7.0...v0.8.0

## v0.7.0 - 2026-08-24

A run can walk one scope of the pipeline. A change touching one side of a project no longer pays
for, or halts on, the steps that can say nothing about it.

### Breaking

Pre-1.0, so this lands in a minor. See
[UPGRADING.md](https://github.com/SanderMuller/boost-pipeline/blob/main/UPGRADING.md) for the
migration.

- **`Step::tags(): array` joins the contract.** Add it to any implementation, returning
  `list<string>`. `Shell` and `Skill` already have it, so a pipeline that only configures steps
  needs no changes. Empty is the right default: an untagged step runs whatever scope is selected.

### Added

- **Tag a step, then select a scope when the run opens.**
  
  ```php
  $steps->in(Formatting::class)
      ->append(Shell::run('vendor/bin/pint --test')->tagged('backend'))
      ->append(Shell::run('yarn lint-all')->tagged('frontend'));
  
  
  
  
  
  
  
  
  
  ```
  ```
  open_run(only: "backend")
  
  
  
  
  
  
  
  
  
  ```
  A step with no tag runs in every scope, so tagging one step never drops the ones carrying none. A
  step may hold several tags and matches on any of them. Matching is case-sensitive, and an empty
  tag is a config error.
  
  **Tag both sides, not just the odd one out.** To select a scope, some step has to carry it.
  Tagging only the frontend steps gives you `only: "frontend"` but no name for the rest.
  
- **`pipeline:verify --only=`** asks whether a scope was verified rather than the whole tree.
  

### What keeps it honest

A scoped run deliberately omits declared gates, which is what every other rule here exists to
prevent. Three things stop it becoming a false green.

- **The receipt records the scope**, so a scoped pass is not a full one.
- **A bare `pipeline:verify` fails on a scoped receipt** rather than answering a question the run
  cannot. `--only=` compares on coverage rather than equality: a full run answers a question about
  any single scope, a scoped run answers only its own.
- **A selection no step carries raises a blocking notice.** That is almost always a mistyped tag,
  and the untagged steps would otherwise pass and let the run report itself verified.

Scopes do not accumulate. There is one receipt, so a second scoped run replaces the first, and
verifying two scopes separately never adds up to a verified tree. That is now a row in the
limitations table rather than a footnote.

### Fixed

- **Filtering reaches inside a parallel group.** Survivors stay a group, an emptied group
  contributes no position, and a lone survivor drops its `batchId` so nothing downstream describes a
  step that ran by itself as sharing a measurement with siblings that were never in the walk.
  
- **`status` reports `excluded_by_scope`** and the payload reports `scope`, so a reader can see the
  walk is smaller than the config without diffing the two. The output schema declares both, and the
  `run_pipeline` prompt says when to reach for `only`.
  

### Documentation

- The README leads with what the package does for you rather than four paragraphs on how prose
  instructions fail, and the fluent config builder follows immediately.
- Two traps are recorded, both rooted in one behaviour: `git status --porcelain` reports a
  wholly-untracked directory as the directory, so a tool asking git what changed never sees inside
  it. That is why `pint --dirty` can report clean over a config file, and why a debris guard reading
  `git status` passes once the work is committed, having checked nothing.
- Who owns `storage/logs/pipeline/` is stated: nothing prunes it, retention is the project's
  business, and deleting is safe.
- A group refuses skill steps because the server cannot fan them out to separate agent contexts, but
  a skill can fan out internally. One `Skill::run()` invoking a skill that dispatches its own
  subagents is a single handover, so many lenses inside one skill cost no more than one.

### Verified

226 tests, 564 assertions. PHPStan level max, Rector and Pint clean, `composer validate --strict`
valid. CI green across all four matrix legs on the last commit carrying code: PHP 8.4 and 8.5,
Laravel 12 and 13, `prefer-lowest` and `prefer-stable`. The two commits after it change `README.md`
alone, which the workflow path filters correctly skip.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.6.1...v0.7.0

## v0.6.1 - 2026-08-24

Two things consumers found by running v0.6.0's parallel groups. One is a number that stopped meaning
what it looked like; the other is a message claiming more than the mechanism knows.

### Fixed

- **A parallel group reports the range of steps it covers.** `position` counts steps, so a group
  handed over several while showing a single number: a seven-step walk holding two groups reported
  `2/7` and took five calls. The number reads as a count of handovers remaining and is not one. It is
  now `2-3/7` for a group, which says what the handover contains rather than leaving it to be
  inferred, and the output schema says the same.
  
- **A stale verdict inside a group no longer reads as naming the writer.** Every step in a group
  measures the same tree from before the group ran, so when the tree moves the report names whichever
  of them passed first. That is not the step identified as writing, and with a group there is no
  ordering that could identify one. The message says so:
  
  > That step ran in a parallel group, so it is the first of the group that passed rather than the
  one identified as writing: the group shares a single measurement and cannot tell its members
  apart.
  
  Nothing changes about the outcome. The run is not verified and `pipeline:verify` exits 1. This is
  the undeclared case only, since a step declaring `->mutating()` cannot join a group at all.
  

### Documentation

- The README states the attribution limit next to the staleness rules, so the named step is not read
  as proof. The note about `position` counting steps moved out of the tool table, where it had been a
  row without a tool in it.

### Verified

193 tests, 486 assertions. PHPStan level max, Rector and Pint clean, `composer validate --strict`
valid. CI green across all four matrix legs on the last commit carrying code; the commit this is
pinned to changes only `README.md`, which the workflow path filters correctly skip.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.6.0...v0.6.1

## v0.6.0 - 2026-08-23

Independent steps can share a position and run at the same time. Concurrency costs the agent
nothing here, which is why it is allowed at all: the agent does not perform a shell step, it calls
`next_step` and waits.

### Breaking

Pre-1.0, so this lands in a minor. See
[UPGRADING.md](https://github.com/SanderMuller/boost-pipeline/blob/main/UPGRADING.md) for the
migration.

- **`Run::resolveCurrentStep()` is now `Run::resolveCurrent()` and returns `list<Result>`.** A
  position can hold several steps, so it resolves to several verdicts, and "current step" stopped
  describing what the method does. Only affects code driving a `Run` directly; the MCP tools are
  unchanged from the outside.

### Added

- **`parallel()` declares steps that run at the same time.**
  
  ```php
  $steps->in(StaticAnalysis::class)->parallel(function (StepCollection $steps): void {
      $steps->append(Shell::run('composer phpstan'));
      $steps->append(Shell::run('node_modules/.bin/tsc --noEmit'));
  });
  
  
  
  
  
  
  
  
  
  
  
  ```
  One `next_step` call runs both and returns both verdicts. Three commands running at once is still
  one thing in front of the agent, so the one-step-at-a-time guarantee is untouched.
  
  Wall clock is the obvious gain. The better one is that a group reports **every** failure in one
  pass, where a sequence blocks at the first and hides the rest behind a fix and a re-run.
  
  A group holds the position if any step in it does not pass, and holds at the group's first step so
  the next call re-runs the whole group. Re-running a passing sibling costs a little time and keeps
  the rule simple: a position either resolved or it did not. An error outranks a failure in deciding
  the state, because a tool that never ran is the more urgent report.
  
  Two things a group refuses, when the config loads rather than when the run reaches it:
  
  - **A skill step.** Several lenses handed over at once is the wall of context the cursor exists to
    break up, and the server cannot fan them out to separate agent contexts to avoid that.
  - **A step declaring `->mutating()`.** Its siblings would run against a tree it is rewriting, with
    no ordering between them to attribute the change to, so every sibling verdict would describe
    code that no longer exists.
  
- **`BatchStepRunner` extends `StepRunner` with `runBatch()`.** `ProcessStepRunner` implements it,
  starting every process before waiting on any. A custom runner that does not implement it keeps
  working: its groups resolve one step after another, which is correct and slower.
  

### Fixed

- **The output schema declares the keys the payload actually sends.** The schema is what a client
  reads to know what it can receive, so a key sent but not declared is the same drift as
  documentation disagreeing with behaviour. `instruction` had shipped undeclared since 0.5.0 — the
  field a skill step exists to carry. The parallel group added `steps`, `parallel` and `results`.
  A test now asserts every key is declared, and renders the nested entries rather than checking only
  the top level.

### Internal

- Scope commands stay sequential inside a group. They are capped at a minute and usually take
  milliseconds, so keeping them out of the concurrent path keeps the part that can go wrong small.
  
- `phpVersion` is pinned to the range `composer.json` declares. PHPStan otherwise analyses at
  whatever PHP is running it, so a developer on 8.5 and a CI job on 8.4 perform two different
  analyses and each misses what the other catches. The test matrix covers both versions; the
  analyser now does too.
  

### Verified

191 tests, 480 assertions. PHPStan level max, Rector and Pint clean, `composer validate --strict`
valid. CI green on this commit across all four matrix legs: PHP 8.4 and 8.5, Laravel 12 and 13,
`prefer-lowest` and `prefer-stable`.

The concurrency claim is checked by wall clock: three one-second steps in a group complete in under
two and a half seconds, where running them in sequence takes over three.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.5.0...v0.6.0

## v0.5.0 - 2026-08-23

A skill step can finally say what it is for. The field that carries that was reachable from config
and read by nothing, so the package delivered steps one at a time without ever narrowing them.

### Breaking

Pre-1.0, so this lands in a minor. See
[UPGRADING.md](https://github.com/SanderMuller/boost-pipeline/blob/main/UPGRADING.md) for the
migration.

- **`Skill::run()`'s third parameter is renamed from `description` to `instruction`.** Only code
  that passed it by name is affected, and nothing can have depended on its effect, because it had
  none.

### Added

- **A skill step carries its own instruction, and the agent receives it.** `Skill::run()` has always
  taken a third argument for what the step is for, and `Step::description()` has always been on the
  contract. The only reader was `WalkStep::toArray()`, which has no callers — so the string never
  reached the agent.
  
  That is the difference between sequencing and focus. A step handed over as a bare `/code-review`
  makes the agent run a broad skill, which arrives with its own list of concerns, so the wall of
  context the cursor exists to break up reappears inside the step.
  
  ```php
  $steps->in(Agent::class)
      ->append(Skill::run('/code-review', id: 'errors',
          instruction: 'Review the error handling in files changed since main. Ignore style and tests.'))
      ->append(Skill::run('/code-review', id: 'tests',
          instruction: 'Judge whether the tests would catch a regression in this change.'));
  
  
  
  
  
  
  
  
  
  
  
  
  ```
  Give each step an explicit `id` when several invoke the same skill: an id is derived from the
  invocation, so two `/code-review` steps both derive `code-review`, and a duplicate id throws when
  the run opens.
  
- **`Pipeline::withPhases()` and `Pipeline::phases()` are back**, with `Phases::append()`,
  `prepend()`, `remove()`, `moveAfter()` and the `PhasePosition` class. The five defaults suit a
  pipeline of mechanical checks. A pipeline that sequences review work does not fit them, because its
  steps are not refactoring or formatting or tests, and six review lenses all reporting phase `Agent`
  tell a reader nothing.
  
  They were removed because no consumer called them. That was true and it was the wrong measure:
  configuring phases only matters to the pipelines the defaults do not describe, so counting callers
  measures which pipelines exist rather than whether the seam earns its place.
  
  Review lenses are deliberately **not** shipped as default phases. What a good review decomposes
  into belongs to the skills, not to this package.
  

### Fixed

- **The note on a skill step states the guarantee that exists.** It used to say only that the step is
  "recorded as acknowledged, not verified". True, and the wrong thing to repeat on every step of a
  pipeline whose steps are meant to be judgement work: it framed the normal outcome as a shortfall.
  It now leads with what the server does promise — this step arrived on its own, in order, and
  nothing follows until it resolves.
  
- **`pipeline:verify` no longer advises removing skill steps from the pipeline.** For a verification
  gate that was sound. For a pipeline that sequences review work it says: stop using the feature.
  The message now says an acknowledged step is expected, that the walk still guarantees order and
  one-at-a-time delivery, and points at `status`. The exit contract is untouched: exit 0 still
  requires every step verified.
  
- **`pipeline:verify` stops claiming a retryable run finished.** A blocked or halted run has not
  finished — `next_step` hands the same step back — so "finished in state [halted]" contradicted the
  server it reports on. It now names the state and the steps that were not verified. The
  acknowledged-step message also had its parenthetical between the verb and its complement.
  

### Documentation

- **The README describes current behaviour rather than its own release history.** It had accumulated
  what the receipt did for earlier releases, a config that reached production with a banned call, a
  driver that hung on a withdrawn server, a suite that measured 336s. Two of those were also wrong:
  "the set is fixed" described the phases one commit before they became configurable again, and the
  worked example quoted a skill-step note that no longer exists.
  
- **The framing leads with sequencing.** The tagline said "a verification pipeline", which is the
  smaller half. Handing over one step at a time is the point, and the instruction on a skill step now
  has prose of its own instead of appearing only inside two code samples.
  

### Verified

172 tests, 423 assertions. PHPStan level max, Rector and Pint clean, `composer validate --strict`
valid. CI green on this commit across all four matrix legs: PHP 8.4 and 8.5, Laravel 12 and 13,
`prefer-lowest` and `prefer-stable`.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.4.2...v0.5.0

## v0.4.2 - 2026-08-23

`pipeline:verify` told a consumer nothing useful when their pipeline could never satisfy it. The
exit contract is unchanged; what it says when it fails is not.

### Fixed

- **`pipeline:verify` tells a run that cannot ever verify apart from one that failed.** Both got
  `finished in state [x] without verifying every step`. A failed step is fixable — fix it, run again,
  exit 0. An acknowledged step is not: the server never verified it and never will, so that pipeline
  cannot exit 0 however often it runs.
  
  A consumer wired the command into a closeout gate for a pipeline holding three review skills, and
  it could never pass. The generic wording reads as a shortfall to fix, so the gate looked broken
  rather than structurally unsatisfiable — and a gate that always fails teaches a reader to skip it,
  which is worse than not having one.
  
  The receipt already carries every verdict, so the command now names the acknowledged steps, says
  re-running will not help, and gives the two real options: declare a proof, or run those steps
  outside the pipeline. A failure alongside an acknowledgement still reports as a failure, because
  that one is fixable.
  
  **Exit 0 still requires `all_verified`.** A third exit code or a "close enough" mode would make
  exit 0 mean two things, which is the laundering this package exists to prevent. The contract was
  right; the diagnosis was useless.
  

### Documentation

- **`pipeline:verify` states its precondition.** It is a gate for a pipeline the server can verify
  end to end, and a pipeline of shell steps is the shape it was built for. Two documented positions
  were in tension: a review-skill configuration was described as correct for never reaching
  `all_verified`, while the gate treated that same configuration as failure. Both are defensible
  alone, and together they told a reader that a correct pipeline is a failing one.
  
- **What an all-check-mode pipeline buys.** The stale report offers two causes — something edited
  files, or a fix-mode step is missing `->mutating()`. Where no step declares it, the second is
  impossible by construction, so a stale report during a run can only mean something outside the run
  wrote to the tree. That is a reason to prefer check mode beyond the obvious one, and a reason to
  keep a fix-mode step out of a pipeline you intend to gate on.
  

### Internal

- **The join between a run and the command that reads it is tested.** Every case in the verify
  command's suite handed it a receipt built by hand, so it asserted what a correct receipt does and
  never what `Run` produces — the exact seam 0.4.0 shipped broken through, with both halves testing
  clean. Restoring the old ordering now fails that one test while its six siblings still pass, which
  is the measure of what they could see.
  
- **The halted path's receipt is pinned.** State settling returns early for both blocked and halted
  and only blocked was covered. The 0.4.0 fault was a persisted state being wrong on an untested
  path, so its sibling is no longer one.
  

### Verified

163 tests, 399 assertions. PHPStan level max, Rector and Pint clean. CI green on this commit across
all four matrix legs — PHP 8.4 and 8.5, Laravel 12 and 13, `prefer-lowest` and `prefer-stable`.

Both fixes came from production dogfooding of 0.4.1.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.4.1...v0.4.2

## v0.4.1 - 2026-08-23

`pipeline:verify` could never exit 0. The command shipped in 0.4.0 as the way something outside the
session could read a run, and an ordering bug made it unusable. Nothing unsafe shipped — it failed
closed — but the gate itself did not work.

### Fixed

- **A run's receipt is written after the state transition, not before it.** `Run::record()`
  persisted the receipt and only then assigned the run's state, while `all_verified` ends on
  `state === Complete`. So a fully green run wrote itself to disk as
  `{"state":"running","all_verified":false}`, and `pipeline:verify` reported a run that "finished in
  state [running]" — a state a finished run cannot be in. Every green run was persisted as
  unverified, so the command always exited 1.
  
  The state is now settled first and the receipt written once, at the end, on both paths — including
  the early return for a `blocked` or `halted` verdict.
  
  The new tests assert the file on disk rather than the in-session payload. Every existing test read
  `status`, which agreed with itself and hid this completely; all three of the new ones fail on the
  old ordering.
  
- **A failing proof says which proof failed.** A proof returned exactly what the runner produced, so
  a silent command such as `grep -q` or `test -f` reported `Failed with no output.` — naming neither
  the command nor the fact that a proof was checked. The agent is handed that same step again and has
  to act on the message, so it now carries the step id and the command. The verdict, exit code and
  log path are unchanged.
  

### Documentation

- **`pipeline:verify` is a local gate, and the README no longer suggests otherwise.** The example was
  a GitHub Actions step. The receipt lives under `storage/logs/`, which every Laravel application
  gitignores, so it never reaches CI: a job following that example finds no receipt and fails every
  build. The example is now a pre-push hook or a pre-PR gate, with a note that CI's job is to run
  the checks rather than ask whether someone else did.
  
- **`proving()` gained the caveat it needed.** A proof over an artifact the step creates only to
  satisfy the proof turns `acknowledged` into `passed` while checking nothing — the same laundering
  as reading `server_run: true` as "it passed". Review skills are the common case: steps that leave
  the tree untouched have nothing to prove, so that configuration never reaches `all_verified`, and
  that is the correct outcome rather than a gap to close.
  
- **Three places still said `proving()` did not exist.** The limitations table read "Verify an agent
  step — reported as `acknowledged`, never `passed`", which would tell a reader `all_verified` is
  unreachable for any configuration: the opposite of what 0.4.0 added. The verdicts table credited
  `passed` to shell steps only, and the internal invariants doc used the release's own proof example
  as its example of the unverifiable. All three now describe the boundary: a proof makes a step
  `passed` where there is an artifact to check, and judgement that touches nothing keeps
  `acknowledged`.
  

### Verified

159 tests, 389 assertions. PHPStan level max, Rector and Pint clean. CI green on this commit across
all four matrix legs — PHP 8.4 and 8.5, Laravel 12 and 13, `prefer-lowest` and `prefer-stable`.

Both fixes and both documentation corrections came from production dogfooding of 0.4.0, with the
blocker traced to the line by the reporter.

**Full Changelog**: https://github.com/SanderMuller/boost-pipeline/compare/v0.4.0...v0.4.1

## v0.4.0 - 2026-08-23

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
