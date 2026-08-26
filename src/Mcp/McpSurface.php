<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Mcp;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Registrar;
use Laravel\Mcp\Server\Tool;

/**
 * Checks the `laravel/mcp` 0.x surface this package depends on actually
 * exists, before the boot-time gate leans on it.
 *
 * A 0.x minor "may move any of these without ceremony" (see
 * `.ai/docs/laravel-mcp-notes.md`), and a moved symbol currently produces a
 * raw PHP fatal on the stdio stream — the JSON-RPC channel a client cannot
 * parse a fatal from. This check turns that fatal into a declined
 * registration and a stderr line instead.
 *
 * `Server\Testing\*` is dev-only and deliberately absent from the
 * production list below — it is never touched at boot.
 *
 * `Tool::shouldRegister()` is deliberately absent too: it does not exist on
 * the base `Tool` class. It is an optional hook a consumer tool may define,
 * dispatched reflectively by `Server\Primitive`. The design leans on that
 * dispatch convention, but there is no symbol here to check for it.
 */
final class McpSurface
{
    /**
     * @var list<string>
     */
    private const array PRODUCTION_CLASSES = [
        Server::class,
        Tool::class,
        Prompt::class,
        Response::class,
        Registrar::class,
    ];

    /**
     * @var list<array{string, string}>
     */
    private const array PRODUCTION_METHODS = [
        [Response::class, 'error'],
        [Response::class, 'structured'],
        [Tool::class, 'annotations'],
        [Tool::class, 'outputSchema'],
        [Registrar::class, 'local'],
    ];

    /**
     * Returns the name of the first missing class or `Class::method` pair,
     * or null when every symbol given resolves.
     *
     * @param  list<string>  $classes
     * @param  list<array{string, string}>  $methods  [class, method] pairs
     */
    public static function firstMissing(array $classes, array $methods): ?string
    {
        foreach ($classes as $class) {
            if (! class_exists($class)) {
                return $class;
            }
        }

        foreach ($methods as [$class, $method]) {
            if (! method_exists($class, $method)) {
                return $class.'::'.$method;
            }
        }

        return null;
    }

    /**
     * Convenience wrapper over `firstMissing()` for the production surface
     * this package's boot-time gate actually depends on.
     */
    public static function firstMissingProduction(): ?string
    {
        return self::firstMissing(self::PRODUCTION_CLASSES, self::PRODUCTION_METHODS);
    }
}
