# Upgrading

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
