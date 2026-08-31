<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

/**
 * What a run is doing right now, between two resolutions.
 *
 * The receipt cannot answer this. It is written after a position resolves, so a
 * long step is invisible while it runs, and a run whose first step is a skill step
 * has written nothing at all. This record stands alone for exactly that reason: it
 * carries the scope and the step ids a reader needs to render a run that has no
 * receipt yet.
 *
 * `token` is what makes a clear safe. Two server processes share a pipeline with no
 * lock, so a clear compares run id and token and deletes only its own record —
 * without it, one server's cleanup erases the record another just wrote.
 */
final readonly class LiveProgress
{
    /**
     * @param  list<string>  $stepIds  every step at the position, so a parallel group reads as one
     * @param  float|null  $timeoutSeconds  the resolved ceiling for this position, null when the
     *                                      runner declares none — a custom runner enforces no
     *                                      timeout, so its record never expires on age
     */
    public function __construct(
        public string $runId,
        public string $token,
        public RunState $state,
        public array $stepIds,
        public string $startedAt,
        public ?string $scope = null,
        public ?float $timeoutSeconds = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'run' => $this->runId,
            'token' => $this->token,
            'state' => $this->state->value,
            'steps' => $this->stepIds,
            'started_at' => $this->startedAt,
            'scope' => $this->scope,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $runId = $data['run'] ?? null;
        $token = $data['token'] ?? null;
        $state = RunState::tryFrom(is_string($data['state'] ?? null) ? $data['state'] : '');

        // A record missing any of these is not a partially usable record. Without
        // the token a clear cannot prove ownership, and without the state a reader
        // cannot tell a running step from a wait that never expires.
        if (! is_string($runId) || ! is_string($token) || ! $state instanceof RunState) {
            return null;
        }

        $scope = $data['scope'] ?? null;
        $timeout = $data['timeout_seconds'] ?? null;
        $startedAt = $data['started_at'] ?? null;

        return new self(
            runId: $runId,
            token: $token,
            state: $state,
            stepIds: self::readStepIds($data['steps'] ?? null),
            startedAt: is_string($startedAt) ? $startedAt : '',
            scope: is_string($scope) ? $scope : null,
            timeoutSeconds: is_int($timeout) || is_float($timeout) ? (float) $timeout : null,
        );
    }

    /**
     * Whether a reader should still believe this record.
     *
     * Only a running position expires, and only where the runner declared a
     * ceiling it enforces: that runner kills a step at the timeout, so a record
     * still open past it means the process died rather than that the step is slow.
     *
     * An awaiting record never expires, because the package deliberately does not
     * time out a skill step. One left by a killed process is the residue this
     * cannot detect — the reader is told how long it has waited instead.
     */
    public function hasExpired(float $margin = 30.0, ?int $now = null): bool
    {
        if ($this->state !== RunState::Running || $this->timeoutSeconds === null) {
            return false;
        }

        $startedAt = strtotime($this->startedAt);

        if ($startedAt === false) {
            return false;
        }

        return ($now ?? time()) - $startedAt > $this->timeoutSeconds + $margin;
    }

    /**
     * @return list<string>
     */
    private static function readStepIds(mixed $steps): array
    {
        if (! is_array($steps)) {
            return [];
        }

        return array_values(array_filter($steps, static fn (mixed $id): bool => is_string($id)));
    }
}
