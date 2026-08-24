# Upgrading

## From 0.9 to 0.10

A project that declares one pipeline needs no config change. Two behaviour changes reach it anyway,
and one API change reaches anything that resolves the loader.

- **`.config/pipeline.php` may now return `array<string, Pipeline>`.** Returning a single
  `Pipeline` still works and that pipeline is named `default`.

  ```php
  // before, and still valid
  return Pipeline::configure()->withSteps(...);

  // after
  return [
      'pr' => Pipeline::configure()->withSteps(...),
      'release' => Pipeline::configure()->withSteps(...),
  ];
  ```

- **`PipelineLoader::load()` returns `?Pipelines`, not `?Pipeline`.** Adapt a caller with
  `->sole()`, which returns the only pipeline and throws when the project declares several.

  ```php
  // before
  $pipeline = $loader->load();

  // after
  $pipeline = $loader->load()?->sole();
  ```

- **`Pipeline::class`, `StepRunner::class` and `ReceiptStore::class` still resolve** for a project
  declaring one pipeline. They throw when it declares several, because "the pipeline" has no answer
  there. Resolve `Pipelines`, `StepRunnerFactory` or `ReceiptStoreFactory` and ask for one by name.

- **Receipts moved to `storage/logs/pipeline/receipts/<name>.json`.** The old
  `storage/logs/pipeline/receipt.json` is not read. The first `pipeline:verify` after upgrading
  reports no run recorded until the pipeline runs once — unknown is not clean. The old file is
  unread and safe to delete.

- **A custom `ReceiptStore` binding is honoured only while the project declares one pipeline.**
  Nothing is ambiguous there, so an override that worked before names existed keeps working. Once
  the config declares several, one store cannot serve them all without collapsing every receipt
  into one — which is the problem named pipelines exist to solve — so the binding is not consulted.
  Bind `ReceiptStoreFactory` instead. A custom `StepRunner` is unaffected either way: it is a
  documented seam and reaches every pipeline.

- **Adopting a map turns a bare `pipeline:verify` into an error.** With several pipelines
  configured, "is this tree verified" has no single answer, so the command names them and asks for
  `--pipeline=`. **Update anything that gates on the bare call before converting the config** — a CI
  job, a PR gate, or a skill that runs `php artisan pipeline:verify`.

- **A duplicate step id now fails at server start rather than at `open_run`.** Config validation
  builds every pipeline's walk, so a project with a duplicate id sees the error sooner, and sees it
  for every pipeline rather than only the one a session happens to open.

## From 0.8 to 0.9

Additive for anyone who only configures steps. Two behaviour changes reach a consumer that reads a
receipt or gates on `--server-verified`.

- `Receipt` gains an `asserted` constructor parameter, appended last with a default, so a positional
  caller keeps working. It lists the step ids whose pass asserted the state of the tree — every
  passing step that is not declared `->mutating()`. A verdict says a step succeeded; this says the
  step checked something rather than producing it.

- `pipeline:verify --server-verified` now refuses three cases it used to pass. A run whose passing
  steps all rewrite the tree, because a formatter reports that it ran and never that the result is
  correct. A run with no tree fingerprint on either side, because the flag exists so a caller can
  skip work on the strength of the tree still matching. And a receipt written before `asserted`
  existed, because unknown is not clean.

  The bare `pipeline:verify` is unchanged in all three cases. `all_verified` asks whether every step
  passed, and in each of them every step did.

- `--server-verified` now names the step ids it counted, so a caller can see which checks the
  pipeline actually holds before deciding what to skip. Exit 0 still reports only on the steps that
  ran: a pipeline declaring no static analysis exits 0 without any.

- `pipeline:verify` — the bare call as well as `--server-verified` — refuses a receipt that holds
  no step verdicts at all. It used to answer "verified this tree: 0 step(s)". No run can write
  such a receipt: one is only written from a resolution, and a resolution always records a result.

- `Receipt::fromArray()` rejects a receipt whose `tree`, `stale`, `scope`, `coverage`,
  `recorded_at`, `all_verified` or `asserted` is present but holds the wrong type. These used to
  coerce to null, which was the permissive reading every time: a malformed `stale` read as not
  stale, a malformed `scope` let a partial run answer a whole-tree question, and a malformed `tree`
  removed the fingerprint comparison. An absent or explicitly null field still means "not set".

## From 0.7 to 0.8

Additive. No migration needed.

- `Receipt` gains a `coverage` constructor parameter, appended last with a default, so a positional
  caller keeps working. It records whether the walk covered the config that declared it, which
  `all_verified` alone could not express: that field goes false both for a step the server could
  only acknowledge and for a declared step dropped before the walk began.

- A receipt written by an earlier release has no `coverage` key and reads as unknown. The bare
  `pipeline:verify` answers from it exactly as before; only the new `--server-verified` flag refuses
  it, because unknown coverage is not clean coverage.

- `Receipt::fromArray()` now rejects a malformed verdict map instead of dropping the bad entries.
  An unreadable receipt reads as no receipt, which the command already reports as no run recorded.

## From 0.6 to 0.7

### Changed

- `Step::tags(): array` joins the contract. Add it to any implementation, returning `list<string>`.
  `Shell` and `Skill` already have it, so a pipeline that only configures steps needs no changes.

  ```php
  // after
  /** @return list<string> */
  public function tags(): array
  {
      return [];   // empty means the step runs in every scope
  }
  ```

  Empty is the right default: an untagged step runs whatever scope is selected, so returning `[]`
  keeps a custom step behaving exactly as it does today.

### Added (no migration needed)

- `Shell::tagged()` and `Skill::tagged()` declare which scopes a step belongs to, and `open_run`
  takes an `only` argument to select one. See the README section on running only part of the
  pipeline.

- `pipeline:verify --only=` asks whether a scope was verified rather than the whole tree. A bare
  call still asks about the tree, and now fails when the recorded run was scoped, because a partial
  run cannot answer it.

- Receipts record the scope. One written before this release has no `scope` key and reads as
  unscoped, which is what it was.

## From 0.5 to 0.6

### Changed

- `Run::resolveCurrentStep()` is now `Run::resolveCurrent()` and returns `list<Result>` rather than
  `?Result`. Only affects code driving a `Run` directly; the MCP tools are unchanged from the
  outside.

  ```php
  // before
  $result = $run->resolveCurrentStep();
  if ($result instanceof Result) { /* ... */ }

  // after
  $results = $run->resolveCurrent();
  if ($results !== []) { /* ... */ }
  ```

  A position in the walk can now hold several steps, so it resolves to several verdicts. The name
  changed with it: "current step" was no longer what the method resolves.

- `StepCollection::all()` still returns `list<Step>` with parallel groups flattened. Use the new
  `entries()` where the grouping matters.

### Added (no migration needed)

- `StepCollection::parallel()` declares steps that share one position and run at the same time. See
  the README section on steps that run at the same time.

- `BatchStepRunner` extends `StepRunner` with `runBatch()`. A custom runner that does not implement
  it keeps working: its groups resolve one step after another.

## From 0.4 to 0.5

### Changed

- `Skill::run()`'s third parameter is renamed from `description` to `instruction`. Only affects code
  that passed it by name.

  ```php
  // before
  Skill::run('/code-review', description: 'Review the error handling.');

  // after
  Skill::run('/code-review', instruction: 'Review the error handling.');
  ```

  Worth knowing why, because the behaviour changed too: nothing ever sent that string to the agent.
  `description()` was read only by `WalkStep::toArray()`, which had no callers, so the argument was
  write-only. It now reaches the agent in the step payload as `instruction`, which is what it was
  always for — a step that says "review only the error handling in files changed since main" narrows
  attention the way a bare `/code-review` cannot.

  `Step::description()` is unchanged on the contract, and `Shell` still uses it as a description.

- The `note` on a skill step's payload is reworded. It used to say only that the step is "recorded as
  acknowledged, not verified"; it now leads with the guarantee that exists — the step arrived on its
  own, in order, and nothing follows until it resolves. If you assert on that string, update the
  expectation.

### Added (no migration needed)

`Pipeline::withPhases()` and `Pipeline::phases()` are back, with `Phases::append()`, `prepend()`,
`remove()`, `moveAfter()` and the `PhasePosition` class. If you migrated away from them for 0.4,
nothing is broken — the `withSteps()`-only form still works. If you were waiting for them, the
"From 0.3 to 0.4" note below no longer applies to this part.

They were removed in 0.4.0 because no consumer called them. That was true, and it was the wrong
conclusion: every consumer at the time ran a pipeline of shell checks, where the five shipped phase
names already fit. A pipeline that sequences review and evaluation work has no such luck, and
grouping every review step under one phase called `Agent` tells a reader nothing.

## From 0.3 to 0.4

This release removes configuration surface that nothing used. If your
`.config/pipeline.php` only calls `withSteps()` — and both known consumers only do — you need no
changes.

### Removed

- `Step::before()` and `Step::after()` are gone from the contract. Delete them from any
  implementation of `Step`.

  ```php
  // before
  final class MyStep implements Step
  {
      public function before(): void { /* ... */ }

      public function after(Result $result): void { /* ... */ }
  }

  // after — both methods deleted
  final class MyStep implements Step {}
  ```

  Leaving them in place is legal PHP and will not break, which is the reason to say so here: the
  package stops calling them, so setup or teardown written inside one silently stops running. Move
  that work into the step's own resolution, or into a shell step of its own.

- `Steps::between()` is gone. Attach the step inside a phase instead.

  ```php
  // before
  $steps->between(Formatting::class, StaticAnalysis::class,
      Shell::run('git diff --quiet -- composer.lock'));

  // after
  $steps->in(StaticAnalysis::class)
      ->prepend(Shell::run('git diff --quiet -- composer.lock'));
  ```

  `prepend()` on the later phase puts the step in the same place in the walk. Only the reported
  phase label changes: the step now belongs to `StaticAnalysis` rather than to the join.

- `Pipeline::withPhases()` and `Pipeline::phases()` are gone, with `Phases::append()`,
  `prepend()`, `remove()`, `moveAfter()`, and the `PhasePosition` class. The five phases in
  `Phases::DEFAULTS` are the whole set.

  ```php
  // before
  Pipeline::configure()
      ->withPhases(fn (Phases $phases) => $phases->append(ImpactAnalysis::class)->after(StaticAnalysis::class))
      ->withSteps(fn (Steps $steps) => $steps->in(ImpactAnalysis::class)->append(Shell::run('...')));

  // after — put the step in the phase that runs at the right point
  Pipeline::configure()
      ->withSteps(fn (Steps $steps) => $steps->in(StaticAnalysis::class)->append(Shell::run('...')));
  ```

  A phase is only a named, ordered group, so the step runs at the same point either way. What you
  lose is the grouping label, and a phase you removed no longer stays removed.

  A step declared into a phase outside that set is still dropped rather than run, still appears in
  `open_run`'s `notices`, and still forces `all_verified: false`.

## From 0.2 to 0.3

### Changed

- `Step` gained `mutates(): bool`. Add it to any implementation of the interface.
  `Shell` and `Skill` already have it, so a pipeline that only configures steps needs no
  changes.

  ```php
  // after
  public function mutates(): bool
  {
      return false;   // true if the step rewrites code
  }
  ```

- `OpenRun`'s constructor takes a second argument, `CommandPreflight`. Only relevant if you
  construct the tool yourself; the container resolves it for you otherwise, which is how
  `laravel/mcp` gets it.

- A step that rewrites code must declare it, or the run reports itself stale.

  ```php
  // before — a fix-mode step, silently trusted
  $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint'));

  // after
  $steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint')->mutating());
  ```

  Check-mode steps (`pint --test`, `rector process --dry-run`, `phpstan`, a test runner)
  need nothing — they do not change the tree, which is the whole reason a gate uses check
  mode. Only declare `->mutating()` where the step genuinely rewrites files, including a
  fixing skill step such as `Skill::run('/evaluate')->mutating()`.

  Declaring it means the step's own writes stop counting against the run. It does not make
  a verdict earned *before* that step true again — those ran against different code — so
  keep fixing steps ahead of the checks that must see their result, which is what the
  default phase order already does.

## From 0.1 to 0.2

Only affects code that implements `StepRunner` or constructs `ProcessStepRunner` itself. A pipeline
that configures steps in `.config/pipeline.php` and nothing else needs no changes.

### Changed

- `StepRunner::run()` takes the run id as a second argument. Add the parameter to your
  implementation.

  ```php
  // before
  final class MyRunner implements StepRunner
  {
      public function run(Step $step): Result
      {
          return Result::passed($step->id(), 'ok');
      }
  }

  // after
  final class MyRunner implements StepRunner
  {
      public function run(Step $step, string $runId): Result
      {
          return Result::passed($step->id(), 'ok');
      }
  }
  ```

  Name anything you write after `$runId`. It is the id every MCP response carries, so an artifact
  named after it can be found from a payload — that correlation is why the id is passed in rather
  than held by the runner.

- `ProcessStepRunner` no longer takes a `runId`. Remove the argument.

  ```php
  // before
  new ProcessStepRunner(
      workingDirectory: base_path(),
      logs: new LogWriter(storage_path('logs/pipeline')),
      summariser: new OutputSummariser,
      environment: new EnvironmentScrubber(base_path()),
      runId: 'r-'.bin2hex(random_bytes(3)),
  );

  // after
  new ProcessStepRunner(
      workingDirectory: base_path(),
      logs: new LogWriter(storage_path('logs/pipeline')),
      summariser: new OutputSummariser,
      environment: new EnvironmentScrubber(base_path()),
  );
  ```

### Fixed (no migration needed)

- A run's log files are named after the run id the server reports, so the id in a response locates
  that run's logs. They previously carried an id that no response mentioned.

- A run id and step id are reduced to filename-safe text before they reach the log path. If you
  pass an explicit `Shell::run(id: ...)` containing characters outside `A-Za-z0-9._-`, that step's
  log filename changes and gains a short hash. The step id itself is untouched, so `status` and
  every response still report exactly what you configured.
