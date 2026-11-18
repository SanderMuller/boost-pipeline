<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Config;

/**
 * Whether `vendor/` holds what `composer.lock` names.
 *
 * A run verifies the code with the packages that are installed, not the ones the
 * lock file names. After a merge or a checkout that changed the lock, nothing
 * installs the new packages until someone runs `composer install`, and a run
 * opened in between verifies against dependencies the lock no longer names.
 *
 * It also tells when an install is still running. Composer writes
 * `installed.json` after each package operation, so a half-finished install
 * shows as a mismatch. In a Laravel app `package:discover` rewrites
 * `bootstrap/cache/packages.php` after the autoloader dump, so a manifest older
 * than `installed.json` means the scripts have not finished. That second check
 * applies only when `composer.json` runs `package:discover` after the autoloader
 * dump. Laravel itself writes the manifest only when the file is missing, so in a
 * project without that hook the manifest is older after every install, for good.
 *
 * A project without a readable lock file or `installed.json` counts as a match.
 * There is nothing to compare, and refusing would block it for good.
 */
final readonly class InstallState
{
    /**
     * Starts every digest. Receipts store digests, so a change to what a digest
     * covers takes a new prefix, and the gate skips a digest it cannot compare.
     */
    public const string DIGEST_FORMAT = 'xxh128-v1:';

    /** How many differing packages a message names; it counts the rest. */
    private const int NAMED = 5;

    public function __construct(private string $basePath) {}

    /**
     * Why `vendor/` does not match the lock, one line per difference; empty when it matches.
     *
     * @return list<string>
     */
    public function differences(): array
    {
        $lock = $this->lockPackages();
        $installed = $this->installedPackages();

        if ($lock === null || $installed === null) {
            return [];
        }

        $differences = [];

        foreach ($lock as $name => $identity) {
            $have = $installed['packages'][$name] ?? null;

            if ($have === null) {
                $differences[] = sprintf('%s %s is not installed', $name, $identity['version']);
            } elseif ($have !== $identity) {
                $differences[] = sprintf('%s is %s, composer.lock names %s', $name, $this->describe($have), $this->describe($identity));
            }
        }

        foreach (array_diff_key($installed['packages'], $lock) as $name => $identity) {
            $differences[] = sprintf('%s %s is installed but not in composer.lock', $name, $identity['version']);
        }

        // Read on every check: a branch switch can add or remove the hook.
        if ($this->discoversAfterDump() && $this->manifestInStep() === false) {
            $differences[] = 'bootstrap/cache/packages.php is older than vendor/composer/installed.json, so package:discover has not run since the install';
        }

        return $differences;
    }

    /**
     * The differences as one sentence fragment, naming at most NAMED of them.
     *
     * @param  list<string>  $differences
     */
    public static function summarise(array $differences): string
    {
        $named = array_slice($differences, 0, self::NAMED);
        $rest = count($differences) - count($named);

        return implode('; ', $named).($rest > 0 ? sprintf('; and %d more', $rest) : '');
    }

    /**
     * A hash of what is installed, and whether that install has the dev packages; null when the lock or installed.json cannot be read.
     *
     * @return array{digest: string, dev: bool}|null
     */
    public function installedDigest(): ?array
    {
        if ($this->lockPackages() === null) {
            return null;
        }

        $installed = $this->installedPackages();

        if ($installed === null) {
            return null;
        }

        return ['digest' => $this->digest($installed['packages']), 'dev' => $installed['dev']];
    }

    /** The same hash computed from `composer.lock`, or null when the lock cannot be read. */
    public function lockDigest(bool $dev): ?string
    {
        $lock = $this->lockPackages($dev);

        return $lock === null ? null : $this->digest($lock);
    }

    /** @param  array<string, array{version: string, reference: string|null}>  $packages */
    private function digest(array $packages): string
    {
        ksort($packages);

        return self::DIGEST_FORMAT.hash('xxh128', (string) json_encode($packages));
    }

    /**
     * The packages `composer.lock` names. $dev decides whether the dev packages count; null follows the installed set.
     *
     * @return array<string, array{version: string, reference: string|null}>|null
     */
    private function lockPackages(?bool $dev = null): ?array
    {
        $lock = $this->readJson($this->basePath.'/composer.lock');

        if ($lock === null || ! is_array($lock['packages'] ?? null)) {
            return null;
        }

        $dev ??= $this->installedPackages()['dev'] ?? true;
        $entries = $lock['packages'];

        if ($dev && is_array($lock['packages-dev'] ?? null)) {
            $entries = [...$entries, ...$lock['packages-dev']];
        }

        return $this->identities($entries);
    }

    /** @return array{packages: array<string, array{version: string, reference: string|null}>, dev: bool}|null */
    private function installedPackages(): ?array
    {
        $installed = $this->readJson($this->installedPath());

        if ($installed === null || ! is_array($installed['packages'] ?? null)) {
            return null;
        }

        return ['packages' => $this->identities($installed['packages']), 'dev' => ($installed['dev'] ?? true) !== false];
    }

    /**
     * What picks a package's code: its version and its commit, keyed by name.
     *
     * Read the same way from both files, so the identities compare. A path
     * repository may have no reference.
     *
     * @param  array<mixed>  $entries
     * @return array<string, array{version: string, reference: string|null}>
     */
    private function identities(array $entries): array
    {
        $identities = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null)) {
                continue;
            }

            $source = is_array($entry['source'] ?? null) ? $entry['source']['reference'] ?? null : null;
            $dist = is_array($entry['dist'] ?? null) ? $entry['dist']['reference'] ?? null : null;
            $reference = $source ?? $dist;

            $identities[$entry['name']] = [
                'version' => is_string($entry['version'] ?? null) ? $entry['version'] : '',
                'reference' => is_string($reference) ? $reference : null,
            ];
        }

        return $identities;
    }

    /** @param  array{version: string, reference: string|null}  $identity */
    private function describe(array $identity): string
    {
        return $identity['reference'] === null ? $identity['version'] : sprintf('%s (%s)', $identity['version'], substr($identity['reference'], 0, 10));
    }

    /** Whether a `post-autoload-dump` script, or a script it calls with `@`, runs `package:discover`. */
    private function discoversAfterDump(): bool
    {
        $scripts = $this->readJson($this->basePath.'/composer.json')['scripts'] ?? null;

        if (! is_array($scripts)) {
            return false;
        }

        $queue = ['post-autoload-dump'];
        $seen = [];

        while (($name = array_shift($queue)) !== null) {
            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;

            foreach ((array) ($scripts[$name] ?? []) as $command) {
                if (! is_string($command)) {
                    continue;
                }

                if (str_contains($command, 'package:discover')) {
                    return true;
                }

                if (str_starts_with($command, '@') && ! str_starts_with($command, '@php')) {
                    $queue[] = (string) strtok(substr($command, 1), ' ');
                }
            }
        }

        return false;
    }

    /** Whether the manifest is at least as new as `installed.json`; null when either is missing. */
    private function manifestInStep(): ?bool
    {
        clearstatcache();

        $manifest = @filemtime($this->basePath.'/bootstrap/cache/packages.php');
        $installed = @filemtime($this->installedPath());

        if ($manifest === false || $installed === false) {
            return null;
        }

        return $manifest >= $installed;
    }

    private function installedPath(): string
    {
        return $this->basePath.'/vendor/composer/installed.json';
    }

    /** @return array<mixed>|null */
    private function readJson(string $path): ?array
    {
        $json = @file_get_contents($path);
        $decoded = $json === false ? null : json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
