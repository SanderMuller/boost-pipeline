<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

/**
 * Slots shared by every pipeline server on this machine, one lock file each.
 *
 * A slot named `phpunit` with room for two is the files `phpunit.1.lock` and
 * `phpunit.2.lock`. A step holds one by holding an exclusive `flock` on it for as
 * long as it runs. The kernel drops that lock when the holder dies, however it
 * dies, so a killed server never leaves a slot taken.
 *
 * The directory is fixed rather than the temp directory, because each agent
 * session can run its server with a temp directory of its own, and a slot that
 * every session counts separately limits nothing.
 */
final readonly class MachineSlots
{
    public function __construct(private string $directory) {}

    public static function defaultDirectory(): string
    {
        return DIRECTORY_SEPARATOR === '\\'
            ? sys_get_temp_dir().'\\boost-pipeline-slots'
            : '/tmp/boost-pipeline-slots';
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * One free slot of this name, or null when all of them are taken.
     *
     * @throws SlotUnavailable when no slot of this name can be held at all, so
     *                         that a step does not wait for one forever
     */
    public function take(string $name, int $capacity): ?SlotHold
    {
        if (! $this->ensureDirectory()) {
            throw SlotUnavailable::directory($this->directory);
        }

        $opened = false;

        for ($number = 1; $number <= $capacity; $number++) {
            $handle = $this->open(sprintf('%s/%s.%d.lock', $this->directory, $name, $number));

            if ($handle === null) {
                continue;
            }

            $opened = true;

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return new SlotHold($handle);
            }

            fclose($handle);
        }

        if (! $opened) {
            throw SlotUnavailable::files($this->directory, $name);
        }

        return null;
    }

    /**
     * The lock file, opened without following a link.
     *
     * The directory is shared by every user on the machine, so a name in it can
     * be a link someone else placed. The open is checked against the name it
     * came from: the same file, and not a link.
     *
     * @return resource|null
     */
    private function open(string $path): mixed
    {
        if (is_link($path)) {
            return null;
        }

        // A lock needs no write access, so another user's lock file still counts.
        // Close-on-exec (`e`), or every step the server starts inherits the lock
        // and keeps the slot taken after the server dies.
        $handle = @fopen($path, 'ce');

        if ($handle === false) {
            $handle = @fopen($path, 're');
        }

        if ($handle === false) {
            return null;
        }

        $opened = fstat($handle);
        $named = @lstat($path);

        // lstat() describes a link itself, so a link swapped in after the
        // first check names another inode than the file that was opened.
        if ($opened === false || $named === false || $opened['ino'] !== $named['ino'] || $opened['dev'] !== $named['dev']) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    private function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return true;
        }

        if (! @mkdir($this->directory, 0777, true) && ! is_dir($this->directory)) {
            return false;
        }

        // mkdir applies the umask. Sticky, like /tmp, so one user cannot delete
        // another user's lock file.
        @chmod($this->directory, 01777);

        return true;
    }
}
