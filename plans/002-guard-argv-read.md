# Plan 002: Stop a web request from reading `$_SERVER['argv']` unconditionally

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a05b7fa..HEAD -- src/Runner/ConsoleServerProcess.php tests/Pest.php`
> If either file changed since this plan was written, compare the "Current
> state" excerpts against the live code before proceeding; on a mismatch,
> treat it as a STOP condition. (`tests/Pest.php` matters because the test
> placement below depends on its suite binding.)

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `a05b7fa`, 2026-08-25

## Why this matters

`ConsoleServerProcess::isStarting()` reads `$_SERVER['argv']` before it checks `runningInConsole()`. The provider's `boot()` calls `isStarting()` on every request of any consumer app where `.config/pipeline.php` exists (`src/BoostPipelineServiceProvider.php`, the block commented "Narrower than runningInConsole() on purpose"). With `register_argc_argv = Off` — the `php.ini-production` default, so typical FPM deployments — `$_SERVER['argv']` is absent. The undefined-key warning becomes an `ErrorException` under Laravel's `HandleExceptions`, thrown from provider boot: a 500 on every web request, from a package that is supposed to be inert outside the MCP server process.

## Current state

`src/Runner/ConsoleServerProcess.php` in full (the class body):

```php
final readonly class ConsoleServerProcess implements ServerProcess
{
    public function __construct(private Application $app) {}

    public function isStarting(): bool
    {
        $argv = $_SERVER['argv'];

        return $this->app->runningInConsole()
            && is_array($argv)
            && ($argv[1] ?? null) === 'mcp:start';
    }
}
```

The `$_SERVER['argv']` read on the first line executes before the `runningInConsole()` short-circuit and without a null-coalesce, so an absent key warns.

The contract's docblock (`src/Contracts/ServerProcess.php`) carries a constraint that matters for the test:

```php
 * Behind a contract so a test can substitute it. The alternative is mutating
 * `$_SERVER['argv']`, and that global is shared with the agent output formatter,
 * which reads and rewrites it — changing it mid-suite corrupts state that has
 * nothing to do with this question.
```

So the test MUST save and restore `$_SERVER['argv']` around every mutation, in a `try`/`finally`.

**Test placement — this is load-bearing.** `tests/Pest.php` binds the Testbench `TestCase` to the Feature suite ONLY: `pest()->extend(TestCase::class)->in('Feature');`. A file under `tests/Unit` runs on bare PHPUnit with no `$this->app`. The test therefore goes in `tests/Feature/ConsoleServerProcessTest.php`, where `$this->app` is the booted Testbench application and `runningInConsole()` is `true`.

Also useful: `phpunit.xml` sets `failOnWarning="true"` and `<source restrictNotices="true" restrictWarnings="true">` covering `src/`, so the undefined-array-key warning raised inside `src/Runner/ConsoleServerProcess.php` is a hard test failure — the reproduction in step 1 works without relying on Laravel's error handler.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full gate | `composer qa-check` | exit 0 |
| Tests only | `vendor/bin/pest` | all pass |
| One file | `vendor/bin/pest tests/Feature/ConsoleServerProcessTest.php` | all pass |

## Scope

**In scope** (the only files you should modify):
- `src/Runner/ConsoleServerProcess.php`
- `tests/Feature/ConsoleServerProcessTest.php` (create)
- `plans/README.md` (status row for this plan only)

**Out of scope** (do NOT touch, even though they look related):
- `src/BoostPipelineServiceProvider.php` — the call site and its narrowing comment are correct as they stand.
- `src/Contracts/ServerProcess.php` — the contract is stable public API.

## Git workflow

- Branch from `main`: `fix/argv-guard`
- Commit style: plain imperative sentence, no conventional-commit prefix (see `git log --oneline`).
- Commit signing is enabled (`commit.gpgsign true`, ssh format). If signing fails, STOP and report — never commit unsigned.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Write the failing test

Create `tests/Feature/ConsoleServerProcessTest.php` (Pest style; as a Feature test it runs on the Testbench `TestCase` — see "Test placement" above — so `$this->app` exists). Cases:

1. `argv` absent: unset `$_SERVER['argv']` (after saving it), assert `isStarting()` returns `false` and raises no warning/exception.
2. `argv[1] === 'mcp:start'` in a console app: returns `true`.
3. `argv[1]` is another artisan command (for example `'test'`): returns `false`.
4. `argv` present but not an array (set it to a string): returns `false`. (A characterisation case — it also passes against the pre-fix code; only case 1 is the regression test.)

Construct the class with the Testbench app: `new ConsoleServerProcess($this->app)` — Testbench runs in console, so `runningInConsole()` is `true` in the suite. Every case that touches `$_SERVER['argv']` restores the original value in `finally` (see the contract docblock quoted above for why).

**Verify**: `vendor/bin/pest tests/Feature/ConsoleServerProcessTest.php` → case 1 FAILS (PHPUnit converts the undefined-array-key warning to a failure under `failOnWarning`/`restrictWarnings`) while the others pass. This proves the reproduction.

### Step 2: Reorder the guard

Change `isStarting()` to check the app first and null-coalesce the global:

```php
    public function isStarting(): bool
    {
        if (! $this->app->runningInConsole()) {
            return false;
        }

        $argv = $_SERVER['argv'] ?? null;

        return is_array($argv)
            && ($argv[1] ?? null) === 'mcp:start';
    }
```

**Verify**: `vendor/bin/pest tests/Feature/ConsoleServerProcessTest.php` → all 4 pass.

## Test plan

Covered in step 1. Verification: `vendor/bin/pest` → all pass, including 4 new tests.

## Done criteria

- [ ] `composer qa-check` exits 0
- [ ] `tests/Feature/ConsoleServerProcessTest.php` exists; its argv-absent case fails against the old code and passes against the new
- [ ] `grep -n "\$_SERVER\['argv'\];" src/Runner/ConsoleServerProcess.php` returns no match (only the null-coalesced read remains)
- [ ] The guard order holds: `grep -A3 'function isStarting' src/Runner/ConsoleServerProcess.php` → the first statement inside the method is the `runningInConsole()` check
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The live `ConsoleServerProcess.php` or the `tests/Pest.php` suite binding no longer matches the excerpts.
- Restoring `$_SERVER['argv']` proves insufficient and other tests start failing — the shared-global warning in the contract docblock is biting; report rather than adding workarounds.

## Maintenance notes

- Anyone adding logic to `isStarting()` must keep the `runningInConsole()` check first and never read `$_SERVER['argv']` without `?? null`.
- Reviewer should scrutinize: the test's save/restore discipline (the docblock warning about the shared global), and that the reorder cannot change behaviour for the real `mcp:start` path (it cannot: console + argv present is unchanged).
- Deferred follow-up (out of this plan): a provider-level test booting with `argv` unset would pin the full path from `boot()`; skipped here because the unit seam exists precisely for this.
