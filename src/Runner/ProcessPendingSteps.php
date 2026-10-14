<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use Closure;
use Illuminate\Support\Sleep;
use SanderMuller\BoostPipeline\Contracts\PendingSteps;
use SanderMuller\BoostPipeline\Results\Result;
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
final class ProcessPendingSteps implements PendingSteps
{
    private const float POLL_SECONDS = 0.1;

    /** A wait between two polls longer than this means nobody was polling. */
    private const float GAP_SECONDS = 1.0;

    private ?float $lastPoll = null;

    /**
     * @param  list<string>  $order  step ids in the order they were given
     * @param  array<string, Result>  $settled  steps that resolved before anything ran
     * @param  array<string, array{Process, Closure(?Throwable, float, bool): Result, Closure(): void}>  $running  the process, what turns it into a verdict (given when it was first seen finished, and whether that followed a gap), and what discards it
     */
    public function __construct(
        private readonly array $order,
        private array $settled,
        private array $running,
    ) {}

    public function await(float $seconds): ?array
    {
        $deadline = microtime(true) + max(0.0, $seconds);

        while (true) {
            $this->poll();

            if ($this->running === []) {
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
        foreach ($this->running as [$process, , $discard]) {
            try {
                $process->stop(0);
            } catch (Throwable) {
                // Already gone is the outcome stop() asks for.
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

    private function poll(): void
    {
        $now = microtime(true);
        $late = $this->lastPoll !== null && $now - $this->lastPoll > self::GAP_SECONDS;
        $this->lastPoll = $now;

        foreach ($this->running as $stepId => [$process, $settle]) {
            try {
                if ($process->isRunning()) {
                    $process->checkTimeout();

                    continue;
                }

                $failure = null;
            } catch (Throwable $throwable) {
                $failure = $throwable;
            }

            $this->settled[$stepId] = $settle($failure, $now, $late);
            unset($this->running[$stepId]);
        }
    }
}
