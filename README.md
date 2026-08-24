# boost-pipeline

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/boost-pipeline.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-pipeline)
[![Tests](https://img.shields.io/github/actions/workflow/status/SanderMuller/boost-pipeline/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/SanderMuller/boost-pipeline/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/boost-pipeline.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-pipeline)
[![License](https://img.shields.io/packagist/l/sandermuller/boost-pipeline.svg?style=flat-square)](LICENSE)

**An MCP server that hands an agent one step at a time: phases, steps, and a cursor it cannot move.**

You already have the checks. A formatter, a static analyser, a test suite, review skills that know
what to look for. What you do not have is a way to make an agent run all of them, in order, and tell
you what happened rather than what it remembers doing.

Ask in prose and you get most of the work, most of the time. The list arrives beside the task, so
whatever sits at the bottom gets what is left over, and one review asked to cover six concerns
covers each of them thinly. Then the report says *"I ran the tests"*, which is a different claim from
*"the tests ran"*.

What you get instead:

- A shell step runs on the server. `passed` means a process exited 0, not that an agent said so.
- A skill step carries its own instruction. A review step gets one lens, not a list of six.
- Independent steps run at once. One call shows you every failure rather than the first one.
- Edit the code after a run went green and its verdicts expire. A pass describes the tree it measured.
- A step you declared but never ran forces `all_verified: false` rather than vanishing quietly.

Configuration is a fluent builder in `.config/pipeline.php`:

```php
return Pipeline::configure()
    ->withSteps(function (Steps $steps): void {
        $steps->in(Refactoring::class)
            ->append(Shell::run('vendor/bin/rector process --dry-run'));

        $steps->in(StaticAnalysis::class)
            ->parallel(function (StepCollection $steps): void {
                $steps->append(Shell::run('composer phpstan'));
                $steps->append(Shell::run('yarn typecheck'));
            });

        $steps->in(Agent::class)
            ->append(Skill::run('/code-review', id: 'errors',
                instruction: 'Review the error handling in files changed since main.'));
    });
```

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
not happen. Nothing failed loudly. The run was simply partial, and the report still says done.

A step at the cursor has nothing to share with. The agent receives one command, one phase, and
one thing to report, and cannot be handed the next one until this one resolves. That is the part
worth more than the verdicts on their own: `next_step` narrows the agent's attention to a single
item, and the server keeps the ordering that the prose version could only suggest.

Reading ahead is still allowed and still harmless. What changes is that nothing else competes with
the step the agent is on.

### Narrowing a skill step

A step that says only `/code-review` gives the breadth straight back. The skill arrives with its own
list of concerns, and the agent skims that list the way it would have skimmed yours. Give the step its
instruction and it has one thing to do.

```php
$steps->in(Agent::class)
    ->append(Skill::run('/code-review', id: 'errors',
        instruction: 'Review the error handling in files changed since main. Ignore style and tests.'))
    ->append(Skill::run('/code-review', id: 'tests',
        instruction: 'Judge whether the tests would catch a regression in this change.'));
```

Two steps, one lens each, and the second is not competing with the first for attention. The
instruction reaches the agent verbatim in the step payload, so write it for the agent to act on
rather than as a label for a human reading the config. Without one, the step falls back to naming
the invocation.

Give each step an explicit `id` when several invoke the same skill. An id is derived from the
invocation when you leave it out, so two `/code-review` steps would both derive `code-review`, and a
duplicate id throws when the run opens rather than silently overwriting a verdict. Ids also name the
log files, so pick something you would want to read in `storage/logs/pipeline/`.

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
                   instruction: "Fix what the checks above reported.",
                   note: "Do this step now, then call report_step. …" } }

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

        $steps->in(StaticAnalysis::class)
            ->parallel(function (StepCollection $steps): void {
                $steps->append(Shell::run('composer phpstan'));
                $steps->append(Shell::run('yarn typecheck'));
            });

        $steps->in(Tests::class)
            ->append(Shell::run('yarn test:js'));

        $steps->in(Agent::class)
            ->append(Skill::run('/evaluate', instruction: 'Fix what the checks above reported.'));
    });
```

Five phases ship, in this order, holding no steps until you add them. A phase is a name and a
position, nothing more, so put a step in whichever phase runs at the right point. You can also
[define your own](#your-own-phases), which is what a pipeline of review steps usually wants:

| # | Phase | For |
|---|---|---|
| 1 | `Refactoring` | Rector and friends, in check mode |
| 2 | `Formatting` | Pint, linters |
| 3 | `StaticAnalysis` | PHPStan, Larastan, `tsc`, Mago |
| 4 | `Tests` | Pest, PHPUnit, Vitest, Dusk |
| 5 | `Agent` | Skills the agent invokes, such as `/evaluate` or an eye-verify command |

### Steps that run at the same time

Independent checks do not need to wait for each other. A parallel group occupies one position in
the walk and resolves as a unit:

```php
$steps->in(StaticAnalysis::class)->parallel(function (StepCollection $steps): void {
    $steps->append(Shell::run('composer phpstan'));
    $steps->append(Shell::run('node_modules/.bin/tsc --noEmit'));
});
```

One `next_step` call runs both and returns both verdicts. This costs the agent nothing, which is why
it is allowed: the agent does not perform a shell step, it calls `next_step` and waits, so three
commands running at once is still one thing in front of it.

The wall clock is the obvious gain. The better one is that **a group reports every failure in one
pass**. A sequence blocks at the first failure and hides the rest behind a fix and a re-run.

A group holds the position if any step in it does not pass, and the next call re-runs the whole
group. Re-running a passing sibling costs a little time and keeps the rule simple: a position either
resolved or it did not.

Two things a group refuses, when the config loads rather than when the run reaches it:

- **A skill step.** Several lenses handed over at once is the wall of context the cursor exists to
  break up, and the server cannot fan them out to separate agent contexts to avoid that. Declare
  skill steps on their own.

  This is not a limit on parallel review work. A skill can fan out internally, and a review skill
  dispatching its own subagents is still one step and one handover, so nine lenses inside one skill
  cost no more than one. What a group refuses is nine separate steps.
- **A step that declares `->mutating()`.** Its siblings would run against a tree it is rewriting,
  with no ordering between them to attribute the change to, so every sibling verdict would describe
  code that no longer exists. Run a fix-mode step on its own, before the checks that must see its
  result.

A custom `StepRunner` that does not implement `BatchStepRunner` still works. Its groups resolve one
step after another, which is correct and slower.

### Running only part of the pipeline

Tag a step to say which scope it belongs to, then select one when the run opens:

```php
$steps->in(Formatting::class)
    ->append(Shell::run('vendor/bin/pint --test')->tagged('backend'))
    ->append(Shell::run('yarn lint-all')->tagged('frontend'));
```

```
open_run(only: "backend")
```

A step with no tag runs in every scope, so tagging one step never drops the ones that carry none.
A step can carry several tags and matches on any of them. Matching is case-sensitive.

**Tag both sides, not just the odd one out.** To select a scope, some step has to carry it. Tagging
only your frontend steps gives you `only: "frontend"` but no name for the rest, and asking for
`backend` then matches nothing. The run says so rather than quietly narrowing:

```
notices: ["No step carries the tag [backend], so this run holds only the steps that carry
          no tag at all. Check the spelling: matching is case-sensitive."]
```

That notice blocks like any other, because a scope nothing carries is almost always a typo, and the
untagged steps would otherwise pass and let the run call itself verified.

**A scoped run verifies less, and everything downstream says so.** The run reports its `scope`,
`status` reports `excluded_by_scope`, and the receipt records it, so `pipeline:verify` will not
report the tree verified on the strength of a partial run:

```bash
php artisan pipeline:verify                  # exit 1 after a backend-only run
php artisan pipeline:verify --only=backend   # exit 0
```

The rule is coverage, not equality: a full run answers a question about any one scope, a scoped run
answers only its own. There is one receipt, so scopes do not accumulate: a second scoped run
replaces the first, and a change spanning both wants an unscoped run.

### Asking what the server verified

A pipeline that sequences agent work holds acknowledged steps, so `all_verified` stays false and
both calls above exit 1 whatever the shell steps found. That answer is correct and never changes,
which makes it useless for the question a downstream check actually has: were the mechanical steps
already run against this exact tree, so it can skip them?

```bash
php artisan pipeline:verify --server-verified
```

```
Run [r-4f2a] passed all 5 step(s) the server verified against this tree: [phpstan], [pint-test],
[typecheck], [test-js], [lint-all]. 1 step(s) rewrote the tree rather than checking it and are not
counted. 2 step(s) were only acknowledged and are not counted, so this is not a claim that the tree
is verified.
```

The message names the steps. Exit 0 on its own never said *which* checks ran, so a caller skipping
work on the strength of it could be skipping a check this pipeline does not hold. It also says what
it set aside, because a caller reading only the exit code would take it for the whole run.

**Narrower is not looser.** `all_verified` was carrying several questions at once, and this flag
drops exactly one of them. Five guards stand before the verdicts:

- **The tree is identifiable.** Both fingerprints must be present. A bare call tolerates a missing
  one and answers from the receipt alone, which is fine for a gate. It is not fine here: the flag
  exists so a caller can skip work because the tree still matches, and with nothing to compare there
  is no "still".
- **The walk covered the config that declared it.** `all_verified` goes false both for an
  acknowledgement and for a declared step dropped before the walk began, and the verdict map cannot
  show the second, because a dropped step leaves no verdict. The receipt records `coverage` for
  this. Absent means unknown, never clean, so a receipt written before this release fails closed.
- **The cursor finished.** A receipt is written after every resolution, deliberately, so a walk
  abandoned at step one leaves a readable receipt holding one pass. Anything but `complete` fails.
- **Something was verified.** "Every server verdict passed" is vacuously true over an empty set, so
  a walk of nothing but acknowledgements would pass here having verified nothing.
- **Something was checked, not just rewritten.** A pass says a step succeeded. A step declared
  `->mutating()` produced the tree rather than reading it, so its pass describes the code it was
  handed, never the code left behind. A walk holding nothing but a passing formatter exits 1. The
  receipt records which steps asserted the tree, and an absent record fails closed.

The flag narrows which verdicts count, never which tree the run covered. A stale receipt still fails
on staleness, and a scoped receipt still cannot answer for the whole tree. Combine it with `--only`
to ask both at once.

**What it still cannot tell you is which checks the pipeline holds.** Exit 0 reports on the steps
that ran, so a pipeline declaring no static analysis exits 0 without any. Naming the steps is what
makes that visible rather than silent: read the ids before deciding what to skip.

### Why that order

Not "cheapest first", which applies *within* a phase. Across phases the order follows the fix
chain: **Rector changes code, the formatter formats the result, analysis reads the formatted
result, tests exercise it.** Each phase's output is the next one's input. Putting the cheap checks
first would mean formatting code Rector is about to rewrite.

### `withSteps()` order is not execution order

Steps run in phase order, then in `append`/`prepend` order within a phase. Declaring
`in(Formatting::class)` before `in(Refactoring::class)` changes nothing, because the phase order
decides. Group your `in()` calls in phase order so the file reads the way it runs. This catches
people out.

### Analyse the config, it is real code

`.config/pipeline.php` is PHP that runs in your application, and it sits outside the paths most
projects hand to their static analyser. So a rule you enforce everywhere else is not enforced here,
and a config can hold a call your own ruleset bans without any full-project run noticing.

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

An `exclude` entry in `pint.json` is unrelated: there is no `include`, so the path has to be an
argument.

There is a second way this file escapes the same tool. `vendor/bin/pint --dirty` asks git which
files changed, and git reports a wholly-untracked directory as the directory: `?? .config/`, never
`?? .config/pipeline.php`. So while `.config/` holds no tracked file, `--dirty` enumerates nothing
inside it and reports clean over a file it never opened. Add one tracked file to the directory and
git starts listing the rest individually. Any tool that parses `git status --porcelain` to decide
what to look at inherits this, not just Pint. Whatever else gates the rest of your code deserves the same check: assume nothing covers
this file until you have seen it fail.

## Verdicts

| Verdict | Meaning | Cursor |
|---|---|---|
| `passed` | Shell step exited 0, or a skill step whose declared proof exited 0 | Advances |
| `failed` | The step ran and found problems, or a declared proof did not hold | Holds (`blocked`) |
| `error` | Shell step did not run: missing binary, timeout, exception | Holds (`halted`) |
| `acknowledged` | Skill step with no proof, which the agent reports it invoked, and the server did not check | Advances |

**`error` is not `failed`.** A tool that did not run is not a tool that found nothing. An `error`
travels on MCP's error channel; a `failed` verdict deliberately does not, because a failing check
is a *successful* tool call reporting a finding. Flagging that as a protocol error would make
every red check look like a broken server and invite the client to retry it.

**`acknowledged` is not `passed`.** The server cannot verify that `/evaluate` really ran, so it does
not pretend to, unless the step declares a proof, which is the one way agent work becomes something
the server checked (see [Proving an agent step](#proving-an-agent-step)). Without one:

- `state: complete` means the walk finished, never "everything passed".
- Every response carrying a result also carries `all_verified`, true only when every step was a server-verified
  pass *and* no declared step was dropped from the walk.
- `status` reports `server_run` and `acknowledged` as separate keys, never one tally.

Note that a *failed* step is `server_run: true`. That key answers *who produced the verdict*, not
*whether it passed*. Conflating the two is the easiest way to launder a claim into a receipt.

## Extending

### Your own phases

The five defaults suit a pipeline of mechanical checks. A pipeline that sequences review work does
not fit them, because its steps are not refactoring or formatting or tests. So the set is open:

```php
final class BlastRadius implements Phase
{
    public function id(): string { return 'blast-radius'; }
    public function name(): string { return 'Blast radius'; }
}

Pipeline::configure()
    ->withPhases(fn (Phases $phases) => $phases->append(BlastRadius::class)->after(Tests::class))
    ->withSteps(fn (Steps $steps) => $steps->in(BlastRadius::class)->append(
        Skill::run('/code-review', id: 'blast-radius',
            instruction: 'Name what this change can break, and nothing else.'),
    ));
```

A phase is a name and a position, nothing more, so a custom one costs no machinery. `append()` and
`prepend()` place it, `->after()` moves it, and `remove()` drops a default you have no steps for.

### Your own runner

`StepRunner` is the other seam. Bind your own over the container's and every step the server resolves
goes through it, so a step kind the shipped runner refuses becomes yours to handle.

```php
$this->app->singleton(StepRunner::class, fn () => new MyRunner);
```

A custom `Step` needs that binding to be worth writing. `ProcessStepRunner` runs `Shell` and
nothing else, and a step reporting `StepKind::Skill` is acknowledged by the agent rather than run.
With the shipped runner in place, a third kind of step has nowhere to resolve.

A dropped step is always reported. Declare a step into a phase that is not registered and it does
not run: the drop appears in `open_run`'s `notices` and forces `all_verified: false`. A gate you
declared but never ran must not look like a clean run.

---

### A receipt is about the code that was there

Each resolution fingerprints the tree: the commit plus the contents of everything dirty or
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
absorb an edit made *while* a step ran, and a blocked run is exactly when you go and change
something, against steps that take half a minute. So a change nothing declared is reported rather
than explained away: either the step rewrites code and should say so, or something edited files
mid-run, and both mean the verdict is not proven for the code that exists now.

Check-mode steps need nothing, which is the usual case: a gate uses `pint --test`, not `pint`.

A pipeline where **no** step declares `->mutating()` gets something extra out of that. The stale
report names two possible causes, and one of them is impossible by construction: with nothing in the
run able to write, a stale report during a run can only mean something outside it edited the code.
That is a reason to prefer check mode beyond the obvious one, and a reason to keep a fix-mode step
out of a pipeline whose receipt you intend to gate on.

Inside a parallel group the mechanism can say a write happened and not which step did it. Every
step in a group measures the same tree from before the group ran, so a stale report names the first
of them that passed, whoever wrote. The message says so rather than letting the named step read as
proof. It still fails closed: the run is not verified, and `pipeline:verify` exits 1. This is the
undeclared case only. A step that declares `->mutating()` cannot join a group at all.

Order matters, and the run enforces it rather than asking nicely. Each pass records the tree it
measured, so a rewrite landing after a check has already passed leaves that check describing code
the run then changed, and the run says which step it was. Rewrite first, check second, which is
what the default phase order does.

Only a pass records a tree. An acknowledgement was never verified and a failure is already keeping
the run from green, so neither expires. That is why fixing a blocked step and retrying it is not
treated as tampering.

`open_run` uses the same signal. It returns the run already open while the tree sits still, and
starts a fresh one once you have changed something. That is what makes the fix loop work: run,
see a failure, fix it, run again, without restarting the server.

A tree that cannot be fingerprinted (no git) disables expiry rather than guessing, so nothing
becomes permanently unverifiable.

### A config error reaches the agent, not just the log

When `.config/pipeline.php` cannot be loaded, the server still registers, under a degraded mode
whose only tool reports why:

```
open_run → error: "This project's pipeline configuration could not be loaded, so no run can be
                   opened. A step timeout must be greater than zero, got 0. …"
```

The server has to register for this to work. For a stdio server, stdout is the JSON-RPC channel, so
a server that withdraws leaves `mcp:start`'s own "not found" line there instead: unparseable to a
client, and indistinguishable from a project that never opted in. Some clients report a confusing
cause; others wait for a response that is never coming.

The message also goes to stderr for whoever is watching the process. Only this package's own
validation errors are handled that way. A syntax error or a `TypeError` in your config still fails
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

The runner caps a step at 540s. A single cap for every step has to be set for the slowest, which
makes it useless for the rest: a five-minute test suite drags the ceiling up, and then a runaway
formatter takes nine minutes to report. Set it where it differs:

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

`error` means the tool never ran: a missing binary, a bad path. The cursor stays put, so install
the thing and call `next_step` again. Only the step that halted re-runs; the verdicts already
earned stand, because the tree has not moved.

## The trap worth knowing: steps that pass vacuously

A step whose exit code does not reflect its finding will pass while proving nothing. It is the
commonest source of a false green.

- `yarn lint` scoped to `git diff` exits 0 without linting anything when nothing changed.
- `richter:detect-changes` is advisory by default and exits 0 whatever it finds, unless you pass
  `--fail-on`.
- A guard that reads `git status` to find stray files passes as soon as the work is committed,
  because its input went empty rather than because the tree is clean of what it looks for.

So check each step you add: if this tool finds a problem, does the process exit non-zero?

That third one is about *when* the step runs rather than how it is configured, and `inspecting()`
cannot rescue it, because the scope is the working tree itself. A step whose input is the state of
the tree answers a different question before and after a commit. Run it where its input still
exists, which for a pre-commit guard means before the commit rather than inside a walk you opened
afterwards.

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
| `next_step` | Resolves the current position, returns the next, or the same one again. |
| `report_step` | Acknowledges a skill step. Only valid while `awaiting`. |
| `status` | Position, per-step verdicts, verified versus acknowledged. |

`position` counts steps rather than handovers, so a parallel group reports the range it covers
(`2-3/7`). A seven-step walk holding two groups takes five calls, so do not read the number as
calls remaining.

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
and exits 0, the substitution collapses and `php artisan test` runs with no arguments, against the *whole*
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

Do not quote the substitution to guard against spaces. `"$(cat ...)"` passes the whole file as
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
- A failing producer blocks the run, so a consumer never runs against a half-written file.
  Because the walk is linear, you do not need to declare a dependency between them.

### 3. An earlier step's log, which is already on disk

Every step writes its full output to `storage/logs/pipeline/<run>-<step>.log`, where `<run>` is
the run id the server reports in every response, and the path comes back in that step's result. A
later step can read it without the producer doing anything special, but mind the run id: logs
persist across runs, and a shell step has no way to receive the path from the earlier step's
result, so a `*` glob can match logs from previous runs as well. Match on the current run id, or
clear the directory at the start of a run:

```php
$steps->in(Tests::class)->append(
    Shell::run('scripts/summarise-analysis.sh storage/logs/pipeline/*-phpstan.log')
);
```

Nothing prunes that directory. It gains a file for every step the server runs: each shell step, and
each skill step whose proof runs. Every one holds that step's whole output rather than the summary.
Laravel rotates the logs its own channels write and does not reach these, so retention is the
project's business. Deleting them is safe, because no run reads another run's logs unless a step you
wrote globs for them. Left alone the directory also makes the hazard above worse, since the more
history sits there, the likelier a `*` match picks up a run you did not mean.

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

A skill step reports `acknowledged` because the server cannot check that a skill ran. That is
honest, and it also leaves the steps doing the actual judgement carrying no verdict at all. Where
the work leaves a side effect, the server can check for it instead:

```php
$steps->in(Agent::class)->append(
    Skill::run('/eye-verification')
        ->proving('find storage/verify -name "*.png" -newer .git/HEAD | grep -q .')
);
```

The proof runs through the same runner as any shell step, so a step with one reports **`passed`**,
not `acknowledged`: the server ran a command and read an exit code. No model call, nothing taken
on trust. A failing proof blocks the run and returns the same step, so "I did it" without the
artifact is not a way past the cursor.

Pick something the work cannot avoid producing: screenshots newer than the last commit, a harness
log, a review commit. Steps whose work genuinely leaves nothing to find keep `acknowledged`, which
is the right verdict for them.

**A proof over an artifact the step creates only to satisfy the proof is worse than no proof.** It
turns `acknowledged` into `passed` while checking nothing about whether the work was done. It is the
same laundering as reading `server_run: true` as "it passed". Review skills are the common case: a
self-review, a code review and a Codex review can all leave the tree untouched, so there is nothing
to test for, and a run whose judgement steps are honest about that never reaches `all_verified`.
That is the correct outcome. Ask whether the artifact would exist if nobody had
written a proof command; if the answer is no, leave the step acknowledged.

## Letting something else read the run

Run state lives in the server process, so a guarantee it produced dies with the session unless
something writes it down. A receipt goes to `storage/logs/pipeline/receipt.json` after each
resolution, and one command turns it into an exit code:

```bash
php artisan pipeline:verify
```

Exit 0 only when a run verified the code now on disk. It fails when no run was recorded, when
the receipt describes a different tree, when the run recorded itself stale, and when the run has not
verified every step, which includes a run still sitting at a failed step, since a blocked or halted
run is retryable rather than finished. That first case is the point: a gate that treats a missing
answer as "nothing to check" passes exactly the run that never happened.

**This is a local gate, not a CI one.** The receipt lives under `storage/logs/`, which every Laravel
application gitignores, so it does not travel with a push. A CI job finds no receipt and fails
every build. Wire it where the working copy is the thing being judged: a pre-commit or pre-push
hook, or a closeout check an agent runs before it opens a pull request.

```bash
# .git/hooks/pre-push, or a pre-PR gate in your agent's flow
php artisan pipeline:verify || exit 1
```

CI's job is different and unchanged: it runs the checks itself rather than asking whether someone
else did.

It answers a narrower question than "did the pipeline run". A skill step with no proof is
`acknowledged`, so `all_verified` stays false and this command exits 1 however many times the run
repeats. That is the contract working: the server did not verify that work, and will not claim it
did.

It also means this command is not the measure of a sequencing pipeline. A pipeline whose steps
are review and evaluation work is meant to hold acknowledged steps, and it still delivers what it is
for: one step at a time, in order, none of them skippable. `pipeline:verify` is the right gate for
the mechanical part of a pipeline and says so; for the rest, read the run with `status`, which
reports verified and acknowledged work as separate counts precisely so neither is mistaken for the
other.

**A receipt is not proof a run happened.** It is a file in the working copy, so anything that can
run a shell step can write one. An agent able to forge it could already claim a pass in prose, so
this closes no trust hole that was open. What it carries is the part prose could never get right:
the tree the verdicts were measured against, so a reader can tell a current pass from a stale one
without asking anybody. A consumer that must not trust the working copy runs the pipeline itself.

## What it deliberately does not do

| Not yet | Why it matters |
|---|---|
| Tolerate failures that predate your change | Every step is strict, so use the tool's own baseline (e.g. `phpstan-baseline.neon`) |
| Verify an agent step whose work leaves no trace | A declared proof makes a step `passed`, but only where there is an artifact to check. Judgement that touches nothing stays `acknowledged` |
| Stop an agent abandoning the flow | Nothing prevents it running `gh pr create` directly. Needs client hooks |
| Time out a skill step | A run stays `awaiting` indefinitely if `report_step` never arrives |
| Coordinate concurrent callers | No lock; two agents on one server share a cursor |
| Accumulate scopes across runs | One receipt, so a second scoped run replaces the first. Verifying two scopes separately never adds up to a verified tree; run unscoped for that |
| Know which checks your pipeline ought to hold | Exit 0 reports on the steps that ran. A pipeline declaring no static analysis exits 0 without any, so `--server-verified` names the ids it counted and leaves the judgement to you |
| Read a mutating step's pass as a claim about the result | `all_verified` counts it, because every step passed. `--server-verified` does not, because a formatter produced the tree rather than checking it |

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

Found a vulnerability? See [SECURITY.md](SECURITY.md). Please do not open a public issue.

## License

MIT. See [LICENSE](LICENSE).

[design-history]: https://github.com/SanderMuller/boost-pipeline/blob/main/.ai/docs/design-history.md
[invariants]: https://github.com/SanderMuller/boost-pipeline/blob/main/.ai/docs/invariants.md
[mcp-notes]: https://github.com/SanderMuller/boost-pipeline/blob/main/.ai/docs/laravel-mcp-notes.md
[changelog]: https://github.com/SanderMuller/boost-pipeline/blob/main/CHANGELOG.md
