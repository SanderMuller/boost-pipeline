# Restart the server after a vendor change without a client reconnect

<!-- spec:planned-at d08b7a9c81fb8e57acf8d3e73c59b48f10ba9e5f 2026-11-18 -->

## Overview

`open_run` refuses when a package that autoloads code changed since the server started, and the
only recovery is a client reconnect (`/mcp` in Claude Code). A `composer install` after a merge or
a branch switch is enough to trigger it, so the refusal comes often.

It also makes `open_run` refuse when `vendor/` does not match `composer.lock`, whether or not
anything changed since the server started. A run then never verifies against dependencies that the
lock file no longer names.

The main change is a small supervisor process between the client and the server. When the server finds a
vendor change at `open_run` and no step is running, it exits with a dedicated code. The supervisor
starts a new server and resends the pending request. The client gets a normal answer on the same
connection.

## Assumptions

Each item says how it was settled: a test run in this session, source read in this session,
documentation, reasoning only, or a choice the user has not confirmed.

- **The guard is correct, and only the reconnect is the problem** (reports and source). Every
  traced refusal followed a real dependency change (section 1).
- **A narrower guard does not help** (test). In a typical Laravel app the server loads files from
  most vendor packages, and a usual upgrade touches at least one loaded package. See "Approaches
  considered".
- **The server detects the change, not the supervisor** (source). Only the server knows whether a
  background step runs (`Run::isPending()`, `src/Run/Run.php:269`). The supervisor cannot see it.
- **The check runs at `open_run` only** (source). `next_step` and `status` never reload, by design
  (`src/Run/RunManager.php:154-157`). A restart there would drop the walk under its cursor.
- **A running background step blocks the restart** (source). `open()` already refuses to interrupt
  a pending step (`src/Run/RunManager.php:82-88`). The refusal stays, with a "wait for the step"
  message.
- **A restart loses open runs** (source). Run state lives only in the server process
  (`README.md:321-325`), keyed by pipeline name. Today a refusal keeps the runs of other pipelines,
  so a restart can lose more than a refusal does. The server therefore restarts only when no run is
  in progress in any pipeline (section 3), and the first answer after a restart says that earlier
  runs are gone. Every traced refusal came at the first `open_run` of a new walk.
- **Steps must not inherit the supervisor flag** (source). `EnvironmentScrubber::forStep()` removes
  only the `.env` keys (`src/Runner/EnvironmentScrubber.php:34-44`). A step that starts its own
  `mcp:start`, for example in a test suite, would see the flag and exit 75 with no supervisor. The
  scrubber removes the flag too.
- **`open_run` refuses when `vendor/` does not match `composer.lock`** (user decision, 2026-11-18).
  This holds on every `open_run`, supervised or not, and with or without a vendor change since start.
  Composer writes `installed.json` after each package operation, so a mismatch means an install is
  still running or did not run. Without the check, a run opened after a merge that changed the lock
  with no install verifies against old dependencies. The refusal says to run or wait for `composer install`, then
  call `open_run` again. Measured cost: 2.4 ms per check on this repository (171 packages). In a Laravel app it also refuses while `bootstrap/cache/packages.php` is older
  than `installed.json`, because `package:discover` runs after the autoloader dump. See section 3.
- **mtimes of Composer files are no signal** (source, Composer 2.11.1).
  Composer writes `installed.json`, `installed.php` and the autoload files only when their content
  changes (`JsonFile.php:166`, `FilesystemRepository.php:183`, `AutoloadGenerator.php:442-472`).
  `PackageManifest::write()` in Laravel rewrites `bootstrap/cache/packages.php` on every run
  (`PackageManifest.php:182`).
- **No handshake replay** (test). A fresh server answered `open_run` with no `initialize`
  (section 1). The `main` branch of `laravel/mcp` also has no handshake check (source).
- **One request at a time** (source). `StdioTransport::run()` handles each line before it reads the
  next, in v0.9.4 and on `main`.
- **`exit(75)` inside `open_run` writes nothing** (test, v0.9.4). A config that calls `exit(75)` on
  reload made the server exit with code 75 and write no line for that request.
- **Exit code `75`** (convention). `EX_TEMPFAIL` from `sysexits.h`: a temporary failure, try again.
- **A restart can happen during an install** (source and reports). Composer writes
  `installed.json` after each package operation, and once more at the end
  (`composer/composer` `src/Composer/Installer/InstallationManager.php`, `executeBatch()` and
  `execute()`). It dumps the autoloader after that, then runs `post-autoload-dump` and
  `post-install-cmd` scripts (`src/Composer/Installer.php`, `run()`). Read on 2026-11-18. A
  background refresh can take minutes, and the scripts can fail after all packages are in place.
- **The supervisor retries a restart that fails early** (choice). A new
  server that exits before its first response, or exits 75 again, gets another start after a delay,
  15 seconds of delay in total, plus the boot time of each try. After that the pending requests get an error that says to wait
  and call `open_run` again, not to reconnect.
- **Windows: no restarts** (documentation). The PHP manual for `stream_select()` says: "Use of
  stream_select() on file descriptors returned by proc_open() will fail and return false under
  Windows."
- **The Composer bin proxy loads no autoloader** (source). `vendor/bin/pint` only sets
  `$GLOBALS['_composer_autoload_path']` and `include`s the target script.
- **The supervisor is opt-in** (choice). Consumers change `.mcp.json` to use it; the README install
  snippet shows it. A server started without it keeps today's refusal, with a hint to the supervisor.
- **The supervisor and the server share a protocol version** (choice). An update of this package
  can give a new server under an old running supervisor. The flag carries the version
  (`BOOST_PIPELINE_SUPERVISED=1`), and the server treats an unknown value as "not supervised".
- **Claude Code acts on `list_changed` only when the server declares it** (test). Claude Code
  2.1.327 in print mode, against a fake stdio server: with `capabilities.tools.listChanged: true`,
  a `notifications/tools/list_changed` sent during a tool call made it fetch `tools/list` again, and
  it then called the new tool. With `listChanged: false` it ignored the notification. Interactive
  mode was not tested; the documentation says it fetches the lists again.
- **`laravel/mcp` can send the notification** (source). A tool that returns a generator can
  `yield Response::notification(...)` before its result
  (`vendor/laravel/mcp/src/Server/Methods/Concerns/InteractsWithResponses.php:51-80`), and
  `Server::addCapability()` sets `tools.listChanged`.
- **A server boot is short compared with a walk** (test). Cold start to the first `open_run` answer:
  159 ms median in this repository under Testbench (5 runs). A full Laravel app boots slower, still
  well under a second per restart.

---

## 1. Current State

- `RunManager::open()` calls `reloadChangedConfig()` first (`src/Run/RunManager.php:68`), which
  calls `ConfigReloader::refresh()` (`src/Run/RunManager.php:160`).
- `refresh()` compares the autoloading packages in `vendor/composer/installed.json` with the
  snapshot taken in the constructor, and throws `InvalidPipelineConfigException::vendorChanged()`
  when they differ (`src/Config/ConfigReloader.php:108-110`).
- `OpenRun::handle()` turns that exception into `Response::error()` (`src/Mcp/Tools/OpenRun.php:72-74`).
  The message tells the agent to reconnect (`src/Exceptions/InvalidPipelineConfigException.php:145-148`).
- The server is `php artisan mcp:start pipeline` (`README.md:43`). `laravel/mcp`'s
  `StdioTransport::run()` reads one line from STDIN and handles it to completion before it reads
  the next (`vendor/laravel/mcp/src/Server/Transport/StdioTransport.php:34-48`). So at most one
  request is in progress in the server at a time. Requests the client sends meanwhile wait unread
  in the pipe.
- Run state lives only in the server process (`README.md:321-325`). A restart loses every open run,
  the same as a reconnect does today. Receipts and history stay on disk.
- A run can hold a step that is still running in the background (`Run::isPending()`,
  `src/Run/Run.php:269`). `open()` already refuses to interrupt one (`src/Run/RunManager.php:82-88`).
- `laravel/mcp` declares `listChanged: false` for tools, prompts and resources
  (`vendor/laravel/mcp/src/Server.php:78-84`).
- `laravel/mcp` keeps no session state between messages. `Server::handle()` builds a new context for
  each message, drops every notification, and does not check for a prior `initialize` before
  `tools/call` (`vendor/laravel/mcp/src/Server.php:183-200`, v0.9.4). The `main` branch on GitHub
  also has no handshake check. It validates a protocol `_meta` on each request instead, and treats
  `initialize` as an unknown method for requests on the newer protocol revision (read on
  2026-11-18, not pinned to a release).
- Claude Code does not restart a stdio server that exits in an interactive session. It marks the
  server as failed until the user selects Reconnect in `/mcp`. Only `claude -p` and Agent SDK runs
  start it again on the next tool call. Remote (HTTP/SSE) servers reconnect on their own
  (code.claude.com/docs/en/mcp, "Stdio servers that exit or fail to start", read 2026-11-18). So
  "exit and let the client restart" does not work for the interactive case.
- Claude Code fetches the lists again when a server sends a `list_changed` notification in an
  interactive session (same page, "Dynamic tool updates").

### What triggers the refusal in practice

Every traced refusal was `vendor/ changed` from `open_run`, and followed a real dependency change:
a merge or a checkout that changed `composer.lock`, then a `composer install` (often started in the
background by a git hook), or a direct `composer update`. Some refusals could not be traced.

No refusal came with a crash or a disconnect, and no step was running at any of them. Each refusal
was the first call of a new walk. Checkouts that changed no autoloading package gave no refusal.

So the guard from `d378193` works as designed in every traced case. The cost is the manual
reconnect, not a wrong refusal.

### What the server process actually loads

Measured on 2026-11-18 with a probe: `auto_prepend_file` set to a script that writes
`get_included_files()` at shutdown.

In this repository, `php vendor/bin/testbench mcp:start pipeline` with one `Shell::run('true')`
step, sent `initialize`, `open_run` and two `next_step` calls:

- Boot loads 896 files. The full walk loads 964.
- The server loads class files from 51 of the 169 installed packages that declare autoload entries.
- After boot, the walk loads new files only from `laravel/framework`, `laravel/mcp`, `nesbot/carbon`
  and `symfony/process`. Steps run as subprocesses, so a step's own code never runs in the server.
- A second probe sent only `open_run`, with no `initialize`. It returned a normal open run. So
  `laravel/mcp` v0.9.4 needs no handshake at runtime.

In a Laravel app, `php artisan mcp:start pipeline` that answers one `open_run` loads files from
most vendor packages: framework, logging, HTTP client, error reporting and so on. Only dev tools
such as static-analysis rule sets load nothing. So a usual upgrade touches a loaded package.

### Approaches considered

| Approach | Why not chosen |
|---|---|
| Server exits, client restarts it | Claude Code does not restart a stdio server in an interactive session (see above). |
| Refuse only when a changed package has a file in `get_included_files()` | No measured benefit: a usual upgrade touches a loaded package (see above). It also needs care, because the `ClassLoader` in memory keeps the old PSR-4, classmap and `files` maps that `vendor/composer/autoload_real.php` loaded once at boot. A new package, a changed `autoload` entry, a moved `install-path` or an authoritative classmap would each break it. |
| Each tool call runs in a fresh subprocess, as Laravel Boost does (`vendor/laravel/boost/src/Mcp/ToolExecutor.php:26-66`) | Boost's tools hold no state. This server holds runs and background steps in memory (`README.md:321-325`), so every run would first have to move to disk. Much larger change. |
| HTTP transport, so the client reconnects on its own | The server must then run as a separate long-lived process, which has the same stale-vendor problem. |
| `pcntl_exec` the server in place, keeping STDIN/STDOUT | `StdioTransport` reads STDIN through a buffered stream, so lines already in the buffer are lost on exec. Needs `pcntl`, which is not always present. |
| Under the supervisor, restart at every `open_run` when the server holds no run in progress | Removes the vendor snapshot and most config-reload refusals. Costs one full app boot per walk, and changes config-reload behaviour that works today. Not chosen now; see Open Question 1. |
| Supervisor process (this spec) | Chosen. Small, and keeps the client connection. |

## 2. The supervisor

A plain PHP script, shipped as a Composer binary: `bin/boost-pipeline-mcp`, listed under
`composer.json` `bin`. The consumer registers it in `.mcp.json`:

```json
{
    "mcpServers": {
        "pipeline": {
            "command": "vendor/bin/boost-pipeline-mcp",
            "args": ["php", "artisan", "mcp:start", "pipeline"],
            "timeout": 600000
        }
    }
}
```

The arguments are the server command. The supervisor does not hard-code `artisan`, so a package
that runs the server through `vendor/bin/testbench mcp:start pipeline` works the same way.

Rules for the script:

- It does not require `vendor/autoload.php` or any file from `vendor/`. A vendor change then cannot
  make the supervisor itself stale. PHP compiles the script once at start, so an update of this
  package does not change the running supervisor either. The Composer bin proxy only `include`s the
  script (`## Assumptions`).
- It starts the server with `proc_open`. The server's STDIN and STDOUT are pipes. Its STDERR is
  the supervisor's STDERR, so log lines still reach the client.
- It sets `BOOST_PIPELINE_SUPERVISED=1` in the server's environment. The value is the protocol
  version between supervisor and server.
- It sets `BOOST_PIPELINE_RESTARTED=1` for a server it starts after a restart, so that server can
  say in its first answer that earlier runs are gone.
- It reads newline-delimited JSON from both sides with `stream_select`, and forwards each line.
  Both pipes are non-blocking, and each direction has a write buffer, so a full pipe on one side
  cannot stop the other.
- It parses only what it needs from each line: the `id`, and whether the line is a request or a
  response.
- When a server dies, the supervisor drops an incomplete last line from it.

State the supervisor keeps: the client requests (with an `id`) that have no response yet, in order.
It resends them to a new server.

The supervisor does not replay the `initialize` handshake. `laravel/mcp` keeps no session state
(section 1), so a new server answers `tools/call` without one. A replayed `initialize` would also
fail on the newer protocol revision, where `initialize` is not a method. See STOP Condition 3.

### Restart flow

1. The server exits with code `75` (`EX_TEMPFAIL`).
2. The supervisor starts a new server with the same command and environment.
3. It resends every client request that has no response yet, in the original order. Then it goes
   back to plain forwarding.

Resending is safe. The server handles one line at a time and exits before it writes a response, so
the request that triggered the exit had no effect. The requests after it were never read.

### Other exits

| Server exit | Supervisor action |
|---|---|
| `75` from a server that already answered a request | Restart flow above |
| `75`, or any exit, from a restarted server before its first response | Start it again after a delay (1, 2, 4, 8 seconds), 15 seconds of delay in total, plus the boot time of each try. An install that still runs is the expected cause. |
| The retry time is used up | Answer each pending request with a JSON-RPC error: "the pipeline server could not restart; a composer install may still be running; wait and call `open_run` again". Start a new server for the next request. Do not tell the agent to reconnect. |
| Any other code, or a signal, from a server that already answered | Exit with the same code (signal: `128 + n`). The client sees a disconnect, as today. |
| The client closes STDIN | Close the server's STDIN, wait for it to exit, exit `0` |

## 3. The restart request in the server

`RunManager::open()` decides between a restart and the current refusal. The decision needs the
open runs, so it cannot live in `ConfigReloader`.

- Split the vendor check out of `ConfigReloader::refresh()` into a public `vendorChanged(): bool`.
  `refresh()` keeps calling it and keeps throwing, so callers outside `open()` keep today's
  behaviour.
- In `RunManager::open()`, before `reloadChangedConfig()`:
  - If `vendor/` does not match `composer.lock`, refuse with a new `installInProgress()` message:
    "vendor/ does not match composer.lock: a composer install is still running, failed, or did not
    run. Wait for it, or run `composer install` (ask the user if you may not run it), then call
    open_run again." It names up to five packages that differ. No restart. This runs on every `open_run`,
    before the vendor check.
  - With `open_run.require_installed_lock` set to `false` in the published config (default `true`),
    a mismatch does not refuse. `open_run` goes on and adds a `warnings` entry with the same text.
    A vendor change still needs a matching lock before a restart, so with the opt-out the server
    refuses with `installInProgress()` only when a restart is due.
  - If `vendorChanged()` is false, carry on as today.
  - If the server is supervised and no run is in progress, call the new `RestartsServer`
    contract. The shipped implementation calls `exit(75)`. "In progress" means `isPending()` in any
    pipeline, or a run in another pipeline that holds results and is not complete. A
    non-pending run in the pipeline being opened does not count: the agent re-opens it, often to
    retry a failed step, and its passes were measured against the old `vendor/` anyway.
  - Otherwise, throw `vendorChanged()` as today, with a message that names the reason (a step
    still runs, or a run in another pipeline would be lost).
- "`vendor/` matches `composer.lock`" (new `InstallState` class, injected into `RunManager`):
  - Compare the name, version and reference (source reference, else dist reference) of each
    package in `composer.lock` (`packages`, plus `packages-dev` when `installed.json` has
    `dev: true`) with `installed.json`. Any difference means no match.
  - When `bootstrap/cache/packages.php` exists, it must not be older than `installed.json`.
    Laravel's `package:discover` rewrites it on every install, after the autoloader dump. This part
    applies only when `packages.php` was not older than `installed.json` when the server started.
    A project that runs `composer install --no-scripts`, or has no `package:discover` hook, then
    never gets stuck on it. A project without that file skips this part.
  - When `composer.lock` is missing or cannot be read, the check counts as a match. A project
    without a lock file keeps today's behaviour.
- The first `open_run` answer of a server started with `BOOST_PIPELINE_RESTARTED=1` adds a
  `warnings` entry: the server restarted after a vendor change, and runs opened before are gone.
- `EnvironmentScrubber::forStep()` removes `BOOST_PIPELINE_SUPERVISED` and
  `BOOST_PIPELINE_RESTARTED` (`src/Runner/EnvironmentScrubber.php:34-44`).
- New contract `SanderMuller\BoostPipeline\Contracts\RestartsServer` with one method,
  `restart(): never`. The default binding is `ExitForRestart`. Tests bind a fake that throws, so
  the suite never calls `exit`.
- "Supervised" means `getenv('BOOST_PIPELINE_SUPERVISED') === '1'`. Read it through the existing
  `ServerProcess` contract (new method `isSupervised(): bool`), so tests do not touch the real
  environment.
- `vendorChanged()` message: keep the reconnect advice, and add one sentence. Without the
  supervisor, it names `vendor/bin/boost-pipeline-mcp`. With the supervisor and a pending step, it
  says to wait for the step and call `open_run` again.

```php
public function open(?string $pipeline = null, ?string $selection = null): Run
{
    $lockMatches = $this->install?->matchesLock() ?? true;

    if (! $lockMatches && $this->requireInstalledLock) {
        throw InvalidPipelineConfigException::installInProgress($this->install->differences());
    }

    // With the opt-out, a mismatch becomes a warning on the answer (not shown).

    if ($this->config?->vendorChanged() === true) {
        if (! $lockMatches) {
            throw InvalidPipelineConfigException::installInProgress($this->install->differences());
        }

        if ($this->process?->isSupervised() === true && ! $this->anyInProgress()) {
            $this->restarter->restart();
        }

        throw InvalidPipelineConfigException::vendorChanged($this->process?->isSupervised() === true, $this->blockers());
    }

    $this->reloadChangedConfig();
    // ... unchanged
}
```

## 4. Announce a changed tool list

The `pipeline` argument names the declared pipelines in its description, and it exists only when
the project declares a map of pipelines (`src/Mcp/Tools/Concerns/PipelineTool.php:46-60`). So the
schema a client holds goes stale when the names change, or on a switch between one pipeline and a
map. Today `ConfigReloader` refuses both cases and asks for a reconnect
(`src/Config/ConfigReloader.php:180-192`).

- `PipelineServer` declares `tools.listChanged: true`.
- `open_run` sends `notifications/tools/list_changed` before its result when:
  - a config reload changed the pipeline names, or switched between one pipeline and a map. The
    reload then goes ahead instead of being refused. The two refusals in `ConfigReloader` go away.
  - it is the first `open_run` of a server started with `BOOST_PIPELINE_RESTARTED=1`. The new
    server cannot know the old names, so it always announces. A client fetches the list once,
    which costs little.
- To send it, `OpenRun::handle()` (`src/Mcp/Tools/OpenRun.php:61`) returns a generator: first the
  notification, then the normal response.
- The `open_run` call that triggered the reload used the old schema. If it now lacks a required
  `pipeline`, it gets the existing `pipelineNotSelected` error, which lists the names. The agent
  calls again with the fresh schema.
- Clients that ignore the notification no longer get a refusal with a reconnect hint. The call
  works, and their schema is stale until a reconnect: after a switch from one pipeline to a map it
  has no `pipeline` argument, so a call without one gets `pipelineNotSelected`, which names the
  pipelines.

## 5. `pipeline:verify` checks the installed packages

`open_run` refuses a mismatch by default, but the opt-out lets a run walk on a `vendor/` that does
not match `composer.lock`. A lock change can also escape the tree fingerprint, for example a
gitignored lock. So the receipt records what the run walked, and the gate compares it.

- At open and at `rebaseline()` (`src/Run/Run.php:448`), the run records the installed digest: a
  hash over the name, version and reference of each package in `installed.json`, in the same field
  set that `InstallState` compares, plus the `dev` flag of that install.
- The receipt gets a new field `installed`: `{digest, dev}` (`src/Run/Receipt.php:110`, `:130`;
  written at `src/Run/Run.php:781`).
- `pipeline:verify` computes the same digest from `composer.lock` (with `packages-dev` when the
  receipt's install had dev packages). It fails when the two differ: "Run [x] walked installed
  packages that do not match composer.lock. Run composer install and open a new run." The check
  sits next to the tree check in `src/Console/VerifyCommand.php:96-130`.
- It fails also when `open_run.require_installed_lock` is `false`. The opt-out lets an agent walk; it
  does not let the gate pass.
- A receipt without the field (written before this change) is not checked, the same as a receipt
  that recorded no tree. A project without `composer.lock` is not checked.

## Edge Cases

| Scenario | Handling |
|---|---|
| Vendor changed, `vendor/` matches the lock, no run in progress, supervised | Server exits `75`. The supervisor restarts it and resends `open_run`. The agent gets a normal answer with a warning that earlier runs are gone. Phase `server-restart` and `supervisor` tests. |
| Vendor changed while a step runs in the background | No exit. `open_run` refuses and tells the agent to wait for the step. The next `open_run` after the step restarts the server. Phase `server-restart` tests. |
| Vendor changed, and the agent re-opens the same pipeline after a failed step | The run in that pipeline does not block the restart. The new server opens a fresh run, and the warning says earlier runs are gone. Phase `server-restart` tests. |
| Vendor changed while another pipeline has a run with results that is not complete | No exit. `open_run` refuses and names that pipeline, so the agent can finish or drop it. Phase `server-restart` tests. |
| Vendor changed, server not supervised | Refusal as today. The message names the supervisor. Phase `server-restart` tests. |
| `open_run` arrives while a `composer install` still runs | `vendor/` does not match the lock yet, or `bootstrap/cache/packages.php` is older than `installed.json`. `open_run` refuses with `installInProgress()`. No restart. Phase `server-restart` tests. |
| `composer.lock` changed but nobody ran an install (for example after a merge with no hook) | `open_run` refuses with `installInProgress()`, also when nothing in `vendor/` changed since the server started. The message says to run the install. Phase `server-restart` tests. |
| The agent may not run `composer install`, and the lock is ahead | Default: `open_run` refuses and tells the agent to ask the user. With `open_run.require_installed_lock = false`: `open_run` succeeds with a warning that names the packages. Phase `server-restart` tests. |
| `composer install --no-dev` | `installed.json` has `dev: false`, so only the lock's `packages` are compared. Phase `server-restart` tests. |
| A path repository or a `dev-` branch package | Compared by version and reference, the same fields in both files. If Composer leaves the reference empty in both, they match. Phase `server-restart` tests. |
| A run is open and the lock changes during the walk | `next_step` does not check, as today. The next `open_run` refuses until `vendor/` matches again. |
| The install finished its package operations but a later script failed | The package set matches the lock. In a Laravel app, `packages.php` decides: if `package:discover` ran, the restart goes ahead. A restarted server that fails at boot is retried, then reported (next row). |
| A restarted server exits before its first response, or exits `75` again | Reached only after the lock check passed, so the package operations are done; the usual cause is a script that failed late. A full refresh can take minutes, but that wait happens in the `installInProgress()` refusal, not here. The supervisor retries after 1, 2, 4 and 8 seconds: 15 seconds of delay plus the boot time of each try. Then it answers the pending requests with an error that says to wait and call `open_run` again. Phase `supervisor` tests. |
| A step starts its own `mcp:start` (for example a test suite) | The step environment does not carry `BOOST_PIPELINE_SUPERVISED`, so that server never exits `75`. Phase `server-restart` tests. |
| An old supervisor runs a server from a newer package version | The server reads the protocol version from `BOOST_PIPELINE_SUPERVISED`. An unknown value counts as "not supervised", so the server refuses instead of exiting `75`. Phase `server-restart` tests. |
| The server dies in the middle of writing a line | The supervisor drops the incomplete line. Phase `supervisor` tests. |
| The shebang `php` and the `php` in the server command differ | The supervisor uses only core PHP functions, so any PHP 8.4 runs it. The server command keeps its own binary. No handling needed. |
| The client sends more requests while the server restarts | They stay in the pending list and go to the new server in order. Phase `supervisor` tests. |
| A client notification (for example `notifications/cancelled`) arrives during a restart | Buffered and forwarded after the resend. A cancel for a request that the new server never saw is harmless. Phase `supervisor` tests. |
| The restart changes the pipeline names | The first `open_run` of the new server sends `list_changed`, and the client fetches the new schema. Phase `list-changed` tests. |
| A config edit renames a pipeline, or switches between one pipeline and a map, with no restart | The reload goes ahead and `open_run` sends `list_changed`. If the call lacks a now-required `pipeline`, it gets `pipelineNotSelected`, and the agent calls again. Phase `list-changed` tests. |
| The client ignores `list_changed` | The call still works. The `pipeline` description stays stale until a reconnect. No handling. |
| A receipt walked a `vendor/` that did not match the lock (opt-out) | `pipeline:verify` fails and says to run `composer install` and open a new run. Phase `verify-lock` tests. |
| The lock changed after the run, and the tree fingerprint did not see it | `pipeline:verify` fails on the digest. Phase `verify-lock` tests. |
| A receipt from before this change | Not checked for the installed digest. Phase `verify-lock` tests. |
| A server that already answered crashes for another reason | The supervisor exits with the same code. The client sees a disconnect, as today. Phase `supervisor` tests. |
| The client disconnects | The supervisor closes the server's STDIN, waits, and exits `0`. Phase `supervisor` tests. |
| The supervisor runs on Windows | `stream_select` fails on `proc_open` descriptors there. The supervisor writes one STDERR line and runs the server with inherited STDIO, without restarts. Phase `supervisor` tests (skipped off Windows). |
| `vendor/bin/boost-pipeline-mcp` is a Composer proxy file | The proxy only `include`s the script and loads no autoloader, so the rule in section 2 holds. |
| The supervisor gets SIGTERM | Without `pcntl` it dies at once. The server reads EOF and leaves its loop (`StdioTransport.php:35`), and `ProcessPendingSteps::__destruct()` stops its steps. Same as a client disconnect today. No extra handling. Phase `supervisor` tests run the proxy path. |

## Implementation

### Phase 1: Restart request in the server (Priority: HIGH)

**ID:** server-restart · **Depends:** none

- [x] Add `ConfigReloader::vendorChanged(): bool` and make `refresh()` use it — `src/Config/ConfigReloader.php:108`
- [x] Add `Contracts\RestartsServer` and `Runner\ExitForRestart` (`exit(75)`), bind it in `BoostPipelineServiceProvider`
- [x] Add `ServerProcess::isSupervised()`, implement it in `ConsoleServerProcess` from `BOOST_PIPELINE_SUPERVISED`
- [x] Add `InstallState::matchesLock()` — package name, version and reference from `composer.lock` against `installed.json`, plus the `bootstrap/cache/packages.php` age check
- [x] In `RunManager::open()`, refuse or restart as in section 3 — inject `ServerProcess`, `RestartsServer` and `InstallState`
- [x] Add `installInProgress()`, and give `vendorChanged()` the supervised flag and the blocker reasons — `src/Exceptions/InvalidPipelineConfigException.php:145`
- [x] Add the "server restarted" warning to the first `open_run` answer when `BOOST_PIPELINE_RESTARTED=1` — `src/Mcp/Tools/OpenRun.php:76-84`
- [x] Add `open_run.require_installed_lock` (default `true`) to `config/boost-pipeline.php`, read it in the provider, and turn a mismatch into a `warnings` entry when it is `false`
- [x] Remove both flags in `EnvironmentScrubber::forStep()` — `src/Runner/EnvironmentScrubber.php:34`
- [x] Tests — `tests/Feature/ConfigReloadTest.php`: a lock mismatch refuses with no vendor change since start, supervised or not, and names the packages; a failed-step run in the pipeline being opened does not block the restart; `--no-dev` compares only `packages`; a matching lock passes; with `require_installed_lock` false a mismatch warns and opens, but still refuses when a restart is due;
  supervised and idle calls `restart()`; a pending step refuses with the wait message; an unfinished run in another pipeline refuses and names it; a lock mismatch refuses with `installInProgress()` and never restarts; an old `packages.php` refuses when `composer.json` runs `package:discover` after the dump, and is ignored when it does not; no lock file counts as a match; unsupervised refuses with the supervisor hint; an unknown protocol version counts as unsupervised; no vendor change never calls `restart()`. Unit test for the scrubber. Unit test for the restart warning

### Phase 2: Announce a changed tool list (Priority: HIGH)

**ID:** list-changed · **Depends:** server-restart

- [x] Declare `tools.listChanged` in `PipelineServer` — `src/Mcp/PipelineServer.php`
- [x] Make `OpenRun::handle()` return a generator that yields `notifications/tools/list_changed` before the result, in the two cases of section 4 — `src/Mcp/Tools/OpenRun.php:61`
- [x] Remove the two name refusals from `ConfigReloader::refresh()` and report a name change to the caller instead — `src/Config/ConfigReloader.php:180-192`
- [x] Tests — the capability is in the `initialize` answer; a reload that renames a pipeline opens and streams the notification first; a switch from one pipeline to a map does the same; the first `open_run` after a restart streams it; a normal `open_run` sends none

### Phase 3: Verify the installed packages (Priority: HIGH)

**ID:** verify-lock · **Depends:** server-restart

- [x] Record the installed digest at open and at `rebaseline()` — `src/Run/Run.php:448`
- [x] Add `installed` to `Receipt` (`toArray`, `fromArray`) and write it — `src/Run/Receipt.php:110`, `:130`, `src/Run/Run.php:781`
- [x] Compare it with the lock digest in `pipeline:verify`, next to the tree check — `src/Console/VerifyCommand.php:96-130`
- [x] Tests — a receipt that walked a mismatched `vendor/` fails; a lock changed after the run fails; a matching receipt passes; a receipt without the field passes this check; no lock file passes this check; the opt-out does not change the gate

### Phase 4: The supervisor script (Priority: HIGH)

**ID:** supervisor · **Depends:** none

- [x] Add `bin/boost-pipeline-mcp` (no autoloader) and the `bin` entry in `composer.json`
- [x] Forwarding loop with `stream_select`, the pending-request list, and the restart flow from section 2
- [x] Exit handling from the "Other exits" table, including the retry with backoff and the final error text
- [x] Non-blocking pipes with a write buffer per direction; drop an incomplete line from a dead server
- [x] Windows fallback: one STDERR line, inherited STDIO, no restarts
- [x] Make sure `.gitattributes` does not `export-ignore` `bin/`
- [x] Tests — a Pest feature test that runs the supervisor with `symfony/process` against a fixture server script (plain PHP, newline JSON). Cover: plain forwarding; a restart sends no `initialize`; pending requests resent in order; an early exit is retried with backoff and ends in the "wait and call `open_run` again" error; a full STDOUT pipe on one side does not block the other; another exit code passes through; client EOF exits `0`; a test with no autoload file in reach proves the script needs none

### Phase 5: End-to-end and docs (Priority: MEDIUM)

**ID:** docs · **Depends:** list-changed, verify-lock, supervisor

- [x] One end-to-end test: supervisor + real `mcp:start pipeline` under Testbench, change the fixture `installed.json` and lock, call `open_run`, get a structured answer with the restart warning
- [x] Re-run the `exit(75)` probe in a Laravel app that registers an error tracker with shutdown handlers (STOP Condition 2)
- [x] README: register the supervisor in the install snippet (`README.md:37-48`), add the restart to the vendor paragraph (`README.md:343-347`), document the lock check and `open_run.require_installed_lock` next to `verify.config_fingerprint`, the new `pipeline:verify` failure, and that a pipeline rename no longer needs a reconnect (`README.md:337-341`)
- [x] `UPGRADING.md`: note the optional `.mcp.json` change, and the new `open_run` refusal when `vendor/` does not match `composer.lock`

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The server handles one request at a time.** The resend logic depends on `StdioTransport`
   reading the next line only after the current one finishes. If a supported `laravel/mcp` version
   reads ahead or handles requests concurrently, a resent request can run twice.
2. **`exit(75)` inside a tool handler writes nothing to STDOUT.** Verified on v0.9.4 under
   Testbench only (see `## Assumptions`); re-check on the other supported versions and in an app
   with an error tracker that registers shutdown handlers. If `laravel/mcp` or a shutdown
   handler writes a partial response first, the client gets an answer to a request the supervisor
   then resends.
3. **A new server needs no handshake.** Every supported `laravel/mcp` version must answer
   `tools/call` without a prior `initialize`. If one does not, the supervisor must replay the
   handshake, and that replay must work on both protocol revisions.
4. **A notification can go before the result of the same call.** Phase 2 depends on a client
   handling `list_changed` that arrives inside a `tools/call`. Tested in Claude Code print mode only.
   If interactive mode drops it, send it after the result instead, or stop and report.

---

## Open Questions

1. **Restart at the start of every walk instead of on a detected vendor change?** Under the
   supervisor, the server would exit `75` at an `open_run` when no run in any pipeline holds
   results.

   Pros:
   - One mechanism for every stale state: vendor changes, config files that declare classes or
     include other files, helper files the config loads, an update of this package itself. The
     `configNeedsReconnect` refusals in `ConfigReloader` would not reach a supervised user.
   - Every walk runs on a fresh process, with no state left from an earlier walk.
   - Low cost: well under a second per walk (159 ms measured here), against walks that take minutes.

   Cons:
   - It must not restart while a run holds results. `open_run` hands back the open run when the tree
     did not move (`src/Run/RunManager.php:90-110`), and a re-open to retry a failed step would lose
     every pass. So it only helps at the start of a walk.
   - It loses the complete runs of other pipelines in memory, so `status` answers "No run is open"
     more often. Receipts stay on disk.
   - It does not remove code. Without the supervisor the server still needs `ConfigReloader` and the
     vendor check, so both paths stay.
   - In practice every reconnect came from a vendor change, not from a config-reload refusal. So
     the extra coverage targets cases nobody reported.

   Recommendation: keep the restart on a detected change for now, and revisit when a config-reload
   refusal is reported. Not decided.

---

## Resolved Questions

1. **How should a restart wait for a running `composer install`?** **Decision:** The server checks
   that `vendor/` matches `composer.lock` and, in a Laravel app, that `packages.php` is not older
   than `installed.json`. If not, it refuses with "wait and call `open_run` again" and does not
   restart. The supervisor also retries a restarted server that exits early. **Rationale:** mtimes
   of Composer files are no signal, process checks match the wrong processes, and the hook log has
   no reliable end line (Composer source).
2. **Refuse when `composer.lock` is ahead of `vendor/` with no vendor change since start?**
   **Decision:** Yes, on every `open_run`, with an opt-out: `open_run.require_installed_lock = false`
   turns the refusal into a warning, for a session where the agent may not run `composer install`
   and the lock stays ahead. **Rationale:** a run then never verifies against
   dependencies the lock no longer names. The user chose this on 2026-11-18. It changes behaviour
   for every user, so it needs a line in `UPGRADING.md`.
3. **Announce changed pipeline names with `list_changed`?** **Decision:** Yes (section 4).
   **Rationale:** a test showed that Claude Code fetches the tool list again when the server
   declares `tools.listChanged`, and `laravel/mcp` can stream the notification. This also removes
   two reconnect refusals. The user asked for it on 2026-11-18.
4. **Should `pipeline:verify` also check the installed packages?** **Decision:** Yes (section 5).
   The receipt records the installed digest, and the gate compares it with `composer.lock`, also
   with the opt-out. **Rationale:** the user chose it on 2026-11-18. The opt-out lets an agent walk
   but must not let the gate pass.

## Findings

<!-- Notes added during implementation. Do not remove this section. -->

- **Phase 1: `ServerSupervisor` instead of new `ServerProcess` methods.** `ServerProcess` is a
  public contract, so adding methods would break a consumer that implements it. One new contract,
  `Contracts\ServerSupervisor` (`isSupervised()`, `isRestart()`, `restart(): never`), replaces
  both the `ServerProcess::isSupervised()` method and the `RestartsServer` contract of section 3.
  The shipped implementation is `Runner\EnvironmentSupervisor`.
- **Phase 1: `InstallState` lives in `src/Config/`**, next to `ConfigReloader`. It is made once per
  process (a singleton). It reads the `package:discover` hook from `composer.json` on every
  check, because a branch switch can add or remove it.
- **Phase 1: warnings travel through `RunManager::warnings()`.** `open()` returns a `Run`, so the
  restart notice and the opt-out lock warning are collected on the manager and `OpenRun` adds them
  in front of the preflight warnings.
- **Phase 4: the supervisor keeps client notifications while a request has no answer.** The
  running server gets them at once. When it exits before it reads them, the next server gets them
  after the resent requests. The supervisor drops them when no request is waiting, or when it gives
  up and answers the requests with an error. The first version kept them only while no server ran.
  The requirements walk and the review found two losses: a replacement that failed at boot, and
  a server that exited `75` with the notification unread in its pipe. `laravel/mcp` drops every
  notification today (`Server::handle()` returns early on one), so neither loss had an effect yet.
- **Review fixes in the supervisor, not tested.** A failed write to STDOUT now counts as a
  client that went away; before, the loop spun at full CPU when it had error lines and no reader.
  A server STDOUT at EOF leaves the select set until the process exits. The last read after an
  exit stops after one second, so a child that inherited the server's STDOUT cannot hold the
  supervisor. A test for these needs a closed client pipe or an inheriting child process, and the
  fixtures cannot make either without `proc_open`, which the PHPStan rules forbid in tests.
- **Review: the Laravel manifest check keys on the discover hook.** The first version
  tracked `bootstrap/cache/packages.php` when it was in step at server start. Laravel writes that
  file only when it is missing (`PackageManifest::getManifest()`), so in a project with no
  `package:discover` hook it fell behind after every install, and `open_run` refused for good. The
  check now applies only when a `post-autoload-dump` script, or a script it calls with `@`, runs
  `package:discover`. This also removes the blind spot of a server that started during an install.
- **Review: not handled.** A custom `vendor-dir`, or a `vendor/` or `composer.lock` outside the
  base path, makes the lock check find nothing to compare, so it passes. That misses a refusal; it
  never refuses wrongly.
- **Review: not handled — the autoloader dump window.** Composer writes `installed.json` for the
  last time before it dumps the autoloader. In a project without the `package:discover` hook, a
  restart in that short window can boot a server on the old `autoload_*.php`, and that server
  sees no later vendor change. Not traced against Composer source; the window is short.
- **Review: the receipt digest carries a format prefix** (`xxh128-v1:`). A later change to what a
  digest covers takes a new prefix, and `pipeline:verify` skips a digest it cannot compare instead
  of failing every older receipt.
- **Review: `require_installed_lock` sits in an `open_run` config group.** It controls `open_run`,
  not `pipeline:verify`, so the `verify` group would mislead.
- **Review: `ServerSupervisor` stays a public contract in `Contracts/`.** The review suggested
  `@internal` in `Runner/`, but the project's PHPStan rules require interfaces in `Contracts` and
  reject calls to `@internal` methods from tests. The review also suggested shipping the restart, the lock refusal and
  `list_changed` as three releases; the user asked for the whole spec in one change.
- **Phase 4: test seam for the retry delays.** `BOOST_PIPELINE_SUPERVISOR_DELAYS` (comma-separated
  seconds) replaces 1, 2, 4, 8, so the tests do not wait 15 seconds. The tests use it; it is not
  documented for users.
- **Phase 4: not tested here.** The Windows fallback (no Windows runner), and the Composer bin
  proxy path: a root package's own `bin` is not linked into its `vendor/bin`. The proxy behaviour
  was read in source (`## Assumptions`). The script is tested from a copy in a directory with no
  `vendor/`.
- **Phase 2: `OpenRun` returns a generator only when it must notify.** Every other call returns the
  `Response` as before, so callers and tests that read the response directly are unchanged. An
  `open_run` that fails after a reload that renamed pipelines (for example a removed name) still
  sends the notification, ahead of the error.
- **Phase 2: the sole-pipeline alias.** A reload that switches from one pipeline to a map rebinds
  `Pipeline::class` to the refusing singleton again, instead of keeping the old instance
  (`BoostPipelineServiceProvider`).
- **Phase 2: `McpSurface` checks two more symbols**, `Response::notification` and
  `Server::addCapability`, because the server now calls both.
- **Phase 3: a malformed `installed` field rejects the receipt**, the same rule as the other optional
  fields (`Receipt::fieldsAreWellFormed()`): coercing it to null would skip the check.
- **Phase 5: the end-to-end test boots a copy of the Testbench skeleton.** The skeleton links
  `vendor/` to this repository's own, so a test that changed its `installed.json` would change the
  real one. `tests/Fixtures/testbench-mcp-server.php` creates the application on a copied base path
  with its own `composer.lock` and `installed.json`, and classes still load from this repository.
- **Phase 5: the manifest check showed up in the end-to-end test.** The copied
  `bootstrap/cache/packages.php` and the first `installed.json` could fall in the same second, so
  the manifest counted as in step. A later `installed.json` with no `package:discover` then refused
  with `installInProgress()`, as designed. The test now touches the manifest after each fake
  install, the same as a real install does.
- **Phase 5: STOP Condition 2 holds in a Laravel app.** The server ran with a `SIGALRM`
  handler that calls `exit(75)` after `open_run`, in an app with an error tracker: exit code 75, one
  line on STDOUT (the `open_run` answer), nothing from shutdown handlers. The released package was
  used, so the exit came from the probe, not from this code.
- **Phase 1: a halted run in another pipeline does not block a restart.** "Not complete" is read as
  "not terminal" (`RunState::isTerminal()`): a halted walk must be opened again anyway.
