<?php

declare(strict_types=1);

namespace SanderMuller\BoostPipeline\Contracts;

/**
 * Whether this process is starting the MCP server.
 *
 * Behind a contract so a test can substitute it. The alternative is mutating
 * `$_SERVER['argv']`, and that global is shared with the agent output formatter,
 * which reads and rewrites it — changing it mid-suite corrupts state that has
 * nothing to do with this question.
 */
interface ServerProcess
{
    public function isStarting(): bool;
}
