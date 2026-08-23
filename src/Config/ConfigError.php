<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Config;

/**
 * Why the pipeline config could not be loaded.
 *
 * Bound only when loading failed, so its presence in the container is itself the
 * signal that the server is running in a degraded mode.
 */
final readonly class ConfigError
{
    public function __construct(public string $message) {}
}
