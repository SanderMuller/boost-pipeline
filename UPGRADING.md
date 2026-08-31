# Upgrading

## Unreleased

- **A scoped run is no longer held back by a step dropped in a scope it never claimed.**
  `all_verified` and `coverage` used to read the walk's notices, which are not filtered by the
  selection. Declaring a step into an unregistered phase anywhere in your config therefore made
  every scoped run unverifiable, including runs that had nothing to do with that step.

  This is a loosening, and the first in this line. A scoped run answers for its own scope, so that
  is what it now reports on. `pipeline:verify` was already scope-accurate; the run's own verdict was
  not, and the two disagreed.

  **What has NOT changed, deliberately.** A tag no step carries still makes a run unverifiable. It
  drops nothing at all, since the walk becomes every untagged step and those pass, so a mistyped tag
  would otherwise leave a run reporting itself verified while the scope you asked about was never
  checked. That guard is measured separately from dropped steps precisely so that this change could
  not delete it.

  A drop inside the scope you asked about still makes the run unverifiable, and still refuses the
  gate, naming the step and its phase.

- **`Run::start()` refuses a scope that disagrees with the walk it was given.** A run records the
  scope its verdicts are about, and those verdicts are measured from the walk's own selection, so
  the two cannot differ. A walk resolved for `backend` with a receipt claiming `frontend` would put
  a true answer about one scope on a receipt describing another.

  It threw nothing before because the old scope-blind verdict masked it: any dropped step anywhere
  made such a run unverifiable, so the mismatch never surfaced. Measuring accurately removed that
  accident, so the rule is now stated. `RunManager` always passed matching values, so this affects
  only code that builds a run by hand, which is not a documented seam.

- **The `notices` a run reports stay unfiltered, and are informational rather than load-bearing
  now.** They name every step the config declared into an unregistered phase, because that is the
  config got wrong regardless of scope. A scoped run can therefore report a notice about another
  scope while reporting `all_verified: true`. That reads oddly the first time and is the accurate
  answer: your config has a problem elsewhere, and this scope is verified.

## From 0.14 to 0.15

- **The config digest is tagged with a format version.** A run records `v1:<digest>` where it
  recorded a bare digest before. Receipts and live records written by 0.14.0 keep working: an
  untagged value is read as this format's content, because 0.14.0 wrote it with the algorithm still
  in use.

  This is about what happens the next time the digest algorithm changes. Two of its inputs have
  already had to be corrected once (env values excluded, float precision normalised), and each
  changed what the digest produces. Without a tag, a digest from a newer algorithm is
  indistinguishable from a digest of a different declaration, so `pipeline:verify` would report the
  second: every consumer's gate failing at once, with a message blaming a stale server that was
  never stale.

  With a tag, a digest this version cannot reproduce is treated as **unknown** instead. The bare
  call ignores it and `--server-verified` refuses it, which is exactly where an absent digest
  already sits. Nothing about your setup changes today; this is what stops a future release from
  breaking your gate.

  A malformed value is also unknown rather than a mismatch. A value this build could not have
  written says nothing about your declaration however it is wrong.

- **`verify.config_fingerprint: false` now also stops `--server-verified` refusing a receipt that
  cannot answer the declaration question.** In 0.14.0 that refusal ignored the toggle. The toggle
  governs the whole question rather than only the comparison: a project that switched it off is not
  asking, so refusing because a receipt has no digest, or one this version cannot read, would
  reintroduce the check by another door. Only affects projects that have turned it off.

- **A scoped `pipeline:verify` now refuses a config that declares a step no phase registers.**
Only a
  whole-tree call did before. A step declared into an unregistered phase never reaches the cursor,
  so
  it cannot fail and cannot be skipped. It simply never runs, and counting recorded step ids finds
  nothing wrong.

  A scoped call was exempt because the walk described the drop in prose, and a sentence cannot say
  which scope the dropped step belonged to. Applying it anyway would have failed a backend answer
  over
  a frontend step. The walk now reports its drops as data, filtered while it resolves by the same
  rule
  it selects steps with, so a scoped call refuses a drop inside its own scope and still ignores one
  outside it.

  **A scoped gate that passed before can now exit 1.** Register the phase, or move the step to one
  that is registered. An untagged dropped step belongs to every scope, so it fails every scoped
  call —
  matching how an untagged step is part of every walk.

  The refusal also names the step ids and their phase now, rather than quoting the notice whole.

## From 0.13 to 0.14

- **`pipeline:verify` now refuses a run that walked a pipeline declaration your config no longer
  produces.** A run records a digest of the whole declaration it walked, and the command compares it
  against the digest your config produces now.

  This closes a false green nothing else could reach. The MCP server resolves the config once when
  its process starts, so a step you redefine after that keeps running its old definition — an old
  command under the same step id, recorded as a pass. The verdicts are keyed by id, so the
  declared-step check added in 0.12.0 sees nothing missing, and the tree fingerprint matches because
  the run ran against the tree that already held your change.

  **A gate that passed before can now exit 1.** Reconnect the MCP client and open a new run. Two
  other causes produce the same mismatch and the message names all three: a config git cannot see
  (ignored, symlinked, or composed from outside the repository), and a config that computes part of
  its declaration when it loads.

  The digest covers everything that defines a step — command, scope command, env keys, timeouts, the
  mutating flag, tags, kind, a skill's invocation, proof and instruction, step order, phase
  registration, batch grouping and the pipeline timeout. It does NOT cover env VALUES: `withEnv()`
  resolves its array when the config loads, so hashing a value would make two shells disagree about
  an identical config. A custom `Step` implementation is compared on the contract alone.

- **A receipt written before this version is treated as unknown, not as wrong.** The bare
  `pipeline:verify` ignores an absent digest, so your existing gate keeps working on upgrade day.
  `pipeline:verify --server-verified` refuses it — that flag exists so a caller can SKIP work, and
  skipping on the strength of a run that cannot say what it walked is the trade it refuses
  everywhere
  else. This is where `coverage` already refuses unknown. The next run writes a digest and the
  refusal goes away.

- **A new `verify.config_fingerprint` config key**, defaulting to true. Set it to false if your
  config computes part of its declaration when it loads — otherwise no run will ever match and the
  gate cannot pass. Only an explicit `false` disables it, so a typo cannot switch it off silently.
  Publish the config with `php artisan vendor:publish --tag=boost-pipeline-config`.

- **`pipeline:history` and the page report a declaration mismatch**, for a finished run and for one
  still in flight. A run recorded before the digest existed reads as unknown rather than mismatched.

## From 0.12 to 0.13

Additive. Nothing to migrate, but two observable changes for a consumer that reads the MCP payloads
or their schemas.

- **`notices` now appears from the moment a run opens, not only once the run holds a result.** It is
  a property of the walk: it says a declared step was dropped before the walk began, which is known
  at open time and settled regardless of how any later step resolves. It was assembled inside the
  block gated on results — the gate for `all_verified` and `stale`, which are properties of
  results — so `status` on a freshly opened run said nothing about a dropped step, and an agent
  sent to do a skill step first was handed the work with no way to learn the run could never
  fully verify.

  The key appears in strictly more responses than before, never fewer, and its values and presence
  condition are unchanged: absent when the walk raised no notice, never an empty array. A consumer
  that read it only from `open_run` is unaffected.

  One thing does shift. `open_run` assembled `notices` itself, appending it after the envelope; that
  duplicate is gone, so the key now sits earlier in the payload. Compare payloads as sets rather
  than in order if you assert on them.

- **`notices` and `stale` are now declared in the tool output schemas.** Both were emitted
  undeclared by three tools each. Both are now declared once on the shared envelope, beside
  `all_verified`, so all four tools carry the same description. Like `all_verified`, each is present
  only under its own condition rather than on every response: `notices` when the walk dropped a
  declared step, `stale` once a result exists and a step measured a tree other than the one on disk.

  `open_run` declares `stale` too, though it reports it only rarely: a stale run is normally
  discarded and replaced before the payload is built, but the check and the payload read the tree
  separately, so a tree that moves between the two can produce it.

  The description of `notices` also changed. It said "such as a dropped transition step"; there is
  no transition step in this package. It now names the two real causes: a step declared into a phase
  nothing registered, and a tag selection no step carries.

## From 0.11 to 0.12

- **`pipeline:verify` now refuses a run that never held a step the config declares.** It reads the
  config as it stands now and compares what is declared against what the run recorded. A step with
  no verdict at all fails the gate, on both the bare call and `--server-verified`.

  A whole-tree call also refuses a config whose walk drops a declared step — one declared into a
  phase nothing registers — because such a step is missing from the comparison as well as from the
  run. A scoped call does not make that check: the notice does not say which scope the dropped step
  belonged to, so applying it there would fail an answer over a step in an unrelated scope.

  This closes a false green. The receipt could not report it: `coverage` is written from the walk's
  own notices, so it covers a step the server loaded and then dropped, and a step the server never
  loaded raises no notice. The MCP server resolves the config once when its process starts, so a
  step declared after that was invisible to every run until the client reconnected — and the run
  recorded itself complete with `coverage: "complete"`. The tree fingerprint did not catch it
  either, because the run ran against the tree that already held the new step.

  **A gate that passed before can now exit 1.** Two situations reach it: the stale-server case above
  (reconnect the MCP client, then open a new run), and a step added to the config after the last
  run (open a new run). Neither is a false alarm — in both, a step the config asks for has no
  recorded result. A run is compared only against the scope the answer is about — a scoped run
  against its own, an unscoped run against the scope `--only` asked about — so a scoped gate is
  unaffected by steps outside it. A verdict for a step the config has since removed does not fail:
  declared-now must be a subset of recorded, not equal to it.

  `pipeline:history` already reported this per step, and the page already showed it. Only the exit
  code was silent.

- **The page's loopback refusal is a plain 403 response, not `abort()`.** An aborted request renders
  through the host application's exception handler, which is free to do anything: one consumer's
  handler queries the database to translate its error page, so a refused request from a LAN address
  surfaced as a 500 with a SQL error rather than a 403. The refusal no longer travels through host
  rendering, so it stays legible in any application. A consumer that styled this 403 through its own
  handler no longer can.

## From 0.10.8 to 0.11.0

Additive. A project that only configures steps needs no migration, but three things arrive whether
or not it opts into any of them.

- **A run opened through the MCP tools now writes two more records.** Its outcome goes to
  `storage/logs/pipeline/history/<pipeline>/<run-id>.json` after each resolution, and the position
  being worked writes `storage/logs/pipeline/live/<pipeline>.json` while it runs. Both sit beside
  the receipt, under the directory a Laravel app already gitignores, and neither changes what
  `receipts/<pipeline>.json` holds or means.

  Both stores are optional dependencies of a run, so a caller that builds one itself records
  nothing until it passes them.

  History keeps the newest 20 runs per pipeline and deletes the rest on write. Step logs are still
  never pruned. Nothing is gated on the page below: enabling it later shows real history at once
  rather than an empty list.

  A run recorded before this version has no history record, so the page and `pipeline:history` can
  show it no step logs even though the log files are still on disk — the `logs` map in a history
  record is what resolves a step to its log, and only a run from this version on writes one. A page
  enabled right after upgrading therefore looks empty rather than broken. The next run fills it.

- **`php artisan pipeline:history` is new.** It reports; `pipeline:verify` still gates. It exits 0
  for every answer it can give, including a failed or stale run, and non-zero only when it cannot
  answer. Do not wire it into a hook expecting it to block.

- **Step log filenames change, once.** A log was named `<runId>-<stepId>.log`, with a short digest
  appended only to an id that had to be rewritten to be filename-safe. Every id now carries its
  digest: `r-4f2a-a1b2c3-pint-d4e5f6.log`. Selective suffixing left a collision — a rewritten id
  such as `a/b` became `a-b-<digest>`, which a caller could also supply as a literal id, and the
  two then wrote to one file. With the digest always present the encoding is injective, so two ids
  share a filename only on a hash collision.

  Existing logs keep their old names; nothing reads them back by name. Anything that reconstructed
  a log path from a run and step id needs updating — the `logs` map in a history record is the
  path a run actually wrote, and the way to look one up.

- **A publishable config file, `config/boost-pipeline.php`.** Its defaults are merged, so a project
  that never publishes it is unaffected and the page stays off. Publish with
  `php artisan vendor:publish --tag=boost-pipeline-config` to serve the page, which registers only
  when `ui.enabled` is true and the environment is local, behind a loopback-only middleware.

- `PipelineOverview` is new and bound unconditionally. `Run::start()` and the `RunManager`
  constructor take two more optional dependencies, appended after the existing ones, so positional
  and named calls keep working unchanged. (`Run`'s own constructor is private; `start()` is the way
  in.) A consumer that builds either by hand — neither is documented as a seam — passes nothing new
  unless it wants the records.

- `LiveProgressStore::write()` returns `bool` rather than `void`, so a run can tell whether a
  record reached disk. Only a consumer implementing that brand-new interface is affected.

## From 0.10.5 to 0.10.6

Additive. No migration needed for a project that only configures steps.

- `OutputSummariser::summarise()` returns one more key, `clipped`: true when output was dropped by
  the byte cap or the per-line clamp rather than by omitting whole lines. `truncated` keeps its
  meaning exactly — it is paired with a line count, and a clipped line omits no line, so the two
  are separate rather than one widened flag.

  A caller reading the array by key is unaffected. A caller comparing, snapshotting or serialising
  the whole array sees an extra key. `OutputSummariser` is a runner internal in practice — nothing
  in the README treats it as a seam — but it carries no `@internal` marker, so the shape change is
  stated here rather than assumed harmless.

- Messages now say when output was dropped and no log holds it, on every verdict: passed, failed
  and error. The note names the log directory, because the fix is a path permission. Nothing about
  a verdict changed — only what the message admits.

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

- The `note` on a skill step's payload is reworded. It used to say only that the step is "recorded
as
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
    ->withPhases(fn (Phases $phases) =>
    $phases->append(ImpactAnalysis::class)->after(StaticAnalysis::class))
    ->withSteps(fn (Steps $steps) =>
    $steps->in(ImpactAnalysis::class)->append(Shell::run('...')));

  // after — put the step in the phase that runs at the right point
  Pipeline::configure()
    ->withSteps(fn (Steps $steps) =>
    $steps->in(StaticAnalysis::class)->append(Shell::run('...')));
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
