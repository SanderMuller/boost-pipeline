<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Runner;

use SanderMuller\BoostPipeline\Contracts\ServerSupervisor;

/**
 * Reads the supervisor's flags from the environment, and asks for a restart by exiting.
 *
 * The flag carries the protocol version, not just "yes". A package update can
 * put a new server under a supervisor that is still running the old script, and
 * a version this server does not know counts as no supervisor at all: refusing
 * is safe, exiting into a supervisor that does not restart is a disconnect.
 */
final readonly class EnvironmentSupervisor implements ServerSupervisor
{
    public const string SUPERVISED = 'BOOST_PIPELINE_SUPERVISED';

    public const string RESTARTED = 'BOOST_PIPELINE_RESTARTED';

    /** Must match BOOST_PIPELINE_PROTOCOL in bin/boost-pipeline-mcp. */
    public const string PROTOCOL = '1';

    /** `EX_TEMPFAIL` from sysexits.h: a temporary failure, try again. Must match BOOST_PIPELINE_RESTART_EXIT in bin/boost-pipeline-mcp. */
    private const int RESTART_EXIT_CODE = 75;

    /** @param  array<string, string>|null  $environment  the variables to read; null reads the process environment */
    public function __construct(private ?array $environment = null) {}

    public function isSupervised(): bool
    {
        return $this->variable(self::SUPERVISED) === self::PROTOCOL;
    }

    public function isRestart(): bool
    {
        return $this->isSupervised() && $this->variable(self::RESTARTED) === '1';
    }

    public function restart(): never
    {
        // Nothing may reach STDOUT first: the supervisor passes a complete line to
        // the client, so this request would get an answer from the old server.
        exit(self::RESTART_EXIT_CODE);
    }

    private function variable(string $name): ?string
    {
        $value = $this->environment === null ? getenv($name) : ($this->environment[$name] ?? null);

        return is_string($value) ? $value : null;
    }
}
