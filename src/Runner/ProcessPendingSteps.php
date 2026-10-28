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
use WeakReference;

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
 * A machine-wide slot is freed when its step's process ends, not when the
 * verdict is collected: an agent that stops calling after a `pending` answer
 * would otherwise keep the slot taken until its server exits. A SIGCHLD handler
 * does that, so it needs ext-pcntl. Without it the slot frees at collection.
 *
 * @internal
 */
final class ProcessPendingSteps implements PendingSteps, WaitsForSlots
{
    private const float POLL_SECONDS = 0.1;

    /** A wait between two polls longer than this means nobody was polling. */
    private const float GAP_SECONDS = 1.0;

    private ?float $lastPoll = null;

    private bool $watching = false;

    /**
     * Every instance the one handler serves. Several runs can each have a step
     * running at once, and one handler per instance would restore the others'
     * handlers out of order. Weak, so a dropped instance still destructs and
     * stops what it started.
     *
     * @var array<int, WeakReference<self>>
     */
    private static array $watchers = [];

    /**
     * The SIGCHLD handler that was in place before, restored after. 0 is SIG_DFL.
     *
     * @var callable|int
     */
    private static mixed $previousHandler = 0;

    private static bool $previousAsync = false;

    /**
     * @param  list<string>  $order  step ids in the order they were given
     * @param  array<string, Result>  $settled  steps that resolved before anything ran
     * @param  array<string, array{Process, Closure(?Throwable, float, bool): Result, Closure(): void, Closure(): void, (Closure(): void)|null}>  $running  the process, what turns it into a verdict (given when it was first seen finished, and whether that followed a gap), what discards it, what cleans up after a step stopped before it ended, and what frees its slot, null when it holds none
     * @param  array<string, array{string, Closure(): (array{Process, Closure(?Throwable, float, bool): Result, Closure(): void, Closure(): void, (Closure(): void)|null}|Result|null)}>  $queued  steps waiting for a slot: its name, and what starts the step once one is free, null while none is
     */
    public function __construct(
        private readonly array $order,
        private array $settled,
        private array $running,
        private array $queued = [],
    ) {
        $this->watch();
    }

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
        // First, so no handler reads a process while it is stopped.
        $this->stopWatching();

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
        $this->startQueued();

        $now = microtime(true);
        $late = $this->lastPoll !== null && $now - $this->lastPoll > self::GAP_SECONDS;
        $this->lastPoll = $now;

        // Only the status reads hold SIGCHLD back. A process started while it is
        // held inherits the mask, and an onAbort command or a queued step must
        // not run with SIGCHLD blocked.
        $ended = $this->withSignalsHeld($this->ended(...));

        foreach ($ended as $stepId => [$failure, $seenAt]) {
            $this->settled[$stepId] = $this->running[$stepId][1]($failure, $seenAt, $late);
            unset($this->running[$stepId]);
        }

        $this->watch();

        if (! $this->holdsSlot()) {
            $this->stopWatching();
        }
    }

    /**
     * Frees the slot of every step whose process ended, and builds no verdict.
     *
     * Runs from a signal handler, between any two statements of the code around
     * it. So every other read of these processes holds SIGCHLD back while it runs
     * ({@see withSignalsHeld()}): Symfony updates and closes a process as it reads
     * its status, and a nested read would act on a half-updated one.
     */
    private function freeEndedSlots(): void
    {
        foreach ($this->running as [$process, , , , $freeSlot]) {
            if ($freeSlot instanceof Closure && ! $this->stillRunning($process)) {
                $freeSlot();
            }
        }
    }

    private function watch(): void
    {
        if ($this->watching || ! $this->holdsSlot() || ! $this->canWatch()) {
            return;
        }

        if (self::$watchers === []) {
            self::$previousHandler = pcntl_signal_get_handler(SIGCHLD);
            self::$previousAsync = pcntl_async_signals(true);
            // Static, so the handler holds no instance alive.
            pcntl_signal(SIGCHLD, static function (int $signal, mixed $info = null): void {
                if (is_callable(self::$previousHandler)) {
                    (self::$previousHandler)($signal, $info);
                }

                foreach (self::$watchers as $watcher) {
                    $watcher->get()?->freeEndedSlots();
                }
            });
        }

        self::$watchers[spl_object_id($this)] = WeakReference::create($this);
        $this->watching = true;
    }

    private function stopWatching(): void
    {
        if (! $this->watching) {
            return;
        }

        unset(self::$watchers[spl_object_id($this)]);
        $this->watching = false;

        if (self::$watchers === []) {
            pcntl_signal(SIGCHLD, self::$previousHandler);
            pcntl_async_signals(self::$previousAsync);
            self::$previousHandler = 0;
        }
    }

    /** Every pcntl function the handler and the mask call, which disable_functions can turn off one by one. */
    private function canWatch(): bool
    {
        return array_all(['pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals', 'pcntl_sigprocmask'], fn (string $function) => function_exists($function));
    }

    private function holdsSlot(): bool
    {
        return array_any($this->running, fn (array $entry) => $entry[4] instanceof Closure);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    private function withSignalsHeld(Closure $work): mixed
    {
        if (! $this->watching) {
            return $work();
        }

        $old = [];
        pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $old);
        $alreadyHeld = is_array($old) && in_array(SIGCHLD, $old, true);

        try {
            return $work();
        } finally {
            // Linux delivers a SIGCHLD that arrived meanwhile here; macOS drops
            // it once the child is reaped.
            if (! $alreadyHeld) {
                pcntl_sigprocmask(SIG_UNBLOCK, [SIGCHLD]);
            }
        }
    }

    private function startQueued(): void
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
    }

    /**
     * The running steps that ended, each with its failure and when it was seen.
     *
     * Each one leaves the handler's sight while SIGCHLD is still held. Linux
     * delivers the SIGCHLD of a process reaped here once it is released, and the
     * handler would free the slot of a timed-out step before its onAbort command
     * runs. The verdict closure frees it after that command.
     *
     * Every process is looked at, and every one past its timeout stopped, before
     * any verdict is built. Building one can run an onAbort command, and the
     * others would otherwise wait behind it, unwatched. One that crosses its
     * timeout while that command runs is stopped at the next poll.
     *
     * @return array<string, array{?Throwable, float}>
     */
    private function ended(): array
    {
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
            $this->running[$stepId][4] = null;
        }

        return $ended;
    }
}
