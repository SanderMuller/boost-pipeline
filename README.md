# boost-pipeline

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/boost-pipeline.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-pipeline)
[![Tests](https://img.shields.io/github/actions/workflow/status/SanderMuller/boost-pipeline/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/SanderMuller/boost-pipeline/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/boost-pipeline.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-pipeline)
[![License](https://img.shields.io/packagist/l/sandermuller/boost-pipeline.svg?style=flat-square)](LICENSE)

**A verification pipeline as an MCP server: phases, steps, and a cursor the agent cannot move.**

Your project already has the checks that should gate a change: a formatter, a static analyser, a
test suite. What it probably does not have is anything that makes an agent actually run them, in
order, before reporting the work as done.

The usual approach is prose: a skill or instruction file listing the checks. An agent reads all of
it at once, picks its own order, and afterwards reports whether it complied, judged from its own
transcript.

Two things go wrong there. The list competes for attention with the task it arrives next to, so
the checks near the bottom get whatever is left over — you get most of the work, most of the time.
And *"I ran the tests"* and *"the tests ran"* are different claims, which prose cannot tell apart.

This package makes the server run each check and own the verdict, and hands the agent one step
at a time.

> **Status: prototype.** It works and it is tested, but several designed behaviours are
> deliberately deferred. Read
> [What it deliberately does not do](#what-it-deliberately-does-not-do) before relying on it.

---

## What the guarantee actually is

The guarantee is not that the agent cannot see the pipeline. `.config/pipeline.php` is a file
in your repo, and the agent can read the whole thing whenever it likes. MCP cannot help either:
the specification makes tools *model-controlled*, so there is no way to force a call or pin an
order at the protocol level.

The guarantee is narrower, and does not depend on hiding anything:

> The server only ever **executes** the step at the cursor, and the cursor only advances when that
> step resolves.

Reading ahead tells the agent what is coming. It does not let the agent obtain a receipt for it. A
`passed` is something the server wrote after running a process and reading its exit code.

---

## One step at a time

Handing an agent eight checks and handing it one check are different instructions, even when the
eight are correct and well written. A list has to share the context window with the work itself,
and attention thins out across it: some checks run properly, some get skimmed, one quietly does
not happen. Nothing failed loudly — the run was simply partial, and the report still says done.

A step at the cursor has nothing to share with. The agent receives one command, one phase, and
one thing to report, and cannot be handed the next one until this one resolves. That is the part
worth more than the verdicts on their own: `next_step` narrows the agent's attention to a single
item, and the server keeps the ordering that the prose version could only suggest.

Reading ahead is still allowed and still harmless. The point is not that the agent cannot see
what is coming — it is that nothing else is in the way of the step it is on.

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
                     log: "storage/logs/pipeline/r-4f2a-phpstan.log" },
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

That last line is why `all_verified` exists. More on it below.

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
notifications do not extend it. The step runner's own default sits below this value so a slow step
is not killed client-side with its verdict lost.

Until `.config/pipeline.php` exists the tools decline to register, so a project that has not opted
in gets an honestly empty tool list rather than errors at call time.

## Configure

```php
<?php declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Phases\Defaults\{Refactoring, Formatting, StaticAnalysis, Tests,
Agent};
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
| 5 | `Agent` | Skills the agent invokes, such as `/evaluate` or an eye-verify command |

### Why that order

Not "cheapest first", which applies *within* a phase. Across phases the order follows the fix
chain: **Rector changes code, the formatter formats the result, analysis reads the formatted
result, tests exercise it.** Each phase's output is the next one's input. Putting the cheap checks
first would mean formatting code Rector is about to rewrite.

### `withSteps()` order is not execution order

Steps run in phase order, then in `append`/`prepend` order within a phase. Declaring
`in(Formatting::class)` before `in(Refactoring::class)` changes nothing, because only
`withPhases()` orders phases. Group your `in()` calls in phase order so the file reads the way it
runs. This catches people out.

### Analyse the config, it is real code

`.config/pipeline.php` is PHP that runs in your application, but it sits outside the paths most
projects hand to their static analyser — so a rule you enforce everywhere else is not enforced
there. A real config reached production with a `shell_exec()` its own project bans, invisible to a
full-project run and only found by analysing the file directly.

Add it to your analysed paths:

```neon
parameters:
    paths:
        - app
        - .config
```

The formatter needs naming too, and more explicitly than you would expect: Pint skips
dot-directories on its default scan, so `vendor/bin/pint --test` reports clean with
`$x   =   1;` sitting in `.config/pipeline.php`. Pass the path:

```bash
vendor/bin/pint --test . .config
```

An `exclude` entry in `pint.json` is unrelated — there is no `include`, so the path has to be an
argument. Whatever else gates the rest of your code deserves the same check: assume nothing covers
this file until you have seen it fail.

## Verdicts

| Verdict | Meaning | Cursor |
|---|---|---|
| `passed` | Shell step exited 0 | Advances |
| `failed` | Shell step ran and found problems | Holds (`blocked`) |
| `error` | Shell step **did not run** (missing binary, timeout, exception) | Holds (`halted`) |
| `acknowledged` | Skill step the agent reports it invoked. **Not verified.** | Advances |

**`error` is not `failed`.** A tool that did not run is not a tool that found nothing. An `error`
travels on MCP's error channel; a `failed` verdict deliberately does not, because a failing check
is a *successful* tool call reporting a finding. Flagging that as a protocol error would make
every red check look like a broken server and invite the client to retry it.

**`acknowledged` is not `passed`.** The server cannot verify that `/evaluate` really ran, so it
does not pretend to. Consequently:

- `state: complete` means **the walk finished**, never "everything passed".
- Every response carrying a result also carries `all_verified`, true only when every step was a server-verified
  pass *and* no declared step was dropped from the walk.
- `status` reports `server_run` and `acknowledged` as **separate keys**, never one tally.

Note that a *failed* step is `server_run: true`. That key answers *who produced the verdict*, not
*whether it passed*. Conflating the two is the easiest way to launder a claim into a receipt.

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

A step can also be anchored *between* two phases. It is an ordinary step with a different attach
position:

```php
$steps->between(Formatting::class, StaticAnalysis::class,
    Shell::run('git diff --quiet -- composer.lock'));
```

A dropped step is always reported. If a transition's anchors are missing or not adjacent, or a
step is declared into a phase you removed, that step does not run: the drop appears in `open_run`'s
`notices` and forces `all_verified: false`. A gate you declared but never ran must not look like a
clean run.

---

### A receipt is about the code that was there

Each resolution fingerprints the tree — the commit plus the contents of everything dirty or
untracked, ignoring what git ignores. Edit code after a run went green and `all_verified` flips to
false, with a `stale` key saying which happened:

```
server ← { state: "complete", all_verified: false,
           stale: "The working tree changed after this run resolved, so its verdicts no longer
                   describe the code on disk. Open a new run." }
```

A step that rewrites code declares it, and then its own writes do not count against the run:

```php
$steps->in(Formatting::class)->append(Shell::run('vendor/bin/pint')->mutating());
```

Attribution is by declaration, not by timing. "Whatever step was running must have done it" would
absorb an edit made *while* a step ran — and a blocked run is exactly when you go and change
something, against steps that take half a minute. So a change nothing declared is reported rather
than explained away: either the step rewrites code and should say so, or something edited files
mid-run, and both mean the verdict is not proven for the code that exists now.

Check-mode steps need nothing, which is the usual case — a gate uses `pint --test`, not `pint`.

Order matters, and the run enforces it rather than asking nicely. Each pass records the tree it
measured, so a rewrite landing after a check has already passed leaves that check describing code
the run then changed — and the run says which step it was. Rewrite first, check second, which is
what the default phase order does.

Only a pass records a tree. An acknowledgement was never verified and a failure is already keeping
the run from green, so neither expires — which is why fixing a blocked step and retrying it is not
treated as tampering.

`open_run` uses the same signal. It returns the run already open while the tree sits still, and
starts a fresh one once you have changed something — which is what makes the fix loop work: run,
see a failure, fix it, run again, without restarting the server.

A tree that cannot be fingerprinted (no git) disables expiry rather than guessing, so nothing
becomes permanently unverifiable.

### A config error reaches the agent, not just the log

When `.config/pipeline.php` cannot be loaded, the server still registers — under a degraded mode
whose only tool reports why:

```
open_run → error: "This project's pipeline configuration could not be loaded, so no run can be
                   opened. A step timeout must be greater than zero, got 0. …"
```

Declining to register instead put `mcp:start`'s own "server not found" line on stdout, which for a
stdio server is the protocol channel: unparseable to a client, misleading (it was registered, then
withdrawn), and indistinguishable from a project that never opted in. One driver hung waiting for
the response that line was never going to be.

The message also goes to stderr for whoever is watching the process. Only this package's own
validation errors are handled that way — a syntax error or a `TypeError` in your config still fails
loudly, because those are defects in your code and a tidy message would hide them.

### Missing binaries are flagged at `open_run`

`open_run` returns a `warnings` array when a step's binary is not on disk, so you find out before
paying for the steps ahead of it:

```
warnings: ["Step [oxlint] runs `node_modules/.bin/oxlint`, which is not present.
            The run will halt there unless it is installed first."]
```

Only commands whose first token is a relative path are checked. `php artisan test` and
`composer phpstan` resolve through PATH or another tool's dispatch, and guessing at those would
warn about steps that run perfectly well.

These are separate from `notices`: a notice means a gate you declared will never run, so the run
cannot be fully verified. A warning is just "you will halt at step three".

### Per-step timeouts

The runner caps a step at 540s. One cap for every step has to be set for the slowest, which makes
it useless for the rest — a real suite measured 336s, so the step needing the headroom is also the
one whose runaway takes nine minutes to report. Set it where it differs:

```php
$steps->in(Tests::class)->append(Shell::run('php artisan test')->timeout(1800));
```

Move the floor for every step when your suite is slow throughout, rather than repeating yourself:

```php
return Pipeline::configure()
    ->withTimeout(1800)
    ->withSteps(function (Steps $steps): void { /* ... */ });
```

A step's own `->timeout()` wins over that; the runner's 540s applies when neither is set.

A timeout is an `error`, not a `failed`: the step did not produce a verdict, so the run halts
rather than treating "no answer" as a finding.

### A halt can be retried

`error` means the tool never ran — a missing binary, a bad path. The cursor stays put, so install
the thing and call `next_step` again. Only the step that halted re-runs; the verdicts already
earned stand, because the tree has not moved.

## The trap worth knowing: steps that pass vacuously

A step whose exit code does not reflect its finding will pass while proving nothing. It is the
commonest source of a false green.

- `yarn lint` scoped to `git diff` exits 0 without linting anything when nothing changed.
- `richter:detect-changes` is advisory by default and exits 0 whatever it finds, unless you pass
  `--fail-on`.

So check each step you add: if this tool finds a problem, does the process exit non-zero?

Where a step really is scoped, declare the scope:

```php
Shell::run('yarn lint')->inspecting('git diff --name-only HEAD -- "resources/**/*.ts"')
```

The command still always runs. An empty scope only annotates the verdict, never replaces it, and a
scope command that cannot run is an `error` rather than an empty scope. A scoped step that
inspected nothing reports it: *"Inspected 0 files … passed without proving anything."*

## Driving it

`run_pipeline` ships as an MCP prompt, so it appears as a slash command
(`/mcp__pipeline__run_pipeline`). Or drive the tools directly:

| Tool | What it does |
|---|---|
| `open_run` | Starts a run, returns the first step. Idempotent. |
| `next_step` | Resolves the current step, returns the next, or the same one again. |
| `report_step` | Acknowledges a skill step. Only valid while `awaiting`. |
| `status` | Position, per-step verdicts, verified versus acknowledged. |

---

## Passing data between steps

There is no step-output mechanism, and that is deliberate. Turborepo and Nx have none either.
Use files and the shell. Three patterns cover it.

### 1. Command substitution, in one step

The simplest case is not two steps at all. If a tool can emit a machine-readable list, substitute
it directly:

```php
$steps->in(Tests::class)->append(
    Shell::run('php artisan test $(php artisan richter:affected-tests --plain)')
);
```

Tools often ship a flag exactly for this. `richter:affected-tests --plain` prints one path per
line "for command substitution". Prefer this over splitting into two steps: nothing crosses a
boundary, so there is nothing to coordinate.

**Check what an empty selection does before you trust this.** If the inner command prints nothing
and exits 0, the substitution collapses and `php artisan test` runs with no arguments — the *whole*
suite. That is a vacuous pass wearing the opposite disguise: not a step that verified nothing, but
one that quietly verified everything and took the time to prove it. Nothing in the exit code says
which happened.

Where the tool distinguishes the cases, gate on that instead. `--plain` exits 0 when the selection
is determinable and 2 when it is not, so a wrapper can fail the step rather than silently widen it:

```php
$steps->in(Tests::class)->append(
    Shell::run('scripts/test-affected.sh')   // exits non-zero on an empty or undeterminable selection
);
```

Do not quote the substitution to guard against spaces — `"$(cat ...)"` passes the whole file as
one argument, so a list of one path per line arrives as a single path with newlines in it. If
paths may contain spaces, write the file NUL-delimited and let `xargs` split it:

```php
Shell::run('xargs -0 php artisan test < storage/pipeline/affected.txt')
```

### 2. A file written by one step, read by the next

When the producer is expensive and several later steps need it, write it down once:

```php
$steps->in(StaticAnalysis::class)
    ->append(Shell::run('php artisan richter:detect-changes --json > storage/pipeline/impact.json'))
    ->append(Shell::run('jq -e \'.risk != "high"\' storage/pipeline/impact.json'));
```

Run logs live under `storage/logs/pipeline/`, which a Laravel app already ignores. A directory you
write yourself does not get that for free: Laravel ignores `storage/app`, `storage/framework` and
`storage/logs` through their own nested `.gitignore` files and nothing else under `storage/`, so
either put your own artefacts under `storage/logs/` too, or add `/storage/pipeline/` to your
`.gitignore`. Two things to keep in mind:

- The producer must come first in phase order. Steps run in phase order, then in declaration
  order within a phase. A consumer placed earlier reads a stale file or none at all.
- **A failing producer blocks the run**, so a consumer never runs against a half-written file.
  Because the walk is linear, you do not need to declare a dependency between them.

### 3. An earlier step's log, which is already on disk

Every step writes its full output to `storage/logs/pipeline/<run>-<step>.log`, where `<run>` is
the run id the server reports in every response, and the path comes back in that step's result. A
later step can read it without the producer doing anything special — but mind the run id: logs
persist across runs, and a shell step has no way to receive the path from the earlier step's
result, so a `*` glob can match logs from previous runs as well. Match on the current run id, or
clear the directory at the start of a run:

```php
$steps->in(Tests::class)->append(
    Shell::run('scripts/summarise-analysis.sh storage/logs/pipeline/*-phpstan.log')
);
```

### What not to do

**Do not build a step that parses another step's stdout to set variables.** GitHub Actions shipped
exactly that as `set-output` and deprecated it for a security vulnerability: parsing raw stdout let
any step logging untrusted data inject environment variables and paths. If you need a value, have
the producer write a file with a known shape (`--json`) and have the consumer read *that*.

Be careful interpolating tool output into a shell line. Every command here comes from
`.config/pipeline.php`, which is repo code you trust. The moment a command embeds another tool's output,
that output becomes shell input, and a filename or fixture containing `$(…)` is an injection path.
Prefer a file plus a parser (`jq -e`) over `$(…)` when the value is not something you control.

### If you need more than this

A declared step-output tier is designed but not built. The intended shape, following GitHub and
GitLab: named outputs written to a file the runner owns rather than stdout, a hard size cap in the
kilobytes, values delivered as argv or env, and forward references rejected when the run opens.
Ask before reaching for it. The three patterns above have covered everything that has come up so
far.

## The environment steps run in

Each step runs in a subprocess with every key your app's `.env` **defines** removed, so the child
re-reads `.env` instead of inheriting already-resolved values. That is what lets a test step pin
its own `DB_DATABASE` rather than touching a shared database.

It does **not** sandbox the environment. Anything exported only in the parent shell (a
`GITHUB_TOKEN`, an API key, a CI secret) is inherited by every step command. That is the same
exposure as running the tool by hand in that shell, and step commands come from
`.config/pipeline.php`, which is repo code you already trust. Stricter isolation means an
allowlist, but one that omits `PATH` or `HOME` turns a misconfiguration into "the tool did not
run", so it is not a prototype default.

## Proving an agent step

A skill step reports `acknowledged` because the server cannot check that a skill ran — which is
honest, and also means the steps doing the actual judgement are the ones carrying no verdict. Where
the work leaves a side effect, the server can check for it instead:

```php
$steps->in(Agent::class)->append(
    Skill::run('/eye-verification')
        ->proving('find storage/verify -name "*.png" -newer .git/HEAD | grep -q .')
);
```

The proof runs through the same runner as any shell step, so a step with one reports **`passed`**,
not `acknowledged` — the server ran a command and read an exit code. No model call, nothing taken
on trust. A failing proof blocks the run and returns the same step, so "I did it" without the
artifact is not a way past the cursor.

Pick something the work cannot avoid producing: screenshots newer than the last commit, a harness
log, a review commit. Steps whose work genuinely leaves nothing to find keep `acknowledged`, which
is the right verdict for them.

## Letting something else read the run

Run state is in-process, so for the first four releases every guarantee the server produced died
with the session that produced it. A receipt is written to `storage/logs/pipeline/receipt.json`
after each resolution, and one command turns it into an exit code:

```bash
php artisan pipeline:verify
```

Exit 0 only when a run verified **the code now on disk**. It fails when no run was recorded, when
the receipt describes a different tree, when the run recorded itself stale, and when the walk
finished without verifying every step. That first case is the point: a gate that treats a missing
answer as "nothing to check" passes exactly the run that never happened.

Wire it wherever your other gates live:

```yaml
- name: Pipeline verified this tree
  run: php artisan pipeline:verify
```

**A receipt is not proof a run happened.** It is a file in the working copy, so anything that can
run a shell step can write one — an agent able to forge it could already claim a pass in prose, so
this closes no trust hole that was open. What it carries is the part prose could never get right:
the tree the verdicts were measured against, so a reader can tell a current pass from a stale one
without asking anybody. A consumer that must not trust the working copy runs the pipeline itself.

## What it deliberately does not do

| Not yet | Why it matters |
|---|---|
| Tolerate failures that predate your change | Every step is strict, so use the tool's own baseline (e.g. `phpstan-baseline.neon`) |
| Verify an agent step | Reported as `acknowledged`, never `passed` |
| Stop an agent abandoning the flow | Nothing prevents it running `gh pr create` directly. Needs client hooks |
| Time out a skill step | A run stays `awaiting` indefinitely if `report_step` never arrives |
| Coordinate concurrent callers | No lock; two agents on one server share a cursor |

None of these are quietly handled somewhere. If a row matters to you, budget real work for it.

## Why it is built this way

`.ai/docs/` holds the design record: the decisions and what they were chosen over
([design-history.md][design-history]), the rules a change must not break and the defects that
already broke them ([invariants.md][invariants]), and verified `laravel/mcp` behaviour
([laravel-mcp-notes.md][mcp-notes]).

Read [invariants.md][invariants] before changing `src/Run/`, `src/Runner/` or `src/Mcp/`.

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

Found a vulnerability? See [SECURITY.md](SECURITY.md) — please do not open a public issue.

## License

MIT. See [LICENSE](LICENSE).

[design-history]: https://github.com/SanderMuller/boost-pipeline/blob/main/.ai/docs/design-history.md
[invariants]: https://github.com/SanderMuller/boost-pipeline/blob/main/.ai/docs/invariants.md
[mcp-notes]: https://github.com/SanderMuller/boost-pipeline/blob/main/.ai/docs/laravel-mcp-notes.md
[changelog]: https://github.com/SanderMuller/boost-pipeline/blob/main/CHANGELOG.md
