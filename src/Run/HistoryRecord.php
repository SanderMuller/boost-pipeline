<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Run;

use SanderMuller\BoostPipeline\Runner\ProcessTree;

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
    /** Shown in place of the receipt's state for a run nobody will walk any further. */
    public const string ABANDONED = 'abandoned';

    /**
     * @param  array<string, string|null>  $logs  step id => log path, null where the step wrote none
     * @param  int|null  $owner  the process id of the server walking the run, null for a record written before 0.22
     * @param  bool  $abandoned  the server replaced the run before it finished
     */
    public function __construct(
        public Receipt $receipt,
        public array $logs = [],
        public ?int $owner = null,
        public bool $abandoned = false,
    ) {}

    public function asAbandoned(): self
    {
        return new self($this->receipt, $this->logs, $this->owner, abandoned: true);
    }

    /**
     * The receipt's state, or `abandoned` for an unfinished run nobody walks.
     *
     * Several servers can share one project's storage, so a run this process does
     * not hold may belong to another live one. Only a dead owner proves the run
     * was left behind. Without a way to ask, the owner counts as alive: a run
     * shown as running is what readers saw before, and never hides a live walk.
     */
    public function shownState(): string
    {
        if (RunState::tryFrom($this->receipt->state)?->isTerminal() === true) {
            return $this->receipt->state;
        }

        return $this->abandoned || ($this->owner !== null && ! ProcessTree::exists($this->owner))
            ? self::ABANDONED
            : $this->receipt->state;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [...$this->receipt->toArray(), 'logs' => $this->logs, 'owner' => $this->owner, 'abandoned' => $this->abandoned];
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

        $owner = $data['owner'] ?? null;

        return new self(
            $receipt,
            self::readLogs($data['logs'] ?? null),
            is_int($owner) && $owner > 0 ? $owner : null,
            ($data['abandoned'] ?? false) === true,
        );
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
