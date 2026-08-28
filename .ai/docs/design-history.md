# Design history

Why this package exists, what it was almost built as, and which ideas were rejected on
purpose. The [README](../../README.md) says what the thing does; this file says why it does it
that way, so a later change does not undo a decision without knowing there was one.

Written August 2026, at the end of the prototype. The full original spec is archived at
[spec-archive-2026-08.md](spec-archive-2026-08.md).

## Where it came from

The evaluation and verification flow already existed as prose skills — `evaluate`,
`final-verification-review`, `code-review`, `codex-review`, `backend-quality`,
`frontend-quality` — published through `sandermuller/boost-core` and
`sandermuller/boost-skills`. It worked. Two things about it did not:

1. **Nothing sequenced it.** The model decided when each check happened.
2. **The gates were self-graded.** `boost.php`'s `pr.gates` vocabulary
   (`skill_invoked` / `shell_command` / `mcp_tool` plus `on_missing`) was assessed by the model
   from its own transcript. A gate that asks "did you run the review?" and accepts the answer
   is not a gate.

The opening ask was an MCP server that holds the pipeline as a state machine so the agent
"cannot skip over or fast forward". Closing hole 2 is what the package actually does.

## Three reversals worth knowing about

Each of these was believed, written down, and then disproved. They are recorded because the
wrong version is the intuitive one, and someone will arrive at it again.

### 1. MCP cannot enforce ordering

The original framing was that the MCP server would enforce the order. It cannot. The MCP
specification (2025-06-18, `server/tools`) makes tools **model-controlled**: the model
discovers and invokes them based on its own understanding. There is no mechanism to force a
call, pin an order, or gate availability.

Real enforcement of an agent's *actions* lives in the host's hook system — Claude Code's
`PreToolUse` can deny a call and `Stop` can force continuation. That is a different layer, and
it is deliberately out of scope. This package is a workflow step, not a cage.

### 2. The guarantee is not secrecy

An early draft claimed "you cannot skip a step you cannot see". That is false.
`.config/pipeline.php` is a file in the repo, the agent can read the whole pipeline whenever it
likes, and five documented default phases make its shape obvious anyway.

The surviving guarantee is narrower and does not depend on hiding anything:

> The server only ever **executes** the step at the cursor, and the cursor only advances when
> that step resolves. Reading ahead tells the agent what is coming; it does not let the agent
> obtain a receipt for it.

This is why `open_run` returns `total_steps` rather than withholding it. Withholding the count
is theatre — the agent can count the config. What is withheld is the *identity* of steps past
the cursor, and only because naming them serves no purpose.

### 3. A diff naming the same symbols is not a cause

The pilot project's static-analysis step was red with 12 errors from a project-specific
PHPStan rule. A draft of the spec blamed an uncommitted config file, because that file's diff
added exactly the symbols the errors named. The claim survived two review passes.

Reverting the file disproved it. With the config reverted **and** the analyser's result cache
cleared, it still reported the same 12 errors. What the file actually controlled was whether a
Rector config could *fix* them: 8 fixes offered with it, 0 without. The errors were genuine
pre-existing debt.

The method lesson generalises past this package: correlation in a diff is not causation, and
reverting the file with caches cleared is what settles it.

## Decisions, and what they were chosen over

| Decision | Chosen over | Why |
|---|---|---|
| A phase is a named, ordered group of steps — nothing more | A fixed set of phase types with their own semantics | Keeps the default set open at no extra cost. A custom phase is the same machinery |
| Five default phases, ordered by the fix chain | Cheapest-check-first | Rector rewrites, Formatting formats the result, StaticAnalysis reads the formatted result, Tests exercise it. Cheapest-first would format code Rector is about to rewrite. Cost ordering applies only *within* a phase |
| Flat lists of phases and steps | Middleware onion (`handle($run, $next)`) | A drip-feed pipeline **suspends** at each step and resumes on the next tool call. An onion forces the server to replay every completed step on resume, and puts an idempotency requirement on every step |
| A transition step is an ordinary step with a different anchor | A server-side hook with its own semantics | One step type, one execution path, one verdict shape — and a transition can gate, because a failing one stops the run like any other step |
| The server runs each shell step | The agent runs it and reports | This is the whole point: it closes the self-grading hole, and it keeps long output out of the agent's context |
| An agent step is `acknowledged`, never `passed` | Trusting the self-report, or an oracle | The server cannot verify that `/evaluate` ran or that `/eye-verification` looked at a browser. What an agent step buys is guaranteed delivery at the right point in the sequence, which prose skills cannot provide |
| An MCP prompt drives the flow | A thin driving skill file | `laravel/boost` already does this with `LaravelCodeSimplifier`, and Claude Code surfaces prompts as slash commands. No extra file, works in any MCP client, and it cannot drift from the tool descriptions because it lives beside them |
| Deterministic truncation plus a log file | Paginating oversized output | Simplest and deterministic. Pagination stays available if truncation turns out to cost the agent too much |
| No feature flag | A flag | Additive internal tooling. Omitting the `.mcp.json` entry is the off switch |
| The fix-loop advice removes the judgement | A rule the reader applies | Two rules were written and shipped before this one, both plausible, both wrong. Splitting by run state failed for a halt fixed by editing a config path. Keying on "did your fix change a file" failed because the fingerprint covers `HEAD`, so a commit or amend moves the tree with nothing on disk changed — contradicting the stale message shipped one release earlier. `open_run` is idempotent on an unmoved tree, which lets the advice say "call open_run" and stop. A rule its own author applied wrongly twice is a rule to delete, not to reword |

Two smaller ones, both removals made during review:

- **`report_step` does not accept a note against a shell step.** A note that cannot affect the
  verdict, and that nothing consumes, is one semantic more than the prototype needs — and it
  invites the misreading that the agent contributes to a shell verdict.
- **`Setup` was designed and then dropped**, leaving five defaults. Nothing populated it, and
  shipping an empty reserved phase advertises a capability the prototype does not exercise. The
  phase set is open, so `$phases->prepend(Setup::class)` restores it in one line when a real
  Setup step exists.

## Rejected, with the reason to keep rejecting it

### Pipeline-level baselining

Rejected twice, the second time for a better reason than the first. There **is** pre-existing
failure to tolerate. But PHPStan ships its own baseline and this project already uses it, so a
merge-base baseline at the pipeline layer would reimplement the tool's own feature, worse.

Pipeline-level baselining earns its place only for tools with no baseline of their own, and for
the different job of **attributing a new failure to a change**. That is why it belongs in v2
beside fingerprint invalidation: both need to know what a step's inputs were.

### Oracle steps (a model as judge)

Verified feasible, deliberately unused. `claude -p "…" --output-format json --permission-mode
dontAsk --allowedTools ""` exits 0 on the subscription with no API key and returns
`{subtype, is_error, result, stop_reason, num_turns}`. `codex exec` adds `--json`,
`-o/--output-last-message` and `--output-schema <FILE>` to constrain the verdict shape.

Two reasons it is not in v1. The agent steps actually wanted are skill invocations, not
judgments. And it is never cheap: a **two-token** prompt burned **39,492 cache-creation input
tokens** and took **8.6 seconds**, drawing down subscription allowance. A few well-targeted
oracle steps could work; a phase full of them cannot.

### Step-to-step data passing

Rejected on research rather than taste, and the research is the reason to keep rejecting it.
Four systems were checked:

- **GitHub Actions** deprecated `set-output` because parsing raw stdout let any workflow that
  logged untrusted data inject environment variables and paths.
- **GitLab** caps `artifacts:reports:dotenv` at 5 KB and 20–150 variables, UTF-8, no comments,
  names `[A-Za-z0-9_]`.
- **Tekton** embeds `Results` in the run status, so they are impractical when large, and fails
  with `InvalidTaskResultReference` when a producer never emits.
- **Turborepo and Nx have no value passing at all** — only file outputs and cache. That is the
  strongest signal that this is rarely needed.

Every one of them splits a *small named values* tier from a *large blob* tier and caps the
small one hard. The blob tier already exists here: every step writes its full output to
`storage/logs/pipeline/`. And the motivating case is covered by the shell —
`richter:affected-tests --plain` exists expressly "for command substitution", so
`php artisan test $(php artisan richter:affected-tests --plain)` needs no new machinery. The
README's "Passing data between steps" section documents the three patterns that replace it.

**If a small tier is ever built**, honour two constraints. The channel must be a file the
runner owns, never stdout (the `set-output` lesson). And consuming an output creates a
dependency edge, so a forward reference must fail loudly at `open_run`, and a reference to a
**dropped** step must not silently pass — the `all_verified: false`-on-notices guard already
covers half of that.

## What was copied from `laravel/boost`, and the one thing that was not

`laravel/boost` is built on the same `laravel/mcp` (`class Boost extends Laravel\Mcp\Server`),
so it is the closest reference implementation available. Six techniques were adopted rather
than reinvented:

1. `Response::error()` for `error`, `Response::structured()` for `failed` — see
   [invariants.md](invariants.md).
2. A subprocess per step with a scrubbed environment, from `Mcp\ToolExecutor`.
3. `Response::structured()` plus a declared `outputSchema`, so response shapes are
   machine-checked instead of prose.
4. `readOnlyHint` on the read-only tools, `anthropic/maxResultSizeChars` on `next_step`.
5. `Server::$instructions` for the drip-feed contract, stated **once** instead of repeated
   across four tool descriptions where the copies can drift.
6. `shouldRegister(): bool` config-gating, so an opted-out project gets an honestly empty tool
   list instead of call-time errors.

**Not copied: `Mcp\Tools\Tinker`'s error handling.** It catches `Throwable` and returns
`Response::text($throwable->getMessage())`, and on a non-zero exit returns
`Response::text('Failed to execute tinker: '.$output)`. Both are ordinary *success* responses
carrying failure prose the model has to interpret — exactly the conflation this package's
invariants forbid. Shipped code in a good package is not automatically the pattern to follow.

## Deferred to v2, with the reason

| Deferred | Why |
|---|---|
| Oracle steps | Feasible and expensive; the wanted agent steps are skill invocations |
| Baselining and per-tool failure parsers | Tools ship their own baselines; parsers are what turn line counts into failure counts |
| Fingerprint invalidation over declared inputs | Prove sequencing first. This is what makes a receipt expire when the code changes |
| Branch-keyed durable state plus a lock | In-process state is enough to test the concept |
| Elicitation, `on_missing`, `maxAttempts` | v1 halts and reports |
| Hook hardening (`PreToolUse` deny on `gh pr create`) | A different enforcement layer, opt-in and off |
| Wiring into `evaluate` / `final-verification-review` / `pull-requests` / `pr.gates` | Prototype first |
| Rollout beyond the pilot consumer | One consuming application only |

## Two things left open

**Does `/evaluate` belong in the Agent phase at all?** Its Phase 1 runs `backend-quality` and
`frontend-quality`, which is what Formatting, StaticAnalysis and Tests already do, so
registering it whole re-runs those checks. It *should* dedup itself — its skip criteria cover
"run earlier in this conversation" and "passed with zero errors" — but that dedup is the model
applying prose criteria to its own transcript, which is the mechanism this design exists to
replace. The real fix is to let `evaluate` read a pipeline run and skip on a **receipt** instead
of a recollection. That belongs with the wiring work.

**A step cannot be reached inside.** The pipeline runs a skill; it cannot see what the skill
did. That is the ceiling on how much an Agent-phase step can ever be worth.

## Provenance

Specced and built August 2026 across four implementation phases (`package-skeleton` →
`step-runner` / `mcp-tools` → `wire-up`), driven by one pilot application's
`.config/pipeline.php`. The detailed decision log stays with that application, because its
provenance is private.

One constraint from that survey is worth keeping, because it explains a decision that looks
timid otherwise. Most repos that consume the shared skill library are **packages** with no
`artisan`, so they could never install this server at all. That is why nothing in the existing
skill catalog was replaced: removing `evaluate` would strand every consumer that cannot run an
MCP server.
