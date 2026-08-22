<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Config;

use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;

/**
 * Loads `.config/pipeline.php`.
 *
 * Absent and invalid are deliberately different outcomes. An absent file means
 * the project has not opted in, so the tools decline to register and nothing
 * throws. An invalid file is a real mistake, so it fails loud.
 */
final readonly class PipelineLoader
{
    public const string CONFIG_PATH = '.config/pipeline.php';

    public function __construct(private string $basePath) {}

    public function path(): string
    {
        return rtrim($this->basePath, '/').'/'.self::CONFIG_PATH;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /** Null when the project has not opted in. */
    public function load(): ?Pipeline
    {
        if (! $this->exists()) {
            return null;
        }

        $pipeline = require $this->path();

        if (! $pipeline instanceof Pipeline) {
            throw InvalidPipelineConfigException::didNotReturnPipeline(
                $this->path(),
                get_debug_type($pipeline),
            );
        }

        return $pipeline;
    }
}
