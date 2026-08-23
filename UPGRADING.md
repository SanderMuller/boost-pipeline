# Upgrading

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
