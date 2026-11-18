<?php

declare(strict_types=1);

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use SanderMuller\BoostPipeline\Config\ConfigReloader;
use SanderMuller\BoostPipeline\Config\InstallState;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\ReceiptStore;
use SanderMuller\BoostPipeline\Contracts\ServerSupervisor;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
use SanderMuller\BoostPipeline\Results\Result;
use SanderMuller\BoostPipeline\Run\JsonReceiptStore;
use SanderMuller\BoostPipeline\Run\ReceiptStoreFactory;
use SanderMuller\BoostPipeline\Run\RunManager;
use SanderMuller\BoostPipeline\Runner\EnvironmentScrubber;
use SanderMuller\BoostPipeline\Runner\LogWriter;
use SanderMuller\BoostPipeline\Runner\OutputSummariser;
use SanderMuller\BoostPipeline\Runner\ProcessStepRunner;
use SanderMuller\BoostPipeline\Runner\StepRunnerFactory;
use SanderMuller\BoostPipeline\Steps\Shell;
use Symfony\Component\Process\Process;

/**
 * One MCP server lives for a whole agent session, and the session switches
 * branches and edits the config. `open_run` reloads a changed config, or refuses
 * with the reason when running the file again in this process is not safe.
 */
final readonly class TimedRunner implements StepRunner
{
    public function __construct(public ?float $timeout) {}

    public function run(Step $step, string $runId): Result
    {
        return Result::passed($step->id(), 'ok');
    }
}

/** @param array<string, list<string>> $pipelines name => commands, each command its own step */
function configDeclaring(array $pipelines, string $extra = '', ?float $timeout = null): string
{
    $entries = '';

    foreach ($pipelines as $name => $commands) {
        $steps = implode('', array_map(
            static fn (string $command): string => sprintf("\$steps->in(Tests::class)->append(Shell::run(%s, id: %s));\n", var_export($command, true), var_export($command, true)),
            $commands,
        ));

        $entries .= sprintf(
            "%s => Pipeline::configure()%s->withSteps(function (Steps \$steps): void {\n%s}),\n",
            var_export($name, true),
            $timeout === null ? '' : "->withTimeout({$timeout})",
            $steps,
        );
    }

    return "<?php\n\nuse SanderMuller\\BoostPipeline\\Config\\Pipeline;\nuse SanderMuller\\BoostPipeline\\Phases\\Defaults\\Tests;\nuse SanderMuller\\BoostPipeline\\Phases\\Steps;\nuse SanderMuller\\BoostPipeline\\Steps\\Shell;\n\n{$extra}\nreturn [\n{$entries}];\n";
}

function writeConfig(string $base, string $code): void
{
    file_put_contents($base.'/.config/pipeline.php', $code);
}

/** @return array{RunManager, StepRunnerFactory, ConfigReloader} */
function reloadingManager(string $base, ?string $installedPackages = null): array
{
    $reloader = new ConfigReloader(new PipelineLoader($base), $base, installedPackages: $installedPackages);
    $pipelines = $reloader->pipelines() ?? throw new RuntimeException('No config.');

    $runners = new StepRunnerFactory(static fn (string $name): StepRunner => new TimedRunner($reloader->pipelines()->get($name)?->timeoutSeconds()));

    return [new RunManager($pipelines, $runners, config: $reloader), $runners, $reloader];
}

function firstCommand(RunManager $runs, string $pipeline = 'default'): ?string
{
    $step = $runs->open($pipeline)->currentStep()?->step;

    return $step instanceof Shell ? $step->command() : null;
}

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/bp-reload-'.bin2hex(random_bytes(4));
    mkdir($this->base.'/.config', recursive: true);
});

afterEach(function (): void {
    new Process(['rm', '-rf', $this->base])->run();
});

it('hands out the new steps after the config changed, without a reconnect', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    [$runs] = reloadingManager($this->base);

    expect(firstCommand($runs))->toBe('echo old');

    writeConfig($this->base, configDeclaring(['default' => ['echo new']]));

    expect(firstCommand($runs))->toBe('echo new');
});

it('replaces an open run that recorded nothing yet, rather than keeping its old walk', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old', 'echo second']]));
    [$runs] = reloadingManager($this->base);
    $first = $runs->open('default');

    writeConfig($this->base, configDeclaring(['default' => ['echo new', 'echo second']]));
    $second = $runs->open('default');

    expect($second)->not->toBe($first)
        ->and($second->currentStep()?->step->id())->toBe('echo new');
});

it('replaces an open run when only an env value changed, which the digest leaves out', function (): void {
    $code = configDeclaring(['default' => ['echo same']]);
    $withMode = static fn (string $mode): string => str_replace("id: 'echo same')", "id: 'echo same')->withEnv(['MODE' => '{$mode}'])", $code);
    writeConfig($this->base, $withMode('one'));
    [$runs] = reloadingManager($this->base);
    $first = $runs->open('default');

    writeConfig($this->base, $withMode('two'));
    $second = $runs->open('default');
    $step = $second->currentStep()?->step;

    expect($second)->not->toBe($first)
        ->and($step instanceof Shell ? $step->env() : null)->toBe(['MODE' => 'two']);
});

it('builds the next runner with a timeout the reload changed', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo a']], timeout: 100.0));
    [$runs, $runners] = reloadingManager($this->base);
    $runs->open('default');

    writeConfig($this->base, configDeclaring(['default' => ['echo a']], timeout: 200.0));
    $runs->open('default');

    $runner = $runners->for('default');

    expect($runner)->toBeInstanceOf(TimedRunner::class)
        ->and($runner instanceof TimedRunner ? $runner->timeout : null)->toBe(200.0);
});

it('refuses a config that now declares a named function, and the server keeps working', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('default');

    $name = 'reload_helper_'.bin2hex(random_bytes(4));
    writeConfig($this->base, configDeclaring(['default' => ['echo new']], extra: "function {$name}(): void {}"));

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'named function')
        ->and(function_exists($name))->toBeFalse();
});

it('refuses a named class but not an anonymous one or a class constant', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('default');

    $anonymous = '$unused = [new class { public function id(): string { return Shell::class; } }];';
    writeConfig($this->base, configDeclaring(['default' => ['echo anonymous']], extra: $anonymous));

    expect(firstCommand($runs))->toBe('echo anonymous');

    writeConfig($this->base, configDeclaring(['default' => ['echo named']], extra: 'final class ReloadNamed'.bin2hex(random_bytes(4)).' {}'));

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'named class');
});

it('refuses a config that includes another file', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('default');

    file_put_contents($this->base.'/.config/extra.php', "<?php\n");
    writeConfig($this->base, configDeclaring(['default' => ['echo new']], extra: "require __DIR__.'/extra.php';"));

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'includes another file');
});

it('refuses when a file the config loaded changed, not only the config', function (): void {
    $class = 'ReloadSource'.bin2hex(random_bytes(4));
    $file = $this->base.'/'.$class.'.php';
    file_put_contents($file, "<?php\nfinal class {$class} { public static function command(): string { return 'echo from-class'; } }\n");
    spl_autoload_register(static function (string $name) use ($class, $file): void {
        if ($name === $class) {
            require $file;
        }
    });

    $code = configDeclaring(['default' => ['echo placeholder']]);
    writeConfig($this->base, str_replace("Shell::run('echo placeholder'", "Shell::run({$class}::command()", $code));
    [$runs] = reloadingManager($this->base);

    expect(firstCommand($runs))->toBe('echo from-class');

    file_put_contents($file, "<?php\nfinal class {$class} { public static function command(): string { return 'echo changed'; } }\n");

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, $class.'.php');
});

it('reloads a different set of pipeline names, and says the client must fetch the tool list again', function (): void {
    writeConfig($this->base, configDeclaring(['pr' => ['echo a'], 'release' => ['echo b']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('pr');

    expect($runs->toolListChanged())->toBeFalse();

    writeConfig($this->base, configDeclaring(['pr' => ['echo a2'], 'nightly' => ['echo c']]));

    expect(firstCommand($runs, 'pr'))->toBe('echo a2')
        ->and($runs->toolListChanged())->toBeTrue()
        ->and(firstCommand($runs, 'nightly'))->toBe('echo c')
        ->and($runs->toolListChanged())->toBeFalse();
});

it('keeps the tool list when a reload changes only the steps', function (): void {
    writeConfig($this->base, configDeclaring(['pr' => ['echo a']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('pr');

    writeConfig($this->base, configDeclaring(['pr' => ['echo b']]));
    $runs->open('pr');

    expect($runs->toolListChanged())->toBeFalse();
});

it('refuses a config that no longer loads, then reloads once it is fixed', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('default');

    writeConfig($this->base, "<?php\nreturn [ this is not php");

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'on line 2');

    writeConfig($this->base, configDeclaring(['default' => ['echo fixed']]));

    expect(firstCommand($runs))->toBe('echo fixed');
});

it('keeps a run whose step is still running, and replaces it once the step is done', function (): void {
    writeConfig($this->base, "<?php\n\nuse SanderMuller\\BoostPipeline\\Config\\Pipeline;\nuse SanderMuller\\BoostPipeline\\Phases\\Defaults\\Tests;\nuse SanderMuller\\BoostPipeline\\Phases\\Steps;\nuse SanderMuller\\BoostPipeline\\Steps\\Shell;\n\nreturn Pipeline::configure()->withResponseWindow(0.05)->withSteps(function (Steps \$steps): void {\n\$steps->in(Tests::class)->append(Shell::run('sleep 0.5', id: 'slow'));\n});\n");

    $reloader = new ConfigReloader(new PipelineLoader($this->base), $this->base);
    $logs = $this->base.'/logs';
    $runs = new RunManager(
        $reloader->pipelines() ?? throw new RuntimeException('No config.'),
        new StepRunnerFactory(static fn (): StepRunner => new ProcessStepRunner(
            workingDirectory: sys_get_temp_dir(),
            logs: new LogWriter($logs),
            summariser: new OutputSummariser,
            environment: new EnvironmentScrubber(sys_get_temp_dir()),
        )),
        config: $reloader,
    );

    $run = $runs->open();
    $run->resolveCurrent();

    writeConfig($this->base, str_replace("'sleep 0.5', id: 'slow'", "'echo new', id: 'new'", (string) file_get_contents($this->base.'/.config/pipeline.php')));

    expect($runs->open())->toBe($run)
        ->and($run->isPending())->toBeTrue();

    while ($run->isPending()) {
        $run->resolveCurrent();
    }

    expect($runs->open())->not->toBe($run);
});

it('changes nothing when the config did not change', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo same']]));
    [, , $reloader] = reloadingManager($this->base);

    expect($reloader->refresh())->toBeNull()
        ->and($reloader->pipelines())->toBeInstanceOf(Pipelines::class);
});

it('reloads a switch between one pipeline and a map, and says the client must fetch the tool list again', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo a']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('default');

    writeConfig($this->base, str_replace(["return [\n'default' => ", "}),\n];"], ['return ', '});'], configDeclaring(['default' => ['echo b']])));

    expect($runs->open()->currentStep()?->step->id())->toBe('echo b')
        ->and($runs->toolListChanged())->toBeTrue();
});

it('tells the client to fetch the tool list when a reload renamed a pipeline, and only then', function (): void {
    $path = app()->basePath('.config/pipeline.php');
    @mkdir(dirname($path), recursive: true);
    file_put_contents($path, configDeclaring(['pr' => ['echo a'], 'release' => ['echo b']]));

    foreach ([ConfigReloader::class, Pipelines::class, Pipeline::class, RunManager::class, StepRunnerFactory::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    try {
        PipelineServer::tool(OpenRun::class, ['pipeline' => 'pr'])
            ->assertNotificationCount(0);

        file_put_contents($path, configDeclaring(['pr' => ['echo a'], 'nightly' => ['echo c']]));

        PipelineServer::tool(OpenRun::class, ['pipeline' => 'pr'])
            ->assertSentNotification('notifications/tools/list_changed')
            ->assertSee('echo a');

        PipelineServer::tool(OpenRun::class, ['pipeline' => 'release'])
            ->assertNotificationCount(0)
            ->assertHasErrors();
    } finally {
        @unlink($path);
    }
});

it('sends the notification with the error too, when the renamed pipeline is the one asked for', function (): void {
    $path = app()->basePath('.config/pipeline.php');
    @mkdir(dirname($path), recursive: true);
    file_put_contents($path, configDeclaring(['pr' => ['echo a'], 'release' => ['echo b']]));

    foreach ([ConfigReloader::class, Pipelines::class, Pipeline::class, RunManager::class, StepRunnerFactory::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    try {
        PipelineServer::tool(OpenRun::class, ['pipeline' => 'pr']);

        file_put_contents($path, configDeclaring(['nightly' => ['echo c'], 'weekly' => ['echo d']]));

        PipelineServer::tool(OpenRun::class, ['pipeline' => 'pr'])
            ->assertSentNotification('notifications/tools/list_changed')
            ->assertHasErrors();
    } finally {
        @unlink($path);
    }
});

it('declares that the tool list can change, so a client acts on the notification', function (): void {
    $server = new PipelineServer(new FakeTransporter);
    $server->start();

    expect(new ReflectionProperty(Server::class, 'capabilities')->getValue($server))
        ->toMatchArray(['tools' => ['listChanged' => true]]);
});

it('tells the client to fetch the tool list on the first open after a restart', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one']]));
    installedProject($this->base);
    [$runs] = supervisedManager($this->base, new FakeSupervisor(restarted: true));

    $runs->open('default');

    expect($runs->toolListChanged())->toBeTrue();

    $runs->open('default');

    expect($runs->toolListChanged())->toBeFalse();
});

it('rebinds the map in the container, so the tools read the reloaded config', function (): void {
    $path = app()->basePath('.config/pipeline.php');
    @mkdir(dirname($path), recursive: true);
    file_put_contents($path, configDeclaring(['default' => ['echo old']]));

    foreach ([ConfigReloader::class, Pipelines::class, Pipeline::class, RunManager::class, StepRunnerFactory::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    try {
        PipelineServer::tool(OpenRun::class, ['pipeline' => 'default'])
            ->assertSee('echo old');

        // Resolved before the edit, so only the rebind can make it answer anew.
        resolve(Pipeline::class);

        file_put_contents($path, configDeclaring(['default' => ['echo new']]));

        PipelineServer::tool(OpenRun::class, ['pipeline' => 'default'])
            ->assertSee('echo new')
            ->assertDontSee('echo old');

        $step = resolve(Pipelines::class)->get('default')?->walk()->at(0)?->step;

        $sole = resolve(Pipeline::class)->walk()->at(0)?->step;

        expect($step instanceof Shell ? $step->command() : null)->toBe('echo new')
            ->and($sole instanceof Shell ? $sole->command() : null)->toBe('echo new');
    } finally {
        @unlink($path);
    }
});

it('reloads a config that imports a function, which declares nothing', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('default');

    writeConfig($this->base, str_replace('use SanderMuller\\BoostPipeline\\Steps\\Shell;', "use SanderMuller\\BoostPipeline\\Steps\\Shell;\nuse function Some\\Package\\helper;\nuse const Some\\Package\\LIMIT;", configDeclaring(['default' => ['echo new']])));

    expect(firstCommand($runs))->toBe('echo new');
});

it('refuses a config that defines a constant', function (string $definition): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('default');

    writeConfig($this->base, configDeclaring(['default' => ['echo new']], extra: $definition));

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'defines a constant');
})->with([
    'const' => ['const RELOAD_LIMIT = 3;'],
    'define' => ["define('RELOAD_LIMIT', 3);"],
    'fully qualified define' => ["\\define('RELOAD_LIMIT', 3);"],
]);

it('reloads a symlinked config that a branch switch repointed', function (): void {
    mkdir($this->base.'/configs');
    file_put_contents($this->base.'/configs/a.php', configDeclaring(['default' => ['echo old']]));
    file_put_contents($this->base.'/configs/b.php', configDeclaring(['default' => ['echo new']]));
    symlink($this->base.'/configs/a.php', $this->base.'/.config/pipeline.php');
    [$runs] = reloadingManager($this->base);

    expect(firstCommand($runs))->toBe('echo old');

    unlink($this->base.'/.config/pipeline.php');
    symlink($this->base.'/configs/b.php', $this->base.'/.config/pipeline.php');

    expect(firstCommand($runs))->toBe('echo new');
});

it('refuses an anonymous class that hides an include or a named function', function (string $extra): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('default');

    file_put_contents($this->base.'/.config/extra.php', "<?php\nreturn 1;\n");
    writeConfig($this->base, configDeclaring(['default' => ['echo new']], extra: $extra));

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class);
})->with([
    'include in the body' => ['$unused = new class { public function load(): int { return require __DIR__.\'/extra.php\'; } };'],
    'include in the constructor arguments' => ['$unused = new class(require __DIR__.\'/extra.php\') { public function __construct(public int $value) {} };'],
    'named function in a method' => ['$unused = new class { public function boot(): void { function reloadHelper(): void {} } };'],
]);

it('allows an anonymous class whose constructor takes a closure', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('default');

    $extra = '$unused = new class(function (): int { return 1; }) { const LIMIT = 1; public function __construct(public Closure $make) {} public function id(): string { return Shell::class; } };';
    writeConfig($this->base, configDeclaring(['default' => ['echo new']], extra: $extra));

    expect(firstCommand($runs))->toBe('echo new');
});

it('refuses a new config with a duplicate step id, and keeps the old one', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    [$runs, , $reloader] = reloadingManager($this->base);
    $open = $runs->open('default');

    writeConfig($this->base, configDeclaring(['default' => ['echo twice', 'echo twice']]));

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'Duplicate step id');

    $kept = $reloader->pipelines()?->get('default')?->walk()->at(0)?->step;

    expect($runs->for('default'))->toBe($open)
        ->and($kept instanceof Shell ? $kept->command() : null)->toBe('echo old');

    writeConfig($this->base, configDeclaring(['default' => ['echo fixed']]));

    expect(firstCommand($runs))->toBe('echo fixed');
});

it('tracks a project class a failed reload loaded, so a later edit to it is refused', function (): void {
    $class = 'ReloadBroken'.bin2hex(random_bytes(4));
    $file = $this->base.'/'.$class.'.php';
    file_put_contents($file, "<?php\nfinal class {$class} { public static function command(): string { throw new RuntimeException('broken'); } }\n");
    spl_autoload_register(static function (string $name) use ($class, $file): void {
        if ($name === $class) {
            require $file;
        }
    });

    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('default');

    $code = configDeclaring(['default' => ['echo placeholder']]);
    writeConfig($this->base, str_replace("Shell::run('echo placeholder'", "Shell::run({$class}::command()", $code));

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'broken');

    file_put_contents($file, "<?php\nfinal class {$class} { public static function command(): string { return 'echo fixed'; } }\n");

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, $class.'.php');
});

/** Composer's `installed.json`, with one autoloading package and one that ships only markdown. */
function installedJson(string $toolVersion, string $docsVersion, string $source = 'dist'): string
{
    return (string) json_encode(['packages' => [
        ['name' => 'acme/tool', 'version' => $toolVersion, 'installation-source' => $source, 'autoload' => ['psr-4' => ['Acme\\Tool\\' => 'src/']]],
        ['name' => 'acme/docs', 'version' => $docsVersion, 'autoload' => null],
    ], 'dev' => true]);
}

it('refuses once an autoloading package changed, before it looks at the config', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    $installed = $this->base.'/installed.json';
    file_put_contents($installed, installedJson('1.0.0', '1.0.0'));
    [$runs] = reloadingManager($this->base, $installed);
    $runs->open('default');

    // Same bytes written again, as a `composer install` that changes nothing does.
    file_put_contents($installed, installedJson('1.0.0', '1.0.0'));

    $runs->open('default');

    file_put_contents($installed, installedJson('2.0.0', '1.0.0'));
    writeConfig($this->base, configDeclaring(['default' => ['echo new']]));

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'vendor/ changed');
});

it('refuses once an autoloading package is gone', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    $installed = $this->base.'/installed.json';
    file_put_contents($installed, installedJson('1.0.0', '1.0.0'));
    [$runs] = reloadingManager($this->base, $installed);
    $runs->open('default');

    file_put_contents($installed, (string) json_encode(['packages' => [['name' => 'acme/docs', 'version' => '1.0.0', 'autoload' => null]]]));

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'vendor/ changed');
});

it('keeps serving when only a package without autoload entries changed', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo old']]));
    $installed = $this->base.'/installed.json';
    file_put_contents($installed, installedJson('1.0.0', '1.0.0'));
    [$runs] = reloadingManager($this->base, $installed);
    $runs->open('default');

    // A package that holds no class PHP could have loaded, the same version
    // installed from source instead of a dist, and a reformatted file.
    file_put_contents($installed, json_encode(json_decode(installedJson('1.0.0', '1.0.1', 'source')), JSON_PRETTY_PRINT));
    writeConfig($this->base, configDeclaring(['default' => ['echo new']]));

    expect($runs->open('default')->currentStep()?->step->id())->toBe('echo new');
});

final class RestartRequested extends RuntimeException {}

final class FakeSupervisor implements ServerSupervisor
{
    public int $restarts = 0;

    public function __construct(public bool $supervised = true, public bool $restarted = false) {}

    public function isSupervised(): bool
    {
        return $this->supervised;
    }

    public function isRestart(): bool
    {
        return $this->restarted;
    }

    public function restart(): never
    {
        $this->restarts++;

        throw new RestartRequested('restart');
    }
}

/**
 * A project with a lock file and an installed vendor/, both naming acme/tool at $version.
 *
 * @return string the installed.json path
 */
function installedProject(string $base, string $version = '1.0.0'): string
{
    file_put_contents($base.'/composer.lock', (string) json_encode(['packages' => [
        ['name' => 'acme/tool', 'version' => $version, 'autoload' => ['psr-4' => ['Acme\\Tool\\' => 'src/']]],
    ], 'packages-dev' => []]));

    return installAt($base, $version);
}

/** Write vendor/composer/installed.json with acme/tool at $version, as a composer install does. */
function installAt(string $base, string $version): string
{
    @mkdir($base.'/vendor/composer', recursive: true);
    $path = $base.'/vendor/composer/installed.json';
    file_put_contents($path, (string) json_encode(['packages' => [
        ['name' => 'acme/tool', 'version' => $version, 'autoload' => ['psr-4' => ['Acme\\Tool\\' => 'src/']]],
    ], 'dev' => true]));

    return $path;
}

/** @return array{RunManager, FakeSupervisor} */
function supervisedManager(string $base, ?FakeSupervisor $supervisor = null, bool $requireLock = true): array
{
    $reloader = new ConfigReloader(new PipelineLoader($base), $base, installedPackages: $base.'/vendor/composer/installed.json');
    $pipelines = $reloader->pipelines() ?? throw new RuntimeException('No config.');
    $runners = new StepRunnerFactory(static fn (string $name): StepRunner => new TimedRunner(null));
    $supervisor ??= new FakeSupervisor;

    return [new RunManager($pipelines, $runners, config: $reloader, install: new InstallState($base), supervisor: $supervisor, requireInstalledLock: $requireLock), $supervisor];
}

it('refuses while vendor/ does not hold what composer.lock names, with no vendor change since start, and names the package', function (bool $supervised): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one']]));
    installedProject($this->base);
    [$runs, $supervisor] = supervisedManager($this->base, new FakeSupervisor(supervised: $supervised));
    $runs->open('default');

    // A merge changed the lock; nobody ran composer install.
    file_put_contents($this->base.'/composer.lock', str_replace('1.0.0', '1.1.0', (string) file_get_contents($this->base.'/composer.lock')));

    expect(fn (): mixed => $runs->open('default'))
        ->toThrow(InvalidPipelineConfigException::class, 'acme/tool is 1.0.0, composer.lock names 1.1.0')
        ->and($supervisor->restarts)->toBe(0);
})->with(['unsupervised' => false, 'supervised' => true]);

it('opens with a warning instead when the lock check is switched off', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one']]));
    installedProject($this->base);
    [$runs] = supervisedManager($this->base, requireLock: false);

    file_put_contents($this->base.'/composer.lock', str_replace('1.0.0', '1.1.0', (string) file_get_contents($this->base.'/composer.lock')));

    expect($runs->open('default')->currentStep()?->step->id())->toBe('echo one')
        ->and($runs->warnings())->toHaveCount(1)
        ->and($runs->warnings()[0])->toContain('vendor/ does not match composer.lock');
});

it('still refuses a restart while vendor/ does not match the lock, even with the lock check switched off', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one']]));
    installedProject($this->base);
    [$runs, $supervisor] = supervisedManager($this->base, requireLock: false);

    // Half way through an install: installed.json moved on, the lock names more.
    installAt($this->base, '1.1.0');
    file_put_contents($this->base.'/composer.lock', str_replace('1.0.0', '1.2.0', (string) file_get_contents($this->base.'/composer.lock')));

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'still running, failed, or did not run')
        ->and($supervisor->restarts)->toBe(0);
});

it('asks the supervisor for a restart once vendor/ changed and matches the lock again', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one']]));
    installedProject($this->base);
    [$runs, $supervisor] = supervisedManager($this->base);
    $runs->open('default');

    installedProject($this->base, '1.1.0');

    expect(fn (): mixed => $runs->open('default'))->toThrow(RestartRequested::class)
        ->and($supervisor->restarts)->toBe(1);
});

it('never asks for a restart while vendor/ did not change', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one']]));
    installedProject($this->base);
    [$runs, $supervisor] = supervisedManager($this->base);

    $runs->open('default');
    $runs->open('default');

    expect($supervisor->restarts)->toBe(0);
});

it('lets the run of the pipeline being opened again, a failed step to retry, not block its own restart', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one', 'echo two']]));
    installedProject($this->base);
    [$runs] = supervisedManager($this->base);
    $runs->open('default')->resolveCurrent();

    installedProject($this->base, '1.1.0');

    expect(fn (): mixed => $runs->open('default'))->toThrow(RestartRequested::class);
});

it('restarts although another pipeline holds an unfinished walk, because its passes measured the old packages', function (): void {
    writeConfig($this->base, configDeclaring(['backend' => ['echo one', 'echo two'], 'release' => ['echo three']]));
    installedProject($this->base);
    [$runs, $supervisor] = supervisedManager($this->base);
    $runs->open('backend')->resolveCurrent();

    installedProject($this->base, '1.1.0');

    expect(fn (): mixed => $runs->open('release'))->toThrow(RestartRequested::class)
        ->and($supervisor->restarts)->toBe(1);
});

it('restarts when the other pipeline finished its walk', function (): void {
    writeConfig($this->base, configDeclaring(['backend' => ['echo one'], 'release' => ['echo three']]));
    installedProject($this->base);
    [$runs] = supervisedManager($this->base);
    $runs->open('backend')->resolveCurrent();

    installedProject($this->base, '1.1.0');

    expect(fn (): mixed => $runs->open('release'))->toThrow(RestartRequested::class);
});

it('restarts when the sole pipeline is opened again by no name in the middle of its walk', function (): void {
    writeConfig($this->base, "<?php\n\nuse SanderMuller\\BoostPipeline\\Config\\Pipeline;\nuse SanderMuller\\BoostPipeline\\Phases\\Defaults\\Tests;\nuse SanderMuller\\BoostPipeline\\Phases\\Steps;\nuse SanderMuller\\BoostPipeline\\Steps\\Shell;\n\nreturn Pipeline::configure()->withSteps(function (Steps \$steps): void {\n\$steps->in(Tests::class)->append(Shell::run('echo one', id: 'one'))->append(Shell::run('echo two', id: 'two'));\n});\n");
    installedProject($this->base);
    [$runs, $supervisor] = supervisedManager($this->base);
    $runs->open()->resolveCurrent();

    installedProject($this->base, '1.1.0');

    expect(fn (): mixed => $runs->open())->toThrow(RestartRequested::class)
        ->and($supervisor->restarts)->toBe(1);
});

it('records the installed packages in the receipt of a run it opens', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one']]));
    installedProject($this->base);
    $reloader = new ConfigReloader(new PipelineLoader($this->base), $this->base, installedPackages: $this->base.'/vendor/composer/installed.json');
    $install = new InstallState($this->base);
    $runs = new RunManager(
        $reloader->pipelines() ?? throw new RuntimeException('No config.'),
        new StepRunnerFactory(static fn (string $name): StepRunner => new TimedRunner(null)),
        receipts: new ReceiptStoreFactory(fn (string $name): ReceiptStore => new JsonReceiptStore($this->base."/receipt-{$name}.json")),
        config: $reloader,
        install: $install,
    );

    $run = $runs->open('default');

    while (! $run->state()->isTerminal()) {
        $run->resolveCurrent();
    }

    expect(new JsonReceiptStore($this->base.'/receipt-default.json')->read()?->installed)
        ->not->toBeNull()
        ->toBe($install->installedDigest());
});

it('opens a new run when an install changed packages that autoload nothing', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one']]));
    $install = static function (string $base, string $version): void {
        $package = ['name' => 'acme/rules', 'version' => $version];
        @mkdir($base.'/vendor/composer', recursive: true);
        file_put_contents($base.'/composer.lock', (string) json_encode(['packages' => [$package], 'packages-dev' => []]));
        file_put_contents($base.'/vendor/composer/installed.json', (string) json_encode(['packages' => [$package], 'dev' => true]));
    };
    $install($this->base, '1.0.0');
    [$runs, $supervisor] = supervisedManager($this->base);
    $first = $runs->open('default');
    $first->resolveCurrent();

    $install($this->base, '1.1.0');
    $second = $runs->open('default');

    expect($second->id)->not->toBe($first->id)
        ->and($supervisor->restarts)->toBe(0);
});

it('restarts although a removed pipeline holds an unfinished walk, because nothing can finish it', function (): void {
    writeConfig($this->base, configDeclaring(['backend' => ['echo one', 'echo two'], 'release' => ['echo three']]));
    installedProject($this->base);
    [$runs] = supervisedManager($this->base);
    $runs->open('backend')->resolveCurrent();

    writeConfig($this->base, configDeclaring(['release' => ['echo three']]));
    touch($this->base.'/.config/pipeline.php', time() + 5);
    $runs->open('release');
    installedProject($this->base, '1.1.0');

    expect(fn (): mixed => $runs->open('release'))->toThrow(RestartRequested::class);
});

it('refuses with a hint to the supervisor when the server runs without one', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one']]));
    installedProject($this->base);
    [$runs, $supervisor] = supervisedManager($this->base, new FakeSupervisor(supervised: false));
    $runs->open('default');

    installedProject($this->base, '1.1.0');

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'vendor/bin/boost-pipeline-mcp')
        ->and($supervisor->restarts)->toBe(0);
});

it('keeps a run with a running step reachable after a reload removed its pipeline', function (): void {
    $config = static fn (string $names): string => "<?php\n\nuse SanderMuller\\BoostPipeline\\Config\\Pipeline;\nuse SanderMuller\\BoostPipeline\\Phases\\Defaults\\Tests;\nuse SanderMuller\\BoostPipeline\\Phases\\Steps;\nuse SanderMuller\\BoostPipeline\\Steps\\Shell;\n\n\$slow = fn () => Pipeline::configure()->withResponseWindow(0.05)->withSteps(function (Steps \$steps): void {\n\$steps->in(Tests::class)->append(Shell::run('sleep 0.5', id: 'slow'));\n});\n\nreturn [{$names}];\n";
    writeConfig($this->base, $config("'backend' => \$slow(), 'release' => \$slow()"));
    $reloader = new ConfigReloader(new PipelineLoader($this->base), $this->base);
    $runs = new RunManager(
        $reloader->pipelines() ?? throw new RuntimeException('No config.'),
        new StepRunnerFactory(fn (): StepRunner => new ProcessStepRunner(
            workingDirectory: sys_get_temp_dir(),
            logs: new LogWriter($this->base.'/logs'),
            summariser: new OutputSummariser,
            environment: new EnvironmentScrubber(sys_get_temp_dir()),
        )),
        config: $reloader,
    );

    $run = $runs->open('backend');
    $run->resolveCurrent();

    expect($run->isPending())->toBeTrue();

    writeConfig($this->base, $config("'release' => \$slow()"));
    touch($this->base.'/.config/pipeline.php', time() + 5);
    $runs->open('release');

    expect($runs->for('backend'))->toBe($run);

    while ($run->isPending()) {
        $run->resolveCurrent();
    }

    expect(fn (): mixed => $runs->for('backend'))->toThrow(InvalidPipelineConfigException::class, 'No pipeline named [backend]');
});

it('refuses with a wait message while a step of any pipeline still runs', function (): void {
    writeConfig($this->base, "<?php\n\nuse SanderMuller\\BoostPipeline\\Config\\Pipeline;\nuse SanderMuller\\BoostPipeline\\Phases\\Defaults\\Tests;\nuse SanderMuller\\BoostPipeline\\Phases\\Steps;\nuse SanderMuller\\BoostPipeline\\Steps\\Shell;\n\nreturn Pipeline::configure()->withResponseWindow(0.05)->withSteps(function (Steps \$steps): void {\n\$steps->in(Tests::class)->append(Shell::run('sleep 0.5', id: 'slow'));\n});\n");
    installedProject($this->base);
    $reloader = new ConfigReloader(new PipelineLoader($this->base), $this->base, installedPackages: $this->base.'/vendor/composer/installed.json');
    $supervisor = new FakeSupervisor;
    $logs = $this->base.'/logs';
    $runs = new RunManager(
        $reloader->pipelines() ?? throw new RuntimeException('No config.'),
        new StepRunnerFactory(static fn (): StepRunner => new ProcessStepRunner(
            workingDirectory: sys_get_temp_dir(),
            logs: new LogWriter($logs),
            summariser: new OutputSummariser,
            environment: new EnvironmentScrubber(sys_get_temp_dir()),
        )),
        config: $reloader,
        install: new InstallState($this->base),
        supervisor: $supervisor,
    );

    $run = $runs->open();
    $run->resolveCurrent();
    installedProject($this->base, '1.1.0');

    expect($run->isPending())->toBeTrue()
        ->and(fn (): mixed => $runs->open())->toThrow(InvalidPipelineConfigException::class, 'is still running. Call next_step until it finishes')
        ->and($supervisor->restarts)->toBe(0);

    while ($run->isPending()) {
        $run->resolveCurrent();
    }

    expect(fn (): mixed => $runs->open())->toThrow(RestartRequested::class);
});

it('says once, on the first open after a restart, that earlier runs are gone', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one']]));
    installedProject($this->base);
    [$runs] = supervisedManager($this->base, new FakeSupervisor(restarted: true));

    $runs->open('default');

    expect($runs->warnings())->toHaveCount(1)
        ->and($runs->warnings()[0])->toContain('restarted after a vendor/ change');

    $runs->open('default');

    expect($runs->warnings())
        ->toBeEmpty();
});

it('keeps the restart notice for the next call when the first open after a restart is refused', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo one'], 'other' => ['echo two']]));
    installedProject($this->base);
    [$runs] = supervisedManager($this->base, new FakeSupervisor(restarted: true));

    expect(fn (): mixed => $runs->open())->toThrow(InvalidPipelineConfigException::class);

    $runs->open('default');

    expect($runs->warnings())->toHaveCount(1)
        ->and($runs->warnings()[0])->toContain('restarted after a vendor/ change');
});
