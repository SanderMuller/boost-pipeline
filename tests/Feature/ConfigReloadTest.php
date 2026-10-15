<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\ConfigReloader;
use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Config\Pipelines;
use SanderMuller\BoostPipeline\Contracts\Step;
use SanderMuller\BoostPipeline\Contracts\StepRunner;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;
use SanderMuller\BoostPipeline\Mcp\PipelineServer;
use SanderMuller\BoostPipeline\Mcp\Tools\OpenRun;
use SanderMuller\BoostPipeline\Results\Result;
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
function reloadingManager(string $base): array
{
    $reloader = new ConfigReloader(new PipelineLoader($base), $base);
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

it('refuses a different set of pipeline names, which the client tool list still names', function (): void {
    writeConfig($this->base, configDeclaring(['pr' => ['echo a'], 'release' => ['echo b']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('pr');

    writeConfig($this->base, configDeclaring(['pr' => ['echo a'], 'nightly' => ['echo c']]));

    expect(fn (): mixed => $runs->open('pr'))->toThrow(InvalidPipelineConfigException::class, 'instead of [pr, release]');
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

it('refuses a switch between one pipeline and a map, which changes whether a name is required', function (): void {
    writeConfig($this->base, configDeclaring(['default' => ['echo a']]));
    [$runs] = reloadingManager($this->base);
    $runs->open('default');

    writeConfig($this->base, str_replace(["return [\n'default' => ", "}),\n];"], ['return ', '});'], configDeclaring(['default' => ['echo b']])));

    expect(fn (): mixed => $runs->open('default'))->toThrow(InvalidPipelineConfigException::class, 'returning one pipeline');
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
