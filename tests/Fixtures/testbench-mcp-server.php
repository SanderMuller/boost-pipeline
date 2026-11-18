<?php

/*
 * Starts the real pipeline MCP server on a throwaway application base path.
 *
 * The end-to-end supervisor test needs a vendor/composer/installed.json and a
 * composer.lock it may change. The Testbench skeleton links vendor/ to this
 * repository's own, so it boots a copy of the skeleton instead: classes still
 * load from this repository's autoloader, while the application reads its
 * base path files from the copy.
 *
 * Usage: php testbench-mcp-server.php <base path>
 */

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\Foundation\Application;
use SanderMuller\BoostPipeline\BoostPipelineServiceProvider;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$base = is_string($arguments[1] ?? null) ? $arguments[1] : '';

// The service provider runs its server-start checks only in a process that runs mcp:start.
$_SERVER['argv'] = ['artisan', 'mcp:start', 'pipeline'];

$app = Application::create(basePath: $base, options: ['extra' => [
    'providers' => [McpServiceProvider::class, BoostPipelineServiceProvider::class],
    'dont-discover' => ['*'],
]]);

exit($app->make(Kernel::class)->call('mcp:start', ['handle' => 'pipeline']));
