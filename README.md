# boost-pipeline

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/boost-pipeline.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-pipeline)
[![Tests](https://img.shields.io/github/actions/workflow/status/SanderMuller/boost-pipeline/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/SanderMuller/boost-pipeline/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/boost-pipeline.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-pipeline)
[![License](https://img.shields.io/packagist/l/sandermuller/boost-pipeline.svg?style=flat-square)](LICENSE)

**A verification pipeline as an MCP server: phases, steps, and a cursor the agent cannot move.**

Your project already has the checks that should gate a change — a formatter, a static analyser, a
test suite. What it probably does not have is anything that makes an agent actually run them, in
order, before reporting the work as done.

The usual approach is prose: a skill or instruction file listing the checks. An agent reads all of
it at once, picks its own order, and afterwards reports whether it complied — judged from its own
transcript. That last part is the problem. *"I ran the tests"* and *"the tests ran"* are different
claims, and prose cannot tell them apart.

This package makes the **server** run each check and own the verdict, and hands the agent one step
at a time.

> **Status: prototype.** It works and it is tested, but several designed behaviours are
> deliberately deferred. Read
> [What it deliberately does not do](#what-it-deliberately-does-not-do) before relying on it.

---

## What the guarantee actually is

Worth being precise, because the obvious reading is wrong.

The guarantee is **not** that the agent cannot see the pipeline. `.config/pipeline.php` is a file
in your repo; the agent can read the whole thing whenever it likes. MCP cannot help here either —
the specification makes tools *model-controlled*, so there is no way to force a call or pin an
order at the protocol level.

The guarantee is narrower, and does not depend on hiding anything:

> The server only ever **executes** the step at the cursor, and the cursor only advances when that
> step resolves.

Reading ahead tells the agent what is coming. It does not let the agent obtain a receipt for it. A
`passed` is something the server wrote after running a process and reading its exit code.

---

## A run, start to finish

```
agent  → open_run()
server ← { run: "r-4f2a", state: "open", position: "1/8", total_steps: 8,
           step: { id: "rector", phase: "Refactoring", kind: "shell",
                   command: "vendor/bin/rector process --dry-run" } }

agent  → next_step()                       # the server runs rector itself
server ← { state: "running", position: "2/8",
           result: { verdict: "passed", exit_code: 0, server_run: true },
           step: { id: "pint", … } }

…

agent  → next_step()                       # the server runs phpstan itself
server ← { state: "blocked", position: "5/8",
           result: { verdict: "failed", exit_code: 1,
                     log: "storage/pipeline/logs/r-4f2a-phpstan.log" },
           step: { id: "phpstan", … } }    # ← the SAME step

agent  → next_step()                       # asking again does not help
server ← { state: "blocked", step: { id: "phpstan", … } }

        # agent fixes the errors, then continues

agent  → next_step()
server ← { state: "awaiting", position: "7/8",
           step: { id: "evaluate", kind: "skill", invoke: "/evaluate",
                   note: "…recorded as acknowledged, not verified." } }

agent  → report_step({ summary: "ran /evaluate, fixed 2 issues" })
server ← { state: "complete", all_verified: false, acknowledged: 1 }
```

That last line is the point of the whole design. Read on.

---

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

The explicit `timeout` is not decoration. A per-server timeout is a hard wall-clock limit per tool
call that progress notifications do **not** extend; the step runner's own default sits below it, so
a slow step is never killed client-side with its verdict lost.

Until `.config/pipeline.php` exists the tools decline to register, so a project that has not opted
in gets an honestly empty tool list rather than errors at call time.

---

## Configure

```php
<?php declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Phases\Defaults\{Refactoring, Formatting, StaticAnalysis, Tests, Agent};
use SanderMuller\BoostPipeline\Phases\Steps;
use SanderMuller\BoostPipeline\Steps\{Shell, Skill};

return Pipeline::configure()
    ->withSteps(function (Steps $steps): void {
        $steps->in(Refactoring::class)
            ->append(Shell::run('vendor/bin/rector process --dry-run'));

        $steps->in(Formatting::class)
            ->append(Shell::run('vendor/bin/pint --test'))
            ->append(Shell::run('yarn lint-all'));

        $steps->in(StaticAnalysis::class)
            ->append(Shell::run('yarn typecheck'))
            ->append(Shell::run('composer phpstan'));

        $steps->in(Tests::class)
            ->append(Shell::run('yarn test:js'));

        $steps->in(Agent::class)
            ->append(Skill::run('/evaluate'));
    });
```

Five phases ship, in this order, holding no steps until you add them:

| # | Phase | For |
|---|---|---|
| 1 | `Refactoring` | Rector and friends, in check mode |
| 2 | `Formatting` | Pint, linters |
| 3 | `StaticAnalysis` | PHPStan, Larastan, `tsc`, Mago |
| 4 | `Tests` | Pest, PHPUnit, Vitest, Dusk |
| 5 | `Agent` | Skills the agent invokes — `/evaluate`, an eye-verify command |

### Why that order

Not "cheapest first" — that rule applies *within* a phase. Across phases the order follows the fix
chain: **Rector changes code, the formatter formats the result, analysis reads the formatted
result, tests exercise it.** Each phase's output is the next one's input. Putting the cheap checks
first would mean formatting code Rector is about to rewrite.

### `withSteps()` order is not execution order

A real trap. Steps run in **phase** order, then in `append`/`prepend` order within a phase.
Declaring `in(Formatting::class)` before `in(Refactoring::class)` changes nothing — only
`withPhases()` orders phases. Group your `in()` calls in phase order so the file reads the way it
runs.

---

## Verdicts, and the two distinctions that matter

| Verdict | Meaning | Cursor |
|---|---|---|
| `passed` | Shell step exited 0 | Advances |
| `failed` | Shell step ran and found problems | Holds (`blocked`) |
| `error` | Shell step **did not run** — missing binary, timeout, exception | Holds (`halted`) |
| `acknowledged` | Skill step the agent reports it invoked. **Not verified.** | Advances |

**`error` is not `failed`.** A tool that did not run is not a tool that found nothing. An `error`
travels on MCP's error channel; a `failed` verdict deliberately does not, because a failing check
is a *successful* tool call reporting a finding — flagging that as a protocol error would make
every red check look like a broken server and invite the client to retry it.

**`acknowledged` is not `passed`.** The server cannot verify that `/evaluate` really ran, so it
does not pretend to. Consequently:

- `state: complete` means **the walk finished**, never "everything passed".
- Every terminal response carries `all_verified` — true only when every step was a server-verified
  pass *and* no declared step was dropped from the walk.
- `status` reports `server_run` and `acknowledged` as **separate keys**, never one tally.

Note that a *failed* step is `server_run: true`. That key answers *who produced the verdict*, not
*whether it passed* — conflating the two is the easiest way to launder a claim into a receipt.

---

## Extending

A phase is nothing but a named, ordered group of steps, which is why adding one costs nothing:

```php
final class ImpactAnalysis implements Phase
{
    public function id(): string { return 'impact-analysis'; }
    public function name(): string { return 'Impact analysis'; }
}

Pipeline::configure()
    ->withPhases(fn (Phases $phases) => $phases->append(ImpactAnalysis::class)->after(StaticAnalysis::class))
    ->withSteps(fn (Steps $steps) => $steps->in(ImpactAnalysis::class)
        ->append(Shell::run('php artisan richter:detect-changes --fail-on=high')));
```

A step can also be anchored *between* two phases — an ordinary step, different attach position:

```php
$steps->between(Formatting::class, StaticAnalysis::class,
    Shell::run('git diff --quiet -- composer.lock'));
```

**Nothing declared disappears quietly.** If a transition's anchors are missing or not adjacent, or
a step is declared into a phase you removed, that step is dropped — and the drop is reported in
`open_run`'s `notices` *and* forces `all_verified: false`. A gate you declared but never ran must
never look like a clean run.

---

## The trap worth knowing: steps that pass vacuously

The most common way a pipeline lies is a step whose exit code does not reflect its finding.

- `yarn lint` scoped to `git diff` exits 0 without linting anything when nothing changed.
- `richter:detect-changes` is advisory by default and exits 0 whatever it finds, unless you pass
  `--fail-on`.

Ask it of every step you add: *if this tool finds a problem, does the process exit non-zero?*

Where a step really is scoped, declare the scope:

```php
Shell::run('yarn lint')->inspecting('git diff --name-only HEAD -- "resources/**/*.ts"')
```

The command still always runs — an empty scope only annotates the verdict, never replaces it, and a
scope command that cannot run is an `error` rather than an empty scope. A scoped step that
inspected nothing says so out loud: *"Inspected 0 files … passed without proving anything."*

---

## Driving it

`run_pipeline` ships as an MCP prompt, so it appears as a slash command
(`/mcp__pipeline__run_pipeline`). Or drive the tools directly:

| Tool | Does |
|---|---|
| `open_run` | Starts a run, returns the first step. Idempotent. |
| `next_step` | Resolves the current step, returns the next — or the same one again. |
| `report_step` | Acknowledges a skill step. Only valid while `awaiting`. |
| `status` | Position, per-step verdicts, verified versus acknowledged. |

---

## The environment steps run in

Each step runs in a subprocess with every key your app's `.env` **defines** removed, so the child
re-reads `.env` instead of inheriting already-resolved values. That is what lets a test step pin
its own `DB_DATABASE` rather than touching a shared database.

It does **not** sandbox the environment. Anything exported only in the parent shell — a
`GITHUB_TOKEN`, an API key, a CI secret — is inherited by every step command. That is the same
exposure as running the tool by hand in that shell, and step commands come from
`.config/pipeline.php`, which is repo code you already trust. Stricter isolation means an
allowlist, but one that omits `PATH` or `HOME` turns a misconfiguration into "the tool did not
run" — so it is not a prototype default.

---

## What it deliberately does not do

| Not yet | Why it matters |
|---|---|
| Expire a receipt when you edit code afterwards | A pass stays green even if the file changed. Needs input fingerprinting |
| Survive a session restart | Run state is in-process; one run per process |
| Tolerate failures that predate your change | Every step is strict — use the tool's own baseline (e.g. `phpstan-baseline.neon`) |
| Verify an agent step | Reported as `acknowledged`, never `passed` |
| Stop an agent abandoning the flow | Nothing prevents it running `gh pr create` directly. Needs client hooks |
| Time out a skill step | A run stays `awaiting` indefinitely if `report_step` never arrives |
| Resume after `halted` | Undefined whether a run can continue once the cause is fixed |
| Coordinate concurrent callers | No lock; two agents on one server share a cursor |

None of these are secretly handled. If a row matters to you, it is real work, not a flag.

---

## Requirements

- PHP 8.4+
- Laravel 11, 12 or 13
- `laravel/mcp` — currently **0.9.x, pre-1.0**, so its API may move between minor releases

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE](LICENSE).
