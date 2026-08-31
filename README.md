# boost-pipeline

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sandermuller/boost-
pipeline.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-pipeline)
[![Tests](https://img.shields.io/github/actions/workflow/status/SanderMuller/boost-pipeline/run-
tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/SanderMuller/boost-
pipeline/actions/workflows/run-tests.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/sandermuller/boost-
pipeline.svg?style=flat-square)](https://packagist.org/packages/sandermuller/boost-pipeline)
[![License](https://img.shields.io/packagist/l/sandermuller/boost-pipeline.svg?style=flat-
square)](LICENSE)

**An MCP server that hands an agent one step at a time: phases, steps, and a cursor it cannot
move.**

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
                     log: "storage/logs/pipeline/r-4f2a-9c1e07-phpstan-4ab8d2.log" },
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
*successful* tool call reporting a finding. Either way the cursor holds, so fix the cause — then
call `open_run` rather than deciding whether your fix moved the tree. It hands back the same run
when nothing moved and a fresh one when anything did, including a commit; `next_step` re-runs only
that step and never re-checks. It also replaces a run that has gone stale — so if you did continue
with `next_step` and stranded the walk, reopening is the way out rather than a no-op. The server
cannot verify that `/evaluate` really ran, so
`state: complete` means the walk finished, never "everything passed". A *failed* step is still
`server_run: true`: that key answers who produced the verdict, not whether it passed.

## Driving it

`run_pipeline` ships as an MCP prompt, so it appears as a slash command
(`/mcp__pipeline__run_pipeline`). Or drive the tools directly:

| Tool          | What it does                                                            |
|---------------|-------------------------------------------------------------------------|
| `open_run`    | Starts a run, returns the first step. Idempotent on a healthy run; replaces one whose tree moved or that has gone stale. |
| `next_step`   | Resolves the current position, returns the next, or the same one again. |
| `report_step` | Acknowledges a skill step. Only valid while `awaiting`.                 |
| `status`      | Position, per-step verdicts, verified versus acknowledged.              |

`position` counts steps rather than handovers, so a parallel group reports the range it covers
(`2-3/7`). `open_run` warns when a step's binary is not on disk, and a step you declared that this
run would have walked but cannot run forces `all_verified: false`.

## A receipt is about the code that was there

Each resolution fingerprints the tree: the commit plus the contents of everything dirty or
untracked. Edit code after a run went green and `all_verified` flips to false, with a `stale` key
saying so. `open_run` uses the same signal — it returns the open run while the tree sits still, and
starts a fresh one once you change something, which is what makes the fix loop work.

**Do not work in the repository while a walk is open.** A run holds a claim about one tree, and
*any* git-visible change invalidates it — not only an edit. The commit is part of the fingerprint,
so `git commit`, `--amend`, `checkout`, `rebase` and `stash` all move it with nothing on disk
changed. That is the mechanism working, and it is worth knowing before it surprises you: finishing
a step and committing the work feels like progress rather than a change, and it stales the run all
the same. Nothing needs undoing when it happens — reopen against the commit you now have.

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

Run state lives in the server process. A receipt goes to
`storage/logs/pipeline/receipts/<pipeline>.json`
after each resolution, and `php artisan pipeline:verify` turns it into an exit code.

Exit 0 only when a run verified the code now on disk. It fails when no run was recorded, when the
receipt describes a different tree, when the run recorded itself stale, and when any step is
unverified. That first case is the point: a gate that treats a missing answer as "nothing to check"
passes the run that never happened.

It also fails when the run walked a pipeline declaration your config no longer produces. The server
resolves the config once when its process starts, so a step you redefine after that keeps running
its old definition — an old command under the same step id, recorded as a pass. The verdicts are
keyed by id, so nothing about the step list looks wrong, and the tree fingerprint matches because
the run ran against
the tree that already held your change. Reconnect the MCP client and open a new run.

Two other things produce the same mismatch, and the message names all three. A config git cannot
see — ignored, symlinked, or composed from a file outside the repository — moves without moving the
tree fingerprint. And a config that computes part of its declaration when it loads, from an
environment variable or a file outside the repo, cannot be compared across processes at all: two of
them disagree about files nobody touched. If that is deliberate, set `verify.config_fingerprint` to
false in the published config; nothing else compensates for it, so do it knowingly.

It also fails when your config declares a step the run never held. The command loads the config in
its own process, so it sees the steps declared now, not the ones the server loaded when it
started — and a server started before a step was declared walks right past it, recording a run
that calls itself complete. Reconnect the MCP client and open a new run. The comparison is made in
the scope the answer is about, and a step you have since removed does not fail anything.

It also fails when your config declares a step into a phase nothing registers. Such a step never
reaches the cursor, so it cannot fail and cannot be skipped. It just never runs. Register the
phase,
or move the step. A scoped call refuses a drop inside its own scope and ignores one outside it; an
untagged step belongs to every scope, so a dropped untagged step fails every call.

The `notices` a run reports are not scope-filtered, and that is deliberate. They name every step the
config declared into an unregistered phase, because that is what the config got wrong regardless of
which scope you asked about. So a frontend-scoped run can name a backend step as dropped while the
gate refuses over only the frontend one, and can report `all_verified: true` alongside it: the
config has a problem elsewhere, and this scope is verified.

A tag no step carries is the one case that still blocks a run whatever its scope. Nothing is dropped
there: the walk becomes every untagged step and those pass, so a mistyped tag would otherwise report
a verified run for a scope that was never checked.

**This is a local gate, not a CI one.** The receipt lives under `storage/logs/`, which Laravel
gitignores, so it does not travel with a push. Wire it into a pre-push hook or a pre-PR gate. CI
runs the checks itself.

A pipeline that sequences agent work holds acknowledged steps, so that command always exits 1. For
the narrower question — were the mechanical steps already run against this tree, so a caller can
skip them — use `pipeline:verify --server-verified`. It counts only server verdicts on a complete
walk, and names the ids it counted, because exit 0 never said which checks ran.

Whether it can answer at all depends on your step order. A walk whose mechanical steps sit ahead of
the acknowledged ones reaches `complete` routinely; one that puts a slow or failure-prone step first
does not, so the flag goes quiet exactly when you wanted an answer. That is a property of the
pipeline you built rather than a guarantee, and reordering can take it away without anything saying
so.

Because this flag exists so a caller can SKIP work, it refuses anything it cannot answer, where the
bare call would shrug. A receipt that predates a field it needs is refused; so is one whose recorded
declaration this version cannot reproduce. Both mean the same thing (this run cannot say what it
walked), and both clear themselves on the next run. Expect one refusal per pipeline the first time
you call it after upgrading.

## Reading what the runs did

`pipeline:verify` gates. `php artisan pipeline:history` reports, and the two read different
records to answer different questions — verify reads the current receipt and answers whether the
tree on disk is verified; history reads the recorded runs and the in-flight record and answers
what the recent walks did.

```bash
php artisan pipeline:history                    # the recent runs, newest first
php artisan pipeline:history --run=r-4f2a       # one run, step by step
php artisan pipeline:history --pipeline=release --limit=5
```

It exits 0 for every answer it can give — an empty history, a stale run, a failed run. Only a
question it cannot answer exits non-zero: an unknown pipeline, a missing `--pipeline` where
several are declared, an unknown run id, a `--limit` that is not a positive integer. Keeping that
apart from `pipeline:verify` is deliberate: a reporting command with gate-like exit codes gets
wired into a hook where it blocks on a question it never asked.

A run opened through the MCP tools is recorded to
`storage/logs/pipeline/history/<pipeline>/<run-id>.json`, and the position being worked writes
`storage/logs/pipeline/live/<pipeline>.json` while it runs. Both are written whether or not you
serve the page below, and both stores are optional dependencies of a run — so code that builds a
`Run` by hand records nothing unless it passes them. A run id reaches that filename through the
same encoding a step log uses, which appends a short digest, so the name is not the id verbatim.
History keeps the newest 20 runs per pipeline and
prunes the rest on write; step logs are not pruned.

**The steps come from the config as it stands now, not from the record.** Nothing stores the step
list a past run walked. So a run recorded before you edited the pipeline shows a step you added
since as never run, and reports a verdict whose step you removed as no longer declared. That is
the honest rendering of two facts, not a fault.

## The page

A page at `{app}/boost-pipelines` showing every declared pipeline, the run in flight and the runs
before it. It polls, so you watch a walk progress without reading the agent's transcript.

Off by default, and behind three gates:

```php
// config/boost-pipeline.php
use SanderMuller\BoostPipeline\Http\LoopbackOnly;

return [
    'ui' => [
        'enabled' => env('BOOST_PIPELINE_UI', false),
        'path' => 'boost-pipelines',
        'middleware' => ['web', LoopbackOnly::class],
    ],
];
```

Its defaults are merged, so the page stays off until you say otherwise. Publish it with
`php artisan vendor:publish --tag=boost-pipeline-config` when you want to change one.

The routes register only when `enabled` is true **and** the environment is local. Neither of those
is access control — `APP_ENV=local` describes the application, not the requester, and a local
server routinely listens on a LAN address or behind a tunnel. So `LoopbackOnly` ships in the
default middleware and refuses a request from anywhere but this machine. It reads `REMOTE_ADDR`
rather than `Request::ip()`, because the latter returns the `X-Forwarded-For` value on an app that
trusts proxies, and a header must not decide whether the page is open. Replace it deliberately if
you need the page reachable from another machine.

**What polling can and cannot show.** The receipt only lands when a position resolves, so a page
reading it alone would freeze for the length of a 900-second step. The live record fills that gap:
it names the steps at the current position and when they started. A `running` record older than
the ceiling its runner enforces reads as interrupted, because that runner kills a step at the
timeout. An `awaiting` record never expires — the package does not time out a skill step, so the
page reports how long it has waited rather than inventing a limit. An awaiting record left behind
by a killed server is the one case nothing can detect.

A step's log expands in place, read from the path that run recorded rather than one derived from
its ids — a consumer that binds its own `StepRunner` may write logs anywhere, or nowhere. A
recorded path is served only when it resolves inside the log directory. Command output is
untrusted, so the page writes it with `textContent` and never as markup.

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

Usually the cheaper fix is to remove the scope instead. `yarn lint-all` cannot pass vacuously, needs
no second command to enumerate what it will check, and is what most projects want from a gate
anyway. Reach for `inspecting()` when the scoped command is the one you actually mean to run — a
slow suite you deliberately narrow — rather than as the default answer to the trap.

## Extending

A phase is a name and a position, so a custom one costs no machinery. Implement `Phase`, then place
it with `->withPhases(fn (Phases $phases) =>
$phases->append(BlastRadius::class)->after(Tests::class))`.
`append()`, `prepend()`, `->after()` and `remove()` are the whole vocabulary.

`StepRunner` is the other seam. Bind your own over the container's
(`$this->app->singleton(StepRunner::class, fn () => new MyRunner)`) and every step goes through it.

## Notes

Each step runs in a subprocess with every key your app's `.env` **defines** removed, so the child
re-reads `.env` rather than inheriting resolved values. This is not a sandbox: anything exported in
the parent shell reaches every step command.

`withEnv()` pins a value for one step, which is what the scrubbing above is for: a test step must
set its own `DB_DATABASE` rather than inherit whatever the app booted with. The value most likely to
need pinning is also the one a literal gets wrong — several checkouts on one database server collide
on a committed name — so derive it:

```php
$database = substr('myapp_phpunit_'.preg_replace('/[^A-Za-z0-9_]/', '_', basename(base_path())),
0, 64);

$steps->in(Tests::class)->append(
    Shell::run('php artisan test', id: 'phpunit')->withEnv(['DB_DATABASE' => $database]),
);
```

The config is real PHP, so anything you can compute is available. Keep it deterministic — the same
checkout must produce the same value on every run, or steps stop sharing the resource they set up.

There is no step-output mechanism, deliberately. Use command substitution inside one step, or have
one step write a file (`--json`) that a later step reads. Never parse another step's stdout — that
is the pattern GitHub Actions deprecated as a security vulnerability.

Every step writes its full output to `storage/logs/pipeline/<run>-<step>.log`, with a short digest
after each id — `r-4f2a-a1b2c3-pint-d4e5f6.log` — so that two ids differing only in characters the
filename cannot hold never write to one file. Nothing prunes that
directory, and deleting it is safe.

**The receipts under it are not logs.** `storage/logs/pipeline/receipts/` sits inside a directory
whose name says disposable, and a Laravel app clearing `storage/logs/` as routine maintenance takes
the receipts with it. Nothing breaks — the tree simply reads as unverified until the next run — but
clear the `*.log` files rather than the directory if you want to keep the answer.

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
| Notice a walk abandoned while awaiting a skill  | The live record has no timeout to expire against, so it reports the wait |
| Know which checks your pipeline ought to hold   | Exit 0 reports on the steps that ran, and nothing more                  |
| Fingerprint a step type you wrote yourself       | A custom `Step` is compared on its contract alone      |

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
