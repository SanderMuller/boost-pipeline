# boost-pipeline

A verification pipeline as an MCP server: phases, steps, and a cursor the agent cannot move.

The checks that gate a change — formatting, static analysis, tests — are usually described in
prose that an agent reads all at once. Nothing sequences them, and whether a gate actually ran
gets judged by the model from its own transcript. This package makes the server run each check
and own the verdict, and hands the agent one step at a time.

> **Prototype.** Wired into no existing skill, and several designed behaviours are deliberately
> deferred. Read [What it does not do](#what-it-does-not-do) before relying on it.

## What the guarantee actually is

It is **not** that the agent cannot see the pipeline — `.config/pipeline.php` is a file in your
repo, and the agent can read it whenever it likes. MCP cannot help here either: the spec makes
tools *model-controlled*, so there is no way to force a call or pin an order.

The guarantee is narrower and does not depend on hiding anything:

> The server only ever **executes** the step at the cursor, and the cursor only advances when
> that step resolves.

Reading ahead tells the agent what is coming; it does not let the agent obtain a receipt for it.

## Install

```bash
composer require --dev sandermuller/boost-pipeline
```

Add the server to `.mcp.json`:

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

The explicit `timeout` matters. A per-server timeout is a hard wall-clock limit per tool call
that progress notifications do not extend, and the step runner's own default sits below it so a
long step is never killed client-side with its verdict lost.

The tools decline to register until `.config/pipeline.php` exists, so an opted-out project gets
an empty tool list rather than call-time errors.

## Configure

```php
<?php declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Phases\Defaults\{Formatting, StaticAnalysis, Tests, Agent};
use SanderMuller\BoostPipeline\Steps\{Shell, Skill};

return Pipeline::configure()
    ->withSteps(function (Steps $steps): void {
        $steps->in(Formatting::class)
            ->append(Shell::run('vendor/bin/pint --test'));

        $steps->in(StaticAnalysis::class)
            ->append(Shell::run('yarn typecheck'))
            ->append(Shell::run('composer phpstan'));

        $steps->in(Agent::class)
            ->append(Skill::run('/evaluate'));
    });
```

Five phases ship, in this order: `Refactoring`, `Formatting`, `StaticAnalysis`, `Tests`,
`Agent`. They hold no steps until you add them.

### Why that order

Not "cheapest first" — that applies *within* a phase. Across phases the order follows the fix
chain: **Rector changes code, formatting formats the result, static analysis analyses the
formatted result, tests exercise it.** Each phase's output is the next one's input. Putting the
cheap checks first would format code Rector is about to rewrite.

### `withSteps()` order is not execution order

Steps run in **phase** order, then in `append`/`prepend` order within a phase. Declaring
`in(Formatting::class)` before `in(Refactoring::class)` changes nothing — only `withPhases()`
orders phases. Group your `in()` calls in phase order so the file reads the way it runs.

## Phases are just named groups

Which is why adding one costs nothing:

```php
final class ImpactAnalysis extends Phase { /* id(), name() */ }

Pipeline::configure()
    ->withPhases(fn (Phases $phases) => $phases->append(ImpactAnalysis::class)->after(StaticAnalysis::class))
    ->withSteps(fn (Steps $steps) => $steps->in(ImpactAnalysis::class)
        ->append(Shell::run('php artisan richter:detect-changes --fail-on=high')));
```

A step can also be anchored *between* two phases — an ordinary step, different attach position:

```php
$steps->between(Formatting::class, StaticAnalysis::class, Shell::run('git diff --quiet -- composer.lock'));
```

If either anchor is missing, or the two are not adjacent, the transition is dropped **and
reported** in `open_run`'s `notices`. It is never silently promoted into a neighbouring phase.

## Verdicts

| Verdict | Meaning | Cursor |
|---|---|---|
| `passed` | Shell step, exit 0 | Advances |
| `failed` | Shell step ran, found problems | Stays (`blocked`) |
| `error` | Shell step **did not run** — missing binary, timeout, exception | Stays (`halted`) |
| `acknowledged` | Skill step the agent reports it invoked. **Not verified.** | Advances |

Two separations are load-bearing.

**`error` is not `failed`.** A tool that did not run is not a tool that found nothing. `error`
travels on MCP's error channel; `failed` deliberately does not, because a failing check is a
*successful* tool call reporting a finding.

**`acknowledged` is not `passed`.** The server cannot verify that `/evaluate` really ran.
`state: complete` therefore never implies success — every terminal response carries
`all_verified`, true only when every step was a server-verified pass. A run of nothing but
acknowledgements completes with `all_verified: false`.

`status` reports `server_run` and `acknowledged` as **separate keys**, never one tally. Note that
a *failed* step is `server_run: true`: that key answers who produced the verdict, not whether it
passed.

## Watch for steps that pass vacuously

The most common way a pipeline lies. `yarn lint` scoped to `git diff` exits 0 without linting
anything when nothing changed; `richter:detect-changes` is advisory by default and exits 0
whatever it finds. Ask of every step: *if this tool finds a problem, does the process exit
non-zero?*

Where a step is scoped, declare it:

```php
Shell::run('yarn lint')->inspecting('git diff --name-only HEAD -- "resources/**/*.ts"')
```

The command still always runs — an empty scope only annotates the verdict, and a scope command
that cannot run is an `error`, not an empty scope. A scoped step that inspected nothing says so:
*"Inspected 0 files … passed without proving anything."*

## Driving it

`run_pipeline` ships as an MCP prompt, so it appears as a slash command
(`/mcp__pipeline__run_pipeline`). Or call the tools directly: `open_run`, then `next_step` until
`state` is `complete` or `halted`, with `report_step` for skill steps.

## What it does not do

| Not yet | Why it matters |
|---|---|
| Expire a receipt when you edit code afterwards | A pass stays green even if the file changed. Needs fingerprint invalidation |
| Survive a session restart | Run state is in-process, one run per process |
| Tolerate failures that predate your change | Every step is strict; use the tool's own baseline (e.g. `phpstan-baseline.neon`) |
| Verify an agent step | Reported as `acknowledged`, never `passed` |
| Stop an agent abandoning the flow | Nothing prevents it running `gh pr create` directly. Needs hooks |
| Time out a skill step | A run stays `awaiting` indefinitely if `report_step` never arrives |
| Recover from `halted` | Undefined whether a run can resume after the cause is fixed |

## A note on the environment steps run in

Each step runs in a subprocess with every key your app's `.env` **defines** removed, so the
child re-reads `.env` rather than inheriting already-resolved values. That is what lets a Tests
step pin its own `DB_DATABASE` instead of touching a shared database.

What it does **not** do is sandbox the environment. Anything exported only in the parent shell —
a `GITHUB_TOKEN`, an API key, a CI secret — is inherited by every step command. That is the same
exposure as running the tool by hand in that shell, and step commands come from
`.config/pipeline.php`, which is repo code you already trust. If you need stricter isolation,
allowlisting is the direction — but an allowlist that omits `PATH` or `HOME` turns a
misconfiguration into "the tool did not run", so it is not a prototype default.

## Requirements

PHP 8.4+, Laravel 11/12/13, and `laravel/mcp` — currently **v0.9.x, pre-1.0**, so its API may
move between minors.

## Testing

```bash
composer test
```

## License

MIT.
