# boost-pipeline

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/boost-pipeline.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-pipeline)
[![Tests](https://img.shields.io/github/actions/workflow/status/SanderMuller/boost-pipeline/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/SanderMuller/boost-pipeline/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/boost-pipeline.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-pipeline)
[![License](https://img.shields.io/packagist/l/sandermuller/boost-pipeline.svg?style=flat-square)](LICENSE)

**An MCP server that hands an agent one step at a time: phases, steps, and a cursor it cannot move.**

You already have the checks. Ask an agent in prose to run them and you get most of the work, most
of the time, then a report that says *"I ran the tests"* rather than *"the tests ran"*.

The server only ever **executes** the step at the cursor, and the cursor only advances when that
step resolves. A `passed` is what the server wrote after running a process and reading its exit
code. The agent may read `.config/pipeline.php` and see what is coming — that does not get it a
receipt.

> **Status: prototype.** It works and it is tested, but several designed behaviours are
> deliberately deferred. Read
> [What it deliberately does not do](#what-it-deliberately-does-not-do) before relying on it.

## A run, start to finish

```
agent  → open_run()
server ← { run: "r-4f2a", state: "open", position: "1/8", total_steps: 8,
           step: { id: "rector", phase: "Refactoring", kind: "shell", … } }

agent  → next_step()                       # the server runs the step itself
server ← { state: "blocked", position: "5/8",
           result: { verdict: "failed", exit_code: 1,
                     log: "storage/logs/pipeline/r-4f2a-phpstan.log" },
           step: { id: "phpstan", … } }    # ← the SAME step, until it passes

        # agent fixes the errors, walks on, reaches the skill step

agent  → report_step({ summary: "ran /evaluate, fixed 2 issues" })
server ← { state: "complete", all_verified: false, acknowledged: 1 }
```

## Install

```bash
composer require --dev sandermuller/boost-pipeline
```

Register the server in `.mcp.json`:

```json
{
    "mcpServers": {
        "pipeline": {
            "command": "php",
            "args": ["artisan", "mcp:start", "pipeline"],
            "timeout": 600000
        }
    }
}
```

Set the `timeout` explicitly. It is a hard wall-clock limit per tool call, and progress
notifications do not extend it. The tools decline to register until `.config/pipeline.php` exists.

## Configure

```php
<?php declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Phases\Defaults\{Refactoring, Formatting, Tests, Agent};
use SanderMuller\BoostPipeline\Phases\{StepCollection, Steps};
use SanderMuller\BoostPipeline\Steps\{Shell, Skill};

return Pipeline::configure()
    ->withSteps(function (Steps $steps): void {
        $steps->in(Refactoring::class)
            ->append(Shell::run('vendor/bin/rector process --dry-run'));

        // Neither of these feeds the other, so they share a position and run at once.
        $steps->in(Formatting::class)
            ->parallel(function (StepCollection $steps): void {
                $steps->append(Shell::run('vendor/bin/pint --test'));
                $steps->append(Shell::run('yarn lint-all'));
            });

        $steps->in(Tests::class)->append(Shell::run('yarn test:js'));

        $steps->in(Agent::class)
            ->append(Skill::run('/evaluate', instruction: 'Fix what the checks above reported.'));
    });
```

Five phases ship, in this order, empty until you add steps: `Refactoring`, `Formatting`,
`StaticAnalysis`, `Tests`, `Agent`. The order follows the fix chain — Rector changes code, the
formatter formats the result, analysis reads it, tests exercise it. Steps run in phase order, then
in declaration order within a phase, so the order of your `in()` calls changes nothing.

Give a step an explicit `id` when several invoke the same skill or command. Ids name the log files,
and a duplicate throws when the run opens. `.config/` sits outside most analysed paths, so add it to
your PHPStan `paths` and pass it to Pint explicitly (`vendor/bin/pint --test . .config`).

### Steps that run at the same time

A parallel group occupies one position and resolves as a unit. One `next_step` call runs every step
in it and returns every verdict, so **a group reports all its failures in one pass**. If any step
does not pass, the position holds and the next call re-runs the whole group.

A group refuses a **skill step** (several lenses at once is the wall of context the cursor exists to
break up) and a step declaring **`->mutating()`** (its siblings would run against a tree it is
rewriting).

### More than one pipeline

Return a map when a project asks its code more than one question:

```php
return [
    'pr' => Pipeline::configure()->…, 
    'release' => Pipeline::configure()->…,
];
```

A file returning a single `Pipeline` keeps working and is named `default`. Each pipeline has its
own steps, cursor and receipt, so ids only have to be unique within one. Where a map is declared the
name is **required** on every call and never guessed — otherwise the wrong cursor advances
invisibly.

### Running only part of the pipeline

```php
$steps->in(Formatting::class)
    ->append(
        Shell::run('vendor/bin/pint --test')->tagged('backend')
    )
    ->append(
        Shell::run('yarn lint-all')->tagged('frontend')
    );
```

Then `open_run(only: "backend")`. An untagged step runs in every scope, matching is case-sensitive,
and a tag no step carries blocks the run rather than quietly narrowing. `pipeline:verify` exits 1
after a scoped run unless you pass the same `--only=`.

### Per-step timeouts

The runner caps a step at 540s. Raise it for one step with `Shell::run(…)->timeout(1800)`, or for
the whole pipeline with `Pipeline::configure()->withTimeout(1800)`. The step's own value wins.

## Verdicts

| Verdict        | Meaning                                                            | Cursor            |
|----------------|--------------------------------------------------------------------|-------------------|
| `passed`       | Shell step exited 0, or a skill step whose declared proof exited 0 | Advances          |
| `failed`       | The step ran and found problems, or a declared proof did not hold  | Holds (`blocked`) |
| `error`        | Shell step did not run: missing binary, timeout, exception         | Holds (`halted`)  |
| `acknowledged` | Skill step with no proof, which the agent reports it invoked       | Advances          |

An `error` travels on MCP's error channel; a `failed` verdict does not, because a failing check is a
*successful* tool call reporting a finding. Either way the cursor holds, so fix the cause and call
`next_step` again — only that step re-runs. The server cannot verify that `/evaluate` really ran, so
`state: complete` means the walk finished, never "everything passed". A *failed* step is still
`server_run: true`: that key answers who produced the verdict, not whether it passed.

## Driving it

`run_pipeline` ships as an MCP prompt, so it appears as a slash command
(`/mcp__pipeline__run_pipeline`). Or drive the tools directly:

| Tool          | What it does                                                            |
|---------------|-------------------------------------------------------------------------|
| `open_run`    | Starts a run, returns the first step. Idempotent.                       |
| `next_step`   | Resolves the current position, returns the next, or the same one again. |
| `report_step` | Acknowledges a skill step. Only valid while `awaiting`.                 |
| `status`      | Position, per-step verdicts, verified versus acknowledged.              |

`position` counts steps rather than handovers, so a parallel group reports the range it covers
(`2-3/7`). `open_run` warns when a step's binary is not on disk, and a step you declared that never
runs forces `all_verified: false`.

## A receipt is about the code that was there

Each resolution fingerprints the tree: the commit plus the contents of everything dirty or
untracked. Edit code after a run went green and `all_verified` flips to false, with a `stale` key
saying so. `open_run` uses the same signal — it returns the open run while the tree sits still, and
starts a fresh one once you change something, which is what makes the fix loop work.

A step that rewrites code declares it, so its own writes do not count against the run:

```php
$steps->in(Formatting::class)
    ->append(
        Shell::run('vendor/bin/pint')->mutating()
    );
```

Attribution is by declaration, not by timing: a change nothing declared is reported rather than
explained away. Only a pass records a tree, so fixing a blocked step and retrying is not tampering.

## Letting something else read the run

Run state lives in the server process. A receipt goes to `storage/logs/pipeline/receipt.json` after
each resolution, and `php artisan pipeline:verify` turns it into an exit code.

Exit 0 only when a run verified the code now on disk. It fails when no run was recorded, when the
receipt describes a different tree, when the run recorded itself stale, and when any step is
unverified. That first case is the point: a gate that treats a missing answer as "nothing to check"
passes the run that never happened.

**This is a local gate, not a CI one.** The receipt lives under `storage/logs/`, which Laravel
gitignores, so it does not travel with a push. Wire it into a pre-push hook or a pre-PR gate. CI
runs the checks itself.

A pipeline that sequences agent work holds acknowledged steps, so that command always exits 1. For
the narrower question — were the mechanical steps already run against this tree, so a caller can
skip them — use `pipeline:verify --server-verified`. It counts only server verdicts on a complete
walk, and names the ids it counted, because exit 0 never said which checks ran.

## Proving an agent step

Where an agent step leaves a side effect, the server can check for it instead of trusting the
report:

```php
$steps->in(Agent::class)
    ->append(
        Skill::run('/eye-verification')
            ->proving('find storage/verify -name "*.png" -newer .git/HEAD | grep -q .')
    );
```

The proof runs through the same runner as any shell step, so a step with one reports **`passed`**,
not `acknowledged`. A failing proof blocks the run and returns the same step.

**A proof over an artifact the step creates only to satisfy the proof is worse than no proof.** Ask
whether the artifact would exist if nobody had written a proof command; if not, leave the step
acknowledged.

## The trap worth knowing: steps that pass vacuously

A step whose exit code does not reflect its finding will pass while proving nothing. It is the
commonest source of a false green.

- `yarn lint` scoped to `git diff` exits 0 without linting anything when nothing changed.
- An advisory tool exits 0 whatever it finds, unless you pass its `--fail-on` flag.
- A guard that reads `git status` for stray files passes as soon as the work is committed, because
  its input went empty rather than because the tree is clean.

So check each step you add: if this tool finds a problem, does the process exit non-zero? Where a
step really is scoped, declare it with
`Shell::run('yarn lint')->inspecting('git diff --name-only HEAD')`. The command still always runs,
and a step that inspected nothing says so in its verdict.

## Extending

A phase is a name and a position, so a custom one costs no machinery. Implement `Phase`, then place
it with `->withPhases(fn (Phases $phases) => $phases->append(BlastRadius::class)->after(Tests::class))`.
`append()`, `prepend()`, `->after()` and `remove()` are the whole vocabulary.

`StepRunner` is the other seam. Bind your own over the container's
(`$this->app->singleton(StepRunner::class, fn () => new MyRunner)`) and every step goes through it.

## Notes

Each step runs in a subprocess with every key your app's `.env` **defines** removed, so the child
re-reads `.env` rather than inheriting resolved values. This is not a sandbox: anything exported in
the parent shell reaches every step command.

There is no step-output mechanism, deliberately. Use command substitution inside one step, or have
one step write a file (`--json`) that a later step reads. Never parse another step's stdout — that
is the pattern GitHub Actions deprecated as a security vulnerability.

Every step writes its full output to `storage/logs/pipeline/<run>-<step>.log`. Nothing prunes that
directory, and deleting it is safe.

## What it deliberately does not do

| Not yet                                         | Why it matters                                                          |
|-------------------------------------------------|-------------------------------------------------------------------------|
| Tolerate failures that predate your change      | Every step is strict, so use the tool's own baseline                    |
| Verify an agent step whose work leaves no trace | A proof needs an artifact to check                                      |
| Stop an agent abandoning the flow               | Nothing prevents `gh pr create`. Needs client hooks                     |
| Time out a skill step                           | A run stays `awaiting` forever if `report_step` never arrives           |
| Coordinate concurrent callers                   | No lock; two agents on one server share a cursor                        |
| Accumulate scopes across runs                   | A second scoped run replaces the first. Run unscoped for the whole tree |
| Notice two pipelines sharing a name             | PHP collapses duplicate array keys, so the later one silently wins      |
| Know which checks your pipeline ought to hold   | Exit 0 reports on the steps that ran, and nothing more                  |

None of these are quietly handled somewhere. If a row matters to you, budget real work for it.

## Requirements

- PHP 8.4+
- Laravel 12.41+ or 13
- `laravel/mcp`, currently 0.9.x and pre-1.0, so its API may move between minor releases

## Testing

```bash
composer test
```

## Changelog

[CHANGELOG.md][changelog] records what changed in each release. Upgrading across a breaking
release: see [UPGRADING.md](UPGRADING.md).

## Security

Found a vulnerability? See [SECURITY.md](SECURITY.md). Please do not open a public issue.

## License

MIT. See [LICENSE](LICENSE).

[changelog]: https://github.com/SanderMuller/boost-pipeline/blob/main/CHANGELOG.md
