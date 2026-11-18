<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

/**
 * The link between the server and `vendor/bin/boost-pipeline-mcp`.
 *
 * The supervisor sits between the client and the server. When the server asks
 * for a restart, the supervisor starts a new server and resends the request, so
 * the client keeps its connection. Behind a contract so a test can substitute
 * it: the shipped one reads the process environment and calls `exit`.
 */
interface ServerSupervisor
{
    /** Whether a supervisor that speaks this server's protocol started this process. */
    public function isSupervised(): bool;

    /** Whether the supervisor started this process to replace one that asked for a restart. */
    public function isRestart(): bool;

    /** Ask the supervisor for a new server. */
    public function restart(): never;
}
