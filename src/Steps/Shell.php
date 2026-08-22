<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Steps;

use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Enums\StepKind;
use SanderMuller\BoostPipeline\Results\Result;

final class Shell implements Step
{
    /**
     * Commands whose first token is only a runner, so the interesting name is the
     * token after it: `yarn lint-all` should be `lint-all`, not `yarn`.
     *
     * @var list<string>
     */
    private const array RUNNERS = ['yarn', 'npm', 'npx', 'pnpm', 'bun', 'composer', 'php', 'node'];

    private ?string $scopeCommand = null;

    private function __construct(
        private readonly string $command,
        private readonly string $id,
        private readonly ?string $description,
    ) {}

    public static function run(string $command, ?string $id = null, ?string $description = null): self
    {
        return new self($command, $id ?? self::deriveId($command), $description);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function description(): string
    {
        return $this->description ?? $this->command;
    }

    public function kind(): StepKind
    {
        return StepKind::Shell;
    }

    public function command(): string
    {
        return $this->command;
    }

    /**
     * A command whose stdout lines enumerate what this step will inspect.
     *
     * Only way to know a git-diff-scoped step checked nothing: there is no
     * generic way to infer it from a tool's own output. Without this, the step's
     * inspected count stays unknown rather than being reported as zero.
     */
    public function inspecting(string $command): self
    {
        $this->scopeCommand = $command;

        return $this;
    }

    public function scopeCommand(): ?string
    {
        return $this->scopeCommand;
    }

    public function before(): void {}

    public function after(Result $result): void {}

    /**
     * Best-effort id from a command string. Explicit ids win; this only has to be
     * stable and readable, because it names log files and appears in responses.
     */
    private static function deriveId(string $command): string
    {
        $tokens = preg_split('/\s+/', trim($command)) ?: [];
        $tokens = array_values(array_filter($tokens, static fn (string $t): bool => $t !== ''));

        if ($tokens === []) {
            return 'step';
        }

        $first = basename($tokens[0]);
        $candidate = in_array($first, self::RUNNERS, true) && isset($tokens[1])
            ? $tokens[1]
            : $first;

        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', basename($candidate)));

        return trim($slug, '-') ?: 'step';
    }
}
