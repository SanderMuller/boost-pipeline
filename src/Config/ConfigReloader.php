<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Config;

use Closure;
use ParseError;
use PhpToken;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use Throwable;

/**
 * Keeps the server's pipeline map in step with `.config/pipeline.php`.
 *
 * The MCP server lives for a whole agent session, and that session switches
 * branches and edits the config. A map loaded once then walks steps the project
 * no longer declares, and only the gate notices — after the whole walk ran. So
 * `open_run` asks this first, and a changed config is reloaded before the run
 * opens, or refused with the reason when a reload is not safe.
 *
 * A reload runs the config file again in the same process. Three things make
 * that unsafe, and each one is refused rather than attempted:
 *
 * - A changed file other than the config: a class PHP already loaded, or a
 *   helper the config requires, cannot be run again in this process.
 * - A config that declares a named class or function, defines a constant, or
 *   includes another file. Running it twice redeclares something, which is a
 *   fatal error no handler can catch — the server would die mid-session.
 * - A different set of pipeline names, or a switch between one pipeline and a
 *   map. The client holds the tool schema it listed at connect, and that schema
 *   names the pipelines and says whether a name is required.
 *
 * A failed reload — a parse error, an invalid declaration — keeps the map and the
 * hashes it had, so the next `open_run` tries again once the file is fixed.
 *
 * It also refuses once a package that autoloads code changed, because the
 * vendor classes this server loaded cannot be loaded again either. A package
 * without autoload entries holds no class PHP could have loaded, so a bump of
 * one does not count. Neither does the root package: Composer rewrites its
 * commit on every install, and the project files the config loads are tracked
 * on their own.
 */
final class ConfigReloader
{
    /**
     * Every project file the config loaded, with the hash it had then.
     *
     * @var array<string, string|null>
     */
    private array $sources = [];

    private ?Pipelines $pipelines = null;

    private bool $loaded = false;

    /**
     * Every package that autoloads code when this server started, with a hash of what identifies its code.
     *
     * @var array<string, string>|null
     */
    private readonly ?array $installed;

    /**
     * @param  (Closure(Pipelines): void)|null  $onReload
     * @param  string|null  $installedPackages  Composer's `vendor/composer/installed.json`, or null to not watch it
     */
    public function __construct(
        private readonly PipelineLoader $loader,
        private readonly string $basePath,
        private readonly ?Closure $onReload = null,
        private readonly ?string $installedPackages = null,
    ) {
        // Taken now, at server start, because that is when the vendor classes
        // loaded. A first `open_run` can come after a `composer install`.
        $this->installed = $installedPackages === null ? null : $this->autoloadingPackages($installedPackages);
    }

    /**
     * The map loaded at the first call, or null when the project has not opted in.
     *
     * @throws InvalidPipelineConfigException
     */
    public function pipelines(): ?Pipelines
    {
        if (! $this->loaded) {
            $this->pipelines = $this->load();
            $this->loaded = true;

            if ($this->pipelines instanceof Pipelines) {
                $this->sources[$this->loader->path()] = $this->hash($this->loader->path());
            }
        }

        return $this->pipelines;
    }

    /**
     * Reload when the config changed since it was loaded.
     *
     * @return Pipelines|null the new map, or null when nothing changed
     *
     * @throws InvalidPipelineConfigException when the config changed and cannot be reloaded in place
     */
    public function refresh(): ?Pipelines
    {
        // PHP keeps the vendor classes it already loaded and autoloads the rest
        // from the new files, so the server would run a mix of two versions.
        if ($this->installedPackages !== null && $this->autoloadingPackages($this->installedPackages) !== $this->installed) {
            throw InvalidPipelineConfigException::vendorChanged();
        }

        $current = $this->pipelines();

        if (! $current instanceof Pipelines) {
            return null;
        }

        $changed = $this->changedSources();

        if ($changed === []) {
            return null;
        }

        $config = $this->loader->path();

        if ($changed !== [$config]) {
            throw InvalidPipelineConfigException::configNeedsReconnect(sprintf(
                'files it loaded changed besides the config itself (%s), and PHP cannot load a class or included file a second time in one process',
                implode(', ', array_map($this->relative(...), array_diff($changed, [$config]))),
            ));
        }

        $code = @file_get_contents($config);

        if ($code === false) {
            throw InvalidPipelineConfigException::configNeedsReconnect('the file cannot be read');
        }

        $blocker = $this->reloadBlocker($code);

        if ($blocker !== null) {
            throw InvalidPipelineConfigException::configNeedsReconnect($blocker);
        }

        // A branch switch can repoint a symlinked config; PHP would otherwise
        // resolve the include through its cached target.
        clearstatcache(true);

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->realPath($config), true);
        }

        try {
            $fresh = $this->load();

            // Start-up builds every walk, so a duplicate step id is caught before
            // the map replaces a working one.
            foreach ($fresh?->names() ?? [] as $name) {
                $fresh?->get($name)?->walk();
            }
        } catch (Throwable $throwable) {
            throw InvalidPipelineConfigException::configReloadFailed(
                $throwable instanceof ParseError
                    ? sprintf('%s on line %d', $throwable->getMessage(), $throwable->getLine())
                    : $throwable->getMessage(),
            );
        }

        if (! $fresh instanceof Pipelines) {
            throw InvalidPipelineConfigException::configNeedsReconnect('the file is gone');
        }

        // The scan read the file once and `require` read it again. Code that
        // changed in between ran unchecked, and may have declared something.
        if ($this->hash($config) !== hash('xxh3', $code)) {
            throw InvalidPipelineConfigException::configNeedsReconnect('it changed again while it was being reloaded, so the server may have run code it did not check');
        }

        if ($fresh->requiresName() !== $current->requiresName()) {
            throw InvalidPipelineConfigException::configNeedsReconnect(
                'it switched between returning one pipeline and returning a map of named ones, which changes whether every tool call needs a pipeline name',
            );
        }

        if ($fresh->names() !== $current->names()) {
            throw InvalidPipelineConfigException::configNeedsReconnect(sprintf(
                'it now declares the pipelines [%s] instead of [%s], and the client still holds the tool list that names the old ones',
                implode(', ', $fresh->names()),
                implode(', ', $current->names()),
            ));
        }

        $this->pipelines = $fresh;
        $this->sources[$config] = hash('xxh3', $code);

        if ($this->onReload instanceof Closure) {
            ($this->onReload)($fresh);
        }

        return $fresh;
    }

    /**
     * Load the config, and track every project file the attempt pulled in.
     *
     * Tracked even when the load throws or is refused: PHP keeps a class it
     * loaded, so a later edit to that file must still be seen.
     *
     * Project files only: vendor code does not change on a branch switch the way
     * the project does, and hashing it on every `open_run` would cost time for
     * nothing.
     */
    private function load(): ?Pipelines
    {
        $before = get_included_files();

        try {
            return $this->loader->load();
        } finally {
            $this->trackFilesIncludedSince($before);
        }
    }

    /** @param  list<string>  $before */
    private function trackFilesIncludedSince(array $before): void
    {
        // The config is keyed by its own path, not its target: a symlink
        // repointed at another file changes the hash, where the old target
        // alone would not.
        $config = $this->realPath($this->loader->path());
        $vendor = $this->realPath($this->basePath.'/vendor').'/';
        $base = $this->realPath($this->basePath).'/';

        foreach (array_diff(get_included_files(), $before) as $file) {
            $path = $this->realPath($file);

            if (str_starts_with($path, $base) && ! str_starts_with($path, $vendor) && $path !== $config) {
                $this->sources[$path] ??= $this->hash($path);
            }
        }
    }

    /** @return list<string> */
    private function changedSources(): array
    {
        $changed = [];

        foreach ($this->sources as $path => $hash) {
            if ($this->hash((string) $path) !== $hash) {
                $changed[] = (string) $path;
            }
        }

        return $changed;
    }

    /**
     * Why running this code again would be unsafe, or null when it is safe.
     *
     * Read from the tokens of the file as it is NOW, because that is the code the
     * reload would run. A named declaration redeclares on the second run, and an
     * include runs a file that may declare one.
     */
    private function reloadBlocker(string $code): ?string
    {
        $tokens = array_values(array_filter(
            PhpToken::tokenize($code),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        $depth = 0;
        $parens = 0;
        $classBodies = [];
        $bodyAtParens = null;

        foreach ($tokens as $index => $token) {
            if ($token->is('(')) {
                $parens++;
            }

            if ($token->is(')')) {
                $parens--;
            }

            if ($token->is(['{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;

                // The first brace outside the constructor arguments opens the body.
                if ($bodyAtParens === $parens && $token->is('{')) {
                    $classBodies[] = $depth;
                    $bodyAtParens = null;
                }
            }

            if ($token->is('}')) {
                if ($classBodies !== [] && end($classBodies) === $depth) {
                    array_pop($classBodies);
                }

                $depth--;
            }

            if ($token->is(T_CLASS) && ($tokens[$index - 1] ?? null)?->is(T_NEW) === true) {
                $bodyAtParens = $parens;

                continue;
            }

            // A method or constant of an anonymous class is a member, not a
            // top-level declaration. Its method bodies sit deeper, and are scanned.
            $blocker = $classBodies !== [] && end($classBodies) === $depth
                ? null
                : $this->declarationAt($tokens, $index);

            if ($blocker !== null) {
                return $blocker;
            }
        }

        return null;
    }

    /**
     * What the token at this index declares that a second run would redeclare.
     *
     * @param  list<PhpToken>  $tokens  without whitespace and comments
     */
    private function declarationAt(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];
        $previous = $tokens[$index - 1] ?? null;
        $next = $tokens[$index + 1] ?? null;

        if ($token->is([T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE])) {
            return 'it includes another file, which a second run would load again';
        }

        // `use function foo;` imports a name; it declares nothing.
        if ($previous?->is(T_USE) === true) {
            return null;
        }

        $isDefineCall = $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && strtolower(ltrim($token->text, '\\')) === 'define' && $next?->is('(') === true
            && $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION]) !== true;

        if ($token->is(T_CONST) || $isDefineCall) {
            return 'it defines a constant, which a second run would define again';
        }

        $named = $next?->is(T_STRING) === true
            || ($next?->is('&') === true && ($tokens[$index + 2] ?? null)?->is(T_STRING) === true);

        if (! $named) {
            return null;
        }

        if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM]) && $previous?->is(T_DOUBLE_COLON) !== true) {
            return 'it declares a named class, interface, trait or enum, which a second run would declare again';
        }

        return $token->is(T_FUNCTION) ? 'it declares a named function, which a second run would declare again' : null;
    }

    /**
     * The packages in Composer's `installed.json` that declare autoload entries,
     * each with a hash of what identifies its code, or null when the file cannot
     * be read.
     *
     * `installed.php` cannot answer this: it carries no autoload entries, and its
     * root entry names the commit checked out at the last install, so it changes
     * on every branch switch.
     *
     * Only the fields that pick the code are hashed. The rest of an entry, such
     * as whether it came from a dist or from source, can differ between two
     * installs of the same version.
     *
     * @return array<string, string>|null
     */
    private function autoloadingPackages(string $path): ?array
    {
        $json = @file_get_contents($path);
        $installed = $json === false ? null : json_decode($json, true);
        $packages = is_array($installed) ? $installed['packages'] ?? null : null;

        if (! is_array($packages)) {
            return null;
        }

        $autoloading = [];

        foreach ($packages as $package) {
            if (! is_array($package) || ! is_string($package['name'] ?? null)) {
                continue;
            }

            if (($package['autoload'] ?? []) !== []) {
                $autoloading[$package['name']] = hash('xxh3', (string) json_encode([
                    $package['version'] ?? null,
                    is_array($package['source'] ?? null) ? $package['source']['reference'] ?? null : null,
                    is_array($package['dist'] ?? null) ? $package['dist']['reference'] ?? null : null,
                    $package['install-path'] ?? null,
                    $package['autoload'],
                ]));
            }
        }

        ksort($autoloading);

        return $autoloading;
    }

    private function hash(string $path): ?string
    {
        $hash = @hash_file('xxh3', $path);

        return $hash === false ? null : $hash;
    }

    private function realPath(string $path): string
    {
        $real = realpath($path);

        return $real === false ? $path : $real;
    }

    private function relative(string $path): string
    {
        $base = $this->realPath($this->basePath).'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
