<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use Closure;
use Illuminate\Support\Sleep;
use SanderMuller\BoostPipeline\Contracts\PendingSteps;
use SanderMuller\BoostPipeline\Contracts\WaitsForSlots;
use SanderMuller\BoostPipeline\Results\Result;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Processes {@see ProcessStepRunner::begin()} started, settled one poll at a time.
 *
 * The exit status is read BEFORE the timeout on every poll. Symfony enforces a
 * timeout only when something polls the process, and between two tool calls
 * nothing does. A step can finish inside its ceiling and first be polled after
 * it; checking the timeout first would then report that verdict as a timeout.
 * A step still running when it is polled past its ceiling is killed and
 * reported as timed out, as a blocking wait does.
 *
 * The one difference from a blocking wait: a step that crosses its ceiling and
 * then finishes while nobody polls keeps the verdict it produced. The moment it
 * exited is not observable here, and a timeout means the tool never produced a
 * verdict — this one did.
 *
 * @internal
 */
final class ProcessPendingSteps implements PendingSteps, WaitsForSlots
{
    private const float POLL_SECONDS = 0.1;

    /** A wait between two polls longer than this means nobody was polling. */
    private const float GAP_SECONDS = 1.0;

    private ?float $lastPoll = null;

    /**
     * @param  list<string>  $order  step ids in the order they were given
     * @param  array<string, Result>  $settled  steps that resolved before anything ran
     * @param  array<string, array{Process, Closure(?Throwable, float, bool): Result, Closure(): void, Closure(): void}>  $running  the process, what turns it into a verdict (given when it was first seen finished, and whether that followed a gap), what discards it, and what cleans up after a step stopped before it ended
     * @param  array<string, array{string, Closure(): (array{Process, Closure(?Throwable, float, bool): Result, Closure(): void, Closure(): void}|Result|null)}>  $queued  steps waiting for a slot: its name, and what starts the step once one is free, null while none is
     */
    public function __construct(
        private readonly array $order,
        private array $settled,
        private array $running,
        private array $queued = [],
    ) {}

    public function waitingForSlots(): array
    {
        return array_map(static fn (array $entry): string => $entry[0], $this->queued);
    }

    public function await(float $seconds): ?array
    {
        $deadline = microtime(true) + max(0.0, $seconds);

        while (true) {
            $this->poll();

            if ($this->running === [] && $this->queued === []) {
                $ordered = [];

                foreach ($this->order as $stepId) {
                    $ordered[$stepId] = $this->settled[$stepId];
                }

                return $ordered;
            }

            $left = $deadline - microtime(true);

            if ($left <= 0.0) {
                return null;
            }

            Sleep::usleep((int) (min($left, self::POLL_SECONDS) * 1_000_000));
        }
    }

    public function stop(): void
    {
        // Never started, so there is nothing to stop and nothing to clean up.
        $this->queued = [];

        foreach ($this->running as [$process, , $discard, $abort]) {
            // A step that already ended on its own did its own cleanup.
            if ($this->stillRunning($process)) {
                ProcessTree::terminate($process);
                $abort();
            }

            $discard();
        }

        $this->running = [];
    }

    /**
     * A session that ends before the verdict is collected still removes what it
     * started: the processes, and the files holding their output.
     */
    public function __destruct()
    {
        $this->stop();
    }

    private function pastTimeout(Process $process, float $now): bool
    {
        $timeout = $process->getTimeout();

        return $timeout !== null && $now - $process->getStartTime() > $timeout;
    }

    private function stillRunning(Process $process): bool
    {
        try {
            return $process->isRunning();
        } catch (Throwable) {
            return false;
        }
    }

    private function poll(): void
    {
        foreach ($this->queued as $stepId => [, $start]) {
            $started = $start();

            if ($started === null) {
                continue;
            }

            unset($this->queued[$stepId]);

            if ($started instanceof Result) {
                $this->settled[$stepId] = $started;
            } else {
                $this->running[$stepId] = $started;
            }
        }

        $now = microtime(true);
        $late = $this->lastPoll !== null && $now - $this->lastPoll > self::GAP_SECONDS;
        $this->lastPoll = $now;

        // Every process is looked at, and every one past its timeout stopped,
        // before any verdict is built. Building one can run an onAbort command,
        // and the others would otherwise wait behind it, unwatched. One that
        // crosses its timeout while that command runs is stopped at the next poll.
        $ended = [];

        foreach ($this->running as $stepId => [$process]) {
            $seenAt = microtime(true);

            try {
                $failure = null;

                if ($process->isRunning()) {
                    if (! $this->pastTimeout($process, $seenAt)) {
                        continue;
                    }

                    // Stopped here rather than by Symfony's checkTimeout(), which
                    // signals the shell alone and loses the processes below it.
                    ProcessTree::terminate($process);
                    $failure = new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
                }
            } catch (Throwable $throwable) {
                $failure = $throwable;
            }

            $ended[$stepId] = [$failure, $seenAt];
        }

        foreach ($ended as $stepId => [$failure, $seenAt]) {
            $this->settled[$stepId] = $this->running[$stepId][1]($failure, $seenAt, $late);
            unset($this->running[$stepId]);
        }
    }
}
