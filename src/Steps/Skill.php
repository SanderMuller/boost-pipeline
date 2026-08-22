<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Steps;

use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Results\Result;

/**
 * An instruction handed back to the invoking agent.
 *
 * The server cannot verify that the skill ran, so this step can only ever yield
 * Verdict::Acknowledged. That is the honest outcome, not a limitation to work
 * around: what the pipeline buys here is delivery at the right point in the
 * sequence, not verification.
 */
final class Skill implements Step
{
    private function __construct(
        private readonly string $invocation,
        private readonly string $id,
        private readonly ?string $description,
    ) {}

    public static function run(string $invocation, ?string $id = null, ?string $description = null): self
    {
        return new self($invocation, $id ?? self::deriveId($invocation), $description);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function description(): string
    {
        return $this->description ?? "Invoke {$this->invocation}";
    }

    public function kind(): StepKind
    {
        return StepKind::Skill;
    }

    public function invocation(): string
    {
        return $this->invocation;
    }

    public function before(): void {}

    public function after(Result $result): void {}

    private static function deriveId(string $invocation): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $invocation));

        $trimmed = trim($slug, '-');

        return $trimmed === '' ? 'skill' : $trimmed;
    }
}
