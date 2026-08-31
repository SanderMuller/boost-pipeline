<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

/**
 * One past run, as history keeps it: a receipt plus the logs that run wrote.
 *
 * A bare {@see Receipt} would not do. `Receipt::fromArray()` builds from a fixed
 * key list and drops everything else, so a log map written into a history file
 * and parsed back into a `Receipt` would vanish on the way out — written, never
 * readable. Pairing them in one record is what makes the extra field survive.
 *
 * The pipeline name is deliberately absent. `history/<pipeline>/` names it by
 * construction, and {@see ReceiptStoreFactory} states the rule: storing the name
 * inside the file as well creates two sources of truth that can disagree.
 */
final readonly class HistoryRecord
{
    /**
     * @param  array<string, string|null>  $logs  step id => log path, null where the step wrote none
     */
    public function __construct(
        public Receipt $receipt,
        public array $logs = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...$this->receipt->toArray(), 'logs' => $this->logs];
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        $receipt = Receipt::fromArray($data);

        if (! $receipt instanceof Receipt) {
            return null;
        }

        return new self($receipt, self::readLogs($data['logs'] ?? null));
    }

    /**
     * A malformed map is dropped rather than rejected: the verdicts are the
     * answer, and a missing log link costs a reader one click, not the record.
     *
     * @return array<string, string|null>
     */
    private static function readLogs(mixed $logs): array
    {
        if (! is_array($logs)) {
            return [];
        }

        $safe = [];

        foreach ($logs as $stepId => $path) {
            if (is_string($stepId) && (is_string($path) || $path === null)) {
                $safe[$stepId] = $path;
            }
        }

        return $safe;
    }
}
