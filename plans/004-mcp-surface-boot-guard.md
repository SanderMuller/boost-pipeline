# Plan 004: Add the `class_exists` boot guard the design record says already exists

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a05b7fa..HEAD -- src/BoostPipelineServiceProvider.php .ai/docs/laravel-mcp-notes.md`
> On any change since this plan was written, compare the "Current state"
> excerpts against the live code; on a mismatch, STOP.

## Status

- **Priority**: P1
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: bug
- **Planned at**: commit `a05b7fa`, 2026-08-25

## Why this matters

`.ai/docs/laravel-mcp-notes.md` states: "The service provider verifies the load-bearing classes exist at boot with `class_exists`." No such check exists — `grep -rn "class_exists" src/` returns nothing. The package pins `laravel/mcp: ^0.9.4`, a 0.x line whose minors "may move any of these without ceremony" (same doc). A moved symbol currently produces a raw PHP fatal on the stdio stream — exactly the unparseable-to-a-client failure mode the `InvalidConfigServer` fallback was built to close for config errors (one MCP driver hung on such output). The doc being wrong is its own cost: a future reader believes the guard is there and does not add it.

## Current state

The registration gate in `src/BoostPipelineServiceProvider.php` `boot()` (around lines 194-215; the comment text locates it):

```php
        // Narrower than runningInConsole() on purpose: ...
        if ($this->app->make(ServerProcess::class)->isStarting()) {
            $reason = $this->configError();

            if ($reason !== null) {
                // Register something, rather than nothing. Declining left
                // `mcp:start` writing "server not found" to stdout — the JSON-RPC
                // channel for a stdio server — which is unparseable to a client, ...
                $this->app->instance(ConfigError::class, new ConfigError($reason));

                Mcp::local(self::HANDLE, InvalidConfigServer::class);
```

The load-bearing 0.x surface, from `.ai/docs/laravel-mcp-notes.md`:

> `Registrar::local()` · `Server` · `Server\Tool` · `Server\Prompt` · `Response::error()` ·
> `Response::structured()` · `Tool::annotations()` · `Tool::shouldRegister()` ·
> `Tool::outputSchema()` · `Server\Testing\{PendingTestResponse,TestResponse}`

`Server\Testing\*` is dev-only — do NOT guard it at boot. The runtime set to guard (each verified against the installed `vendor/laravel/mcp` at planning time):

- classes: `Laravel\Mcp\Server`, `Laravel\Mcp\Server\Tool`, `Laravel\Mcp\Server\Prompt`, `Laravel\Mcp\Response`, `Laravel\Mcp\Server\Registrar`, `Laravel\Mcp\Facades\Mcp` (the facade the provider actually calls — see the second trap below)
- methods (as `[class, method]` pairs on those classes): `Response::error`, `Response::structured`, `Tool::annotations` (inherited from `Server\Concerns\HasAnnotations` — `method_exists` is true), `Tool::outputSchema`, `Registrar::local`

Two traps, both verified:

- Do NOT guard `Tool::shouldRegister` — it does NOT exist on the base `Tool` class (`method_exists` is `false`). It is an optional consumer hook dispatched reflectively at `vendor/laravel/mcp/src/Server/Primitive.php:93-94`; this repo supplies its own in `src/Mcp/Tools/Concerns/PipelineTool.php`. The notes doc lists it in the load-bearing set anyway — that listing is about the DESIGN depending on the hook's dispatch convention, not about a checkable method. Guarding it makes the test in step 3 fail against a correctly installed vendor.
- Guard the `Laravel\Mcp\Facades\Mcp` CLASS — the provider calls `Mcp::local(...)` through the facade (`src/BoostPipelineServiceProvider.php:226,232`), so a moved facade class fatals even when `Registrar` survives. But do NOT put its `local` in the METHOD list: `local` is a `@method` docblock over `Facade::__callStatic`, so `method_exists` is `false` there. Guard `Server\Registrar::local` as the real method, and the facade as a class only.
  (This distinction was wrong in the first version of this plan — it excluded the facade entirely, and an external review caught the resulting gap after the work merged. Fixed in commit `fix/guard-mcp-facade`.)

Key design constraint: if the MCP surface itself is broken, the `InvalidConfigServer` fallback is NOT safe to register — it goes through the same `Mcp::local()` and `Response` API. So the guard must write one line to stderr (never stdout — that is the JSON-RPC channel) and decline registration entirely. This is a deliberate exception to the "register something rather than nothing" comment, because that comment assumes `Mcp::local` works.

The provider ALREADY has the stderr mechanism: `BoostPipelineServiceProvider::writeToStderr()` at `src/BoostPipelineServiceProvider.php:261-277`. Its comment explains why raw `fwrite(STDERR, ...)` is unsafe here (`STDERR` is absent outside the CLI SAPI, and `runningInConsole()` can be true without it via `APP_RUNNING_IN_CONSOLE`); it guards with `defined('STDERR')`, an `@fopen('php://stderr', 'w')` fallback, and `@fwrite`. Use it — do not write a second stderr path. The existing call site at line 253 shows the message convention: a `[boost-pipeline] ` prefix, and `writeToStderr()` appends `PHP_EOL` itself.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full gate | `composer qa-check` | exit 0 |
| Tests only | `vendor/bin/pest` | all pass |
| Static analysis | `composer phpstan` | 0 errors |

## Scope

**In scope** (the only files you should modify):
- `src/BoostPipelineServiceProvider.php`
- `src/Mcp/McpSurface.php` (create — the new check class from step 1)
- `tests/Unit/McpSurfaceTest.php` (create)
- `.ai/docs/laravel-mcp-notes.md` — correct the sentence to describe what ships
- `plans/README.md` (status row for this plan only)

**Out of scope** (do NOT touch, even though they look related):
- The existing files in `src/Mcp/` (tools, servers, `StepPayload`) — no tool or server changes; the only addition there is the new `McpSurface.php`.
- `composer.json` — the constraint stays `^0.9.4`; widening it is a separate lead recorded under "Audited, not planned" in plans/README.md.
- `InvalidConfigServer` / `ConfigError` — the config-error path is separate and stays as is.

## Git workflow

- Branch from `main`: `fix/mcp-surface-guard`
- Commit style: plain imperative sentence (see `git log --oneline`).
- Commit signing is enabled (ssh). If signing fails, STOP — never commit unsigned.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Build a testable surface check

Create a small final class at `src/Mcp/McpSurface.php` with one static method taking the symbol lists as parameters so a unit test can probe it with fake names:

```php
/**
 * @param list<string> $classes
 * @param list<array{string, string}> $methods  [class, method] pairs
 */
public static function firstMissing(array $classes, array $methods): ?string
```

(`list<string>`, deliberately NOT `class-string`: the tests pass names that do not resolve — that is the point of the check.)

Return the first missing symbol's name (`"Laravel\Mcp\Response"` or `"Laravel\Mcp\Response::structured"`), or `null` when the surface is intact. Implementation: `class_exists` per class, `method_exists` per pair. Also give it a constant holding the real production lists from Current state above (already verified against the installed vendor — note the two traps listed there) and a zero-argument convenience wrapper that checks that constant.

**Verify**: `composer phpstan` → 0 errors.

### Step 2: Wire it into `boot()`

In `src/BoostPipelineServiceProvider.php`, inside the `isStarting()` block and BEFORE `configError()` runs (the insert point is unambiguous: `isStarting()` is checked at line 203, `$reason = $this->configError();` at line 204 — the guard goes between them), add:

```php
$missing = McpSurface::firstMissingProduction(); // the zero-argument wrapper from step 1

if ($missing !== null) {
    // Cannot register even the invalid-config fallback: it needs the same
    // MCP surface. Stderr, never stdout — stdout is the JSON-RPC channel.
    $this->writeToStderr('[boost-pipeline] laravel/mcp is missing ['.$missing.']; the pipeline server was not registered. Check the installed laravel/mcp version.');

    return;
}
```

Use the EXISTING `writeToStderr()` helper (see Current state) — no `sprintf`, no `PHP_EOL` (the helper appends it), no raw `fwrite(STDERR, ...)`. Match the surrounding comment density and style.

**Verify**: `vendor/bin/pest` → all existing tests still pass (the guard is a no-op with the real vendor present).

### Step 3: Test the check

New Pest unit test (model on `tests/Unit/CommandPreflightTest.php` structure):

1. `firstMissing([], [])` → `null`.
2. A real existing class and method → `null`.
3. A fake class name → returns that name.
4. A real class with a fake method → returns `"Class::method"`.
5. The production constant, checked against the installed vendor → `null` (this is the living pin: it fails on the next `laravel/mcp` bump that moves a symbol, which is the point).

**Verify**: `vendor/bin/pest tests/Unit/<NewTest>.php` → all pass.

### Step 4: Correct the design record

In `.ai/docs/laravel-mcp-notes.md`, update the sentence "The service provider verifies the load-bearing classes exist at boot with `class_exists`." to name the real mechanism (the check class, the stderr-and-decline behaviour, and that `Server\Testing\*` is not guarded at boot). Also add a note beside the load-bearing list that `Tool::shouldRegister()` is an optional reflective hook, absent from the base class — the design leans on its dispatch convention, but it is not a `method_exists`-checkable symbol.

**Verify**: `grep -n 'class_exists' .ai/docs/laravel-mcp-notes.md` → the line now describes the shipped mechanism.

## Test plan

Covered in step 3. Verification: `vendor/bin/pest` → all pass, including the 5 new cases.

## Done criteria

- [ ] `composer qa-check` exits 0
- [ ] `grep -rn "class_exists" src/` now returns the guard
- [ ] The production-list test (step 3, case 5) passes against the installed `laravel/mcp`
- [ ] `.ai/docs/laravel-mcp-notes.md` matches the implementation
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The `boot()` code or the `writeToStderr()` helper no longer matches the excerpts (drift).
- A class or method from the production list in Current state fails its existence check against the installed `vendor/laravel/mcp` — the vendor moved since planning; report the mismatch, do not guess a replacement symbol.
- You feel the need to register `InvalidConfigServer` on a missing symbol instead — that is explicitly wrong here (it needs the same surface); the design constraint is decline-plus-stderr via the existing helper.

## Maintenance notes

- The production symbol list is a maintained pin: every `laravel/mcp` bump should re-run the suite, and case 5 fails loudly when the surface moved. When the `laravel/mcp` 1.0 migration (see "Audited, not planned" in plans/README.md) is executed, this list is the checklist for the migration.
- Reviewer should scrutinize: nothing is written to stdout on the failure path, and the guard runs only inside `isStarting()` (never on ordinary web/console requests).
