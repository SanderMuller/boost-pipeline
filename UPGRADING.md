# Upgrading

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
