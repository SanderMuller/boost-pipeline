# `laravel/mcp` notes

Facts about the dependency that cost time to establish, each verified against
`vendor/laravel/mcp` at **v0.9.4**. Read this before changing anything in `src/Mcp/` or writing
a test that goes through the server.

## It is pre-1.0, and the design leans on its 0.x surface

`laravel/mcp` is `v0.9.4`. It is a direct requirement here, constrained to `^0.9.4`, but a 0.x
minor may move any of these without ceremony:

`Registrar::local()` · `Server` · `Server\Tool` · `Server\Prompt` · `Response::error()` ·
`Response::structured()` · `Tool::annotations()` · `Tool::shouldRegister()` ·
`Tool::outputSchema()` · `Server\Testing\{PendingTestResponse,TestResponse}`

`Tool::shouldRegister()` is not a checkable symbol: it does not exist on the base `Tool` class
(`method_exists` is `false`). It is an optional hook a consumer tool may define, dispatched
reflectively by `Server\Primitive`. The design leans on that dispatch convention, but there is
nothing to `method_exists`-check for it, so it is absent from the guard below.

`SanderMuller\BoostPipeline\Mcp\McpSurface::firstMissingProduction()` checks the rest of this
list — `class_exists` per class, `method_exists` per `[class, method]` pair — at boot, before
`Mcp::local()` runs. `Server\Testing\*` is dev-only and is not part of that check; it is never
touched at boot. When a symbol is missing, the service provider writes one line to stderr
(never stdout — that is the JSON-RPC channel) via `writeToStderr()` and declines registration
entirely, including the `InvalidConfigServer` fallback, which needs the same MCP surface. If a
bump breaks the design rather than a signature, that stderr line is a stop-and-report, not a
work-around-it.

The package is registered with `Mcp::local('pipeline', PipelineServer::class)` from this
package's service provider — `Registrar::local(string $handle, string $serverClass)` at
`vendor/laravel/mcp/src/Server/Registrar.php:71`. There is no `routes/ai.php` in the consuming
repo. The entry point is `php artisan mcp:start pipeline`, which is `laravel/mcp`'s own
`StartCommand`.

## Tool names default to kebab-case

`Primitive::name()` falls back to `Str::kebab(class_basename($this))`. So `class OpenRun` is
exposed as **`open-run`**, not `open_run`.

This shipped as a real defect. The spec contract, all four tool descriptions and the driver
prompt said `open_run` / `next_step`, while the server exposed `open-run` / `next-step` — the
prompt was instructing the agent to call tools that did not exist. Every tool now pins
`protected string $name` explicitly, and a test asserts the four names.

Unit tests could not catch this. Only starting the real server and listing its tools did.

## `Response::error()` versus `Response::structured()`

The mapping is easy to get backwards, and getting it backwards makes every failing check look
like a broken server.

| Verdict | Response | Why |
|---|---|---|
| `passed` | `Response::structured([...])` | The call succeeded, the check passed |
| `failed` | `Response::structured([...])` | The call **succeeded**. The check found problems. Not an `isError` |
| `error` | `Response::error(...)` | The tool call itself could not complete |
| `acknowledged` | `Response::structured([...])` | Succeeded, unverified |

`Response::error()` sets the protocol-level `isError: true`, which invites the client to treat
the call as broken and retry it. A failing PHPStan run is not a broken call.

Two encoding details that broke test assertions:

- **`Response::structured()` returns a `ResponseFactory`, not a `Response`.** `Server\ToolInvoker`
  accepts either, and `Tool::handle()` is duck-typed, so the tools here declare
  `Response|ResponseFactory`. Calling `->toArray()[0]` on the result does not work.
- **It encodes with `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`**
  (`vendor/laravel/mcp/src/Response.php:97`), so an assertion containing an escaped slash will
  not match.

## Arguments arrive empty in tests

**Symptom:** `PipelineServer::tool(ReportStep::class, ['summary' => 'x'])` reaches the tool, but
`$request->get('summary')` is `null`.

**Cause:** arguments reach a tool through exactly one path. `Server::runMethodHandle()` binds the
request as the container instance `mcp.request`
(`vendor/laravel/mcp/src/Server.php:297`), and `McpServiceProvider::registerContainerCallbacks()`
registers a `resolving(Request::class)` callback that copies the arguments off that binding into
each freshly resolved `Request`. `ToolInvoker` then calls the handler through
`Container::call()`, so the `Request` it injects is only populated if that callback exists.

Testbench boots **only** the providers `getPackageProviders()` names. Composer auto-discovery of
a *dependency's* service provider does not happen, so `McpServiceProvider` was never booted, the
resolving callback was never registered, and every tool saw an empty `Request`.

**Fix:** list it in `tests/TestCase.php`.

```php
protected function getPackageProviders($app): array
{
    return [McpServiceProvider::class, BoostPipelineServiceProvider::class];
}
```

Worth recording because the wrong diagnosis is plausible and was believed for a while: that the
`Server\Testing` harness never binds `mcp.request`. It does — `runMethodHandle` is on every
path, the test harness included. The harness is fine. Verified by probe: the provider was not in
the loaded set before the fix, and the same call delivers arguments after it.

The consequence is general, not specific to arguments. Any `laravel/mcp` behaviour that lives in
`McpServiceProvider` is absent from a Testbench suite that does not list it.

## Timeouts have two ceilings, and the inner one must be lower

`.mcp.json` pins the client's per-call wall clock at `600000` ms. `ProcessStepRunner`'s
`DEFAULT_TIMEOUT_SECONDS` is `540.0`, deliberately below it: if the step outlives the client's
limit, the client kills the call and **the verdict is lost** — which is worse than a recorded
timeout, because a lost verdict leaves the run wedged rather than halted with a reason.

Keep the runner's default under the client's limit. The 600s figure itself was sized against a
measured 31.5s warm PHPStan run.

Related: exit codes **126** and **127** (not executable, not found) map to `Verdict::Error`
rather than `failed`, along with the timeout and thrown-exception paths.

## Output limits

MCP output warns at 10,000 tokens and caps at 25,000. Every shell step writes its full output to
`storage_path('pipeline/logs/<run>-<step>.log')` and returns a deterministic truncation plus the
log path. `Server::$defaultPaginationLength` in `laravel/boost` is the reminder that pagination
is the alternative if truncation costs the agent too much.

`anthropic/maxResultSizeChars` goes on `next_step`, which is the tool carrying the large
payloads. `readOnlyHint` goes on `open_run` and `status`.

## Prompts are user-invoked, tools are model-invoked

`Laravel\Mcp\Server\Prompt` surfaces in Claude Code as a slash command,
`/mcp__<server>__<prompt>`, with space-separated arguments. That is what `RunPipeline` is.

The consequence is easy to forget: the **model** cannot start the flow by "running the prompt".
It never needed to — `open_run` is a tool it can call directly. The prompt is for the human's
convenience.

If a prompt template ever needs to contain code examples, read
`laravel/boost`'s `RendersBladeGuidelines` first: Blade mangles backticks, `<?php`,
`@directives` and `<x-` component tags unless they are placeholder-swapped.
