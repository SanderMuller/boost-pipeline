<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Stops a step's process together with every process it started.
 *
 * Symfony signals only the process it started, which for a step is the shell.
 * A test runner below that shell, and the workers it forked, then live on with
 * the database and the CPU they hold. So the tree is read BEFORE anything is
 * signalled: once the shell is gone its children belong to pid 1, and nothing
 * links them to the step any more.
 *
 * A process that left the tree on its own, with `setsid` or a double fork, is
 * out of reach. Windows has no parent links to read here, so it stops the root.
 *
 * @internal
 */
final class ProcessTree
{
    private const int SIGTERM = 15;

    private const int SIGKILL = 9;

    /** How long a process gets to exit on SIGTERM before SIGKILL. */
    private const float GRACE_SECONDS = 2.0;

    private const float POLL_SECONDS = 0.05;

    public static function terminate(Process $process): void
    {
        $pid = $process->getPid();

        if ($pid === null || DIRECTORY_SEPARATOR === '\\') {
            self::stopRoot($process);

            return;
        }

        $descendants = self::descendants($pid);

        self::signal([$pid, ...$descendants], self::SIGTERM);

        $deadline = microtime(true) + self::GRACE_SECONDS;

        // The root is asked through Symfony: until it is reaped it stays a zombie,
        // which signal 0 still reports as existing.
        while (microtime(true) < $deadline && (self::rootRunning($process) || array_filter($descendants, self::exists(...)) !== [])) {
            Sleep::usleep((int) (self::POLL_SECONDS * 1_000_000));
        }

        self::signal([
            ...(self::rootRunning($process) ? [$pid] : []),
            ...array_values(array_filter($descendants, self::exists(...))),
        ], self::SIGKILL);

        // Lets Symfony read the exit status, so the Process object agrees.
        self::stopRoot($process);
    }

    /**
     * Whether a process with this id exists.
     *
     * Without the posix extension there is no cheap way to ask, so it answers
     * yes: a caller then keeps waiting or keeps a record, never loses one.
     */
    public static function exists(int $pid): bool
    {
        if (! function_exists('posix_kill') || ! function_exists('posix_get_last_error')) {
            return true;
        }

        // Signal 0 checks without sending. EPERM means it exists under another user.
        return posix_kill($pid, 0) || posix_get_last_error() === 1;
    }

    /** @return list<int> */
    private static function descendants(int $root): array
    {
        $children = self::childrenByParent();
        $found = [];
        $queue = [$root];

        while ($queue !== []) {
            $parent = array_shift($queue);

            foreach ($children[$parent] ?? [] as $child) {
                if (! in_array($child, $found, true) && $child !== $root) {
                    $found[] = $child;
                    $queue[] = $child;
                }
            }
        }

        return $found;
    }

    /**
     * Every process id, grouped by its parent.
     *
     * From /proc where it exists, because slim Linux images ship no `ps`. From
     * `ps` everywhere else, which is macOS and the BSDs.
     *
     * @return array<int, list<int>>
     */
    private static function childrenByParent(): array
    {
        $pairs = is_dir('/proc/self') ? self::fromProc() : self::fromPs();
        $children = [];

        foreach ($pairs as [$pid, $parent]) {
            $children[$parent][] = $pid;
        }

        return $children;
    }

    /** @return list<array{int, int}> */
    private static function fromProc(): array
    {
        $pairs = [];

        $files = glob('/proc/[0-9]*/stat');

        foreach ($files === false ? [] : $files as $file) {
            $stat = @file_get_contents($file);

            // The name in parentheses can hold spaces and parentheses itself, so
            // the fields are read after the LAST closing one: state, then ppid.
            $close = $stat === false ? false : strrpos($stat, ')');

            if ($stat === false || $close === false) {
                continue;
            }

            $fields = explode(' ', trim(substr($stat, $close + 2)));
            $pairs[] = [(int) basename(dirname($file)), (int) ($fields[1] ?? 0)];
        }

        return $pairs;
    }

    /** @return list<array{int, int}> */
    private static function fromPs(): array
    {
        try {
            $ps = new Process(['ps', '-A', '-o', 'pid=', '-o', 'ppid=']);
            $ps->setTimeout(10);
            $ps->run();
        } catch (Throwable) {
            return [];
        }

        $pairs = [];

        foreach (explode("\n", trim($ps->getOutput())) as $line) {
            $fields = preg_split('/\s+/', trim($line));

            if (is_array($fields) && count($fields) === 2 && ctype_digit($fields[0]) && ctype_digit($fields[1])) {
                $pairs[] = [(int) $fields[0], (int) $fields[1]];
            }
        }

        return $pairs;
    }

    /** @param list<int> $pids */
    private static function signal(array $pids, int $signal): void
    {
        if ($pids === []) {
            return;
        }

        if (function_exists('posix_kill')) {
            foreach ($pids as $pid) {
                @posix_kill($pid, $signal);
            }

            return;
        }

        try {
            new Process(['kill', '-'.$signal, ...array_map(strval(...), $pids)])->run();
        } catch (Throwable) {
            // A process already gone is the outcome this asks for.
        }
    }

    private static function rootRunning(Process $process): bool
    {
        try {
            return $process->isRunning();
        } catch (Throwable) {
            return false;
        }
    }

    private static function stopRoot(Process $process): void
    {
        try {
            $process->stop(0);
        } catch (Throwable) {
            // Already gone is the outcome stop() asks for.
        }
    }
}
