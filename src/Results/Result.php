<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Results;

use SanderMuller\BoostPipeline\Enums\Verdict;

final readonly class Result
{
    public function __construct(
        public Verdict $verdict,
        public string $stepId,
        public string $summary,
        public ?int $exitCode = null,
        public ?string $logPath = null,
        /**
         * How many files the step actually inspected, when that is knowable.
         *
         * null means unknown, and is NOT the same as 0. Defaulting to 0 would
         * make every step look like it inspected nothing, which is the exact
         * signal a vacuous pass is supposed to raise.
         */
        public ?int $filesInspected = null,
        public ?string $reason = null,
    ) {}

    public static function passed(string $stepId, string $summary, ?int $filesInspected = null, ?string $logPath = null): self
    {
        return new self(Verdict::Passed, $stepId, $summary, exitCode: 0, logPath: $logPath, filesInspected: $filesInspected);
    }

    public static function failed(string $stepId, string $summary, int $exitCode, ?string $logPath = null, ?int $filesInspected = null): self
    {
        return new self(Verdict::Failed, $stepId, $summary, exitCode: $exitCode, logPath: $logPath, filesInspected: $filesInspected);
    }

    public static function error(string $stepId, string $reason, ?string $logPath = null): self
    {
        return new self(Verdict::Error, $stepId, $reason, logPath: $logPath, reason: $reason);
    }

    public static function acknowledged(string $stepId, string $summary): self
    {
        return new self(Verdict::Acknowledged, $stepId, $summary, reason: $summary);
    }

    /**
     * Whether the server produced this verdict by executing something.
     *
     * True for passed, failed AND error — it answers "who ran it", not "did it
     * pass". Keep it distinct from Verdict::isVerified().
     */
    public function serverRun(): bool
    {
        return $this->verdict !== Verdict::Acknowledged;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'verdict' => $this->verdict->value,
            'step_id' => $this->stepId,
            'summary' => $this->summary,
            'exit_code' => $this->exitCode,
            'log' => $this->logPath,
            'files_inspected' => $this->filesInspected,
            'server_run' => $this->serverRun(),
            'reason' => $this->reason,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
