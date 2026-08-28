<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp\Prompts;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Prompt;

/**
 * The driver, as an MCP prompt rather than a skill file.
 *
 * Claude Code surfaces prompts as slash commands, so this ships no extra file,
 * works in any MCP client rather than only skill-capable agents, and cannot drift
 * from the tool descriptions because it lives beside them. Modelled on
 * `laravel/boost`'s own `LaravelCodeSimplifier`.
 *
 * It deliberately says only what the tools do not already say. Prose that
 * restates a tool description is prose that can contradict it later.
 */
final class RunPipeline extends Prompt
{
    protected string $name = 'run_pipeline';

    protected string $title = 'run_pipeline';

    protected string $description = 'Walk the verification pipeline for this working tree, one step at a time, and report the result honestly.';

    public function handle(): Response
    {
        return Response::text(<<<'TXT'
        Walk the verification pipeline to its end.

        1. Call `open_run` to start and get the first step. Where the project
           declares more than one pipeline, `pipeline` is required and every later
           call needs the same name: `open_run(pipeline: "release")`. Each pipeline
           has its own cursor and its own receipt, so naming the wrong one runs the
           wrong steps. Where the pipeline tags its steps, pass `only` to run one
           scope: `open_run(only: "backend")` walks the steps carrying that tag plus
           every untagged one. Use it when your change cannot affect the rest. A
           scoped run verifies less, says so in `scope`, and its receipt records it.
           A name picks which pipeline; a scope narrows the one you picked.
        2. Call `next_step` repeatedly until `state` is `complete` or `halted`.
        3. When a step's `kind` is `skill`, invoke the skill it names, then call
           `report_step` with what you did. Until you do, the run stays `awaiting`
           and will not advance.
        4. Report the final tally exactly as `status` gives it.

        Two things you must not do:

        - Do not run a step's command yourself. The server executes shell steps and
          owns their verdicts; running them again costs time and proves nothing.
        - Do not summarise the run as a pass unless `all_verified` is true. `state:
          complete` means the walk finished, not that everything passed — a run can
          complete on acknowledgements alone. Report verified and acknowledged
          counts separately, and say plainly if the run `halted`.

        If a step fails, fix the cause and then call `open_run` again, not
        `next_step`. Do not work out whether your fix moved the tree: `open_run`
        hands back the run you were already in when nothing moved, and a fresh one
        when anything did — an edit, and equally a commit, an amend, a checkout or
        a rebase. `next_step` never re-checks, so carrying on finishes the walk
        with `all_verified: false` and a `stale` key, and the steps that passed
        before your fix no longer describe the code. Neither call sees a fix that
        touches only a git-ignored file such as `.env`; decide that one yourself. If a step reports it inspected 0 files, say so:
        it passed without proving anything.
        TXT);
    }
}
