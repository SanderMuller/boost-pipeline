<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Steps;

use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;

/**
 * An instruction handed back to the invoking agent.
 *
 * The server cannot verify that the skill ran, so this step can only ever yield
 * Verdict::Acknowledged. That is the honest outcome, not a limitation to work
 * around: what the pipeline buys here is delivery at the right point in the
 * sequence, not verification.
 */
final readonly class Skill implements Step
{
    private function __construct(
        private string $invocation,
        private string $id,
        private ?string $instruction,
        private bool $mutates = false,
        private ?string $proof = null,
        /** @var list<string> */
        private array $tags = [],
    ) {}

    /**
     * @param  string|null  $instruction  What this step is for, handed to the agent verbatim.
     *                                    This is the focus mechanism: a step that says
     *                                    "review only the error handling in files changed
     *                                    since main" narrows attention the way a broad
     *                                    skill invocation cannot. Reaches the agent in the
     *                                    step payload, so write it for the agent to act on.
     */
    public static function run(string $invocation, ?string $id = null, ?string $instruction = null): self
    {
        return new self($invocation, $id ?? self::deriveId($invocation), $instruction);
    }

    /**
     * Declare that the agent is expected to change code during this step.
     *
     * A fixing skill — `/evaluate` and the like — genuinely rewrites the tree, so
     * without this the run reports stale every time the skill does its job. Note
     * what declaring it costs: verdicts earned BEFORE this step were measured
     * against different code, and saying the change is expected does not make
     * them true again. Put fixing steps ahead of the checks that must see the
     * result, which is what the default phase order already does.
     */
    public function mutating(): self
    {
        return new self($this->invocation, $this->id, $this->instruction, true, $this->proof, $this->tags);
    }

    /**
     * A command that must exit 0 before this step counts as done.
     *
     * This is the only way a skill step earns `passed` rather than
     * `acknowledged`. Where the work leaves a side effect — screenshots on disk,
     * a harness log, a commit — the server can check for it and produce a verdict
     * it owns, with no model call and nothing taken on trust:
     *
     *     Skill::run('/eye-verification')
     *         ->proving('find storage/verify -name "*.png" -newer .git/HEAD | grep -q .')
     *
     * A failing proof blocks the run and returns the same step, so "I did it"
     * without the artifact is not a way past the cursor. Steps whose work leaves
     * nothing to find keep `acknowledged`, which is the honest verdict for them.
     */
    public function proving(string $command): self
    {
        return new self($this->invocation, $this->id, $this->instruction, $this->mutates, $command, $this->tags);
    }

    /**
     * Declare which scopes this step belongs to.
     *
     * A step with no tag runs in every scope, so tagging one step never drops the
     * ones that carry none. Matching is case-sensitive.
     */
    public function tagged(string ...$tags): self
    {
        foreach ($tags as $tag) {
            if (trim($tag) === '') {
                throw InvalidPipelineConfigException::emptyTag($this->id);
            }
        }

        return new self(
            $this->invocation,
            $this->id,
            $this->instruction,
            $this->mutates,
            $this->proof,
            array_values(array_unique([...$this->tags, ...$tags])),
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->tags;
    }

    public function proof(): ?string
    {
        return $this->proof;
    }

    public function mutates(): bool
    {
        return $this->mutates;
    }

    public function id(): string
    {
        return $this->id;
    }

    /** The instruction where one was given, and a bare invocation otherwise. */
    public function description(): string
    {
        return $this->instruction ?? "Invoke {$this->invocation}";
    }

    public function kind(): StepKind
    {
        return StepKind::Skill;
    }

    public function invocation(): string
    {
        return $this->invocation;
    }

    private static function deriveId(string $invocation): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $invocation));

        $trimmed = trim($slug, '-');

        return $trimmed === '' ? 'skill' : $trimmed;
    }
}
