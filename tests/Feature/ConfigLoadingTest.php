<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Config\Pipeline;
use SanderMuller\BoostPipeline\Config\PipelineLoader;
use SanderMuller\BoostPipeline\Exceptions\InvalidPipelineConfigException;

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/boost-pipeline-'.bin2hex(random_bytes(4));
    mkdir($this->base.'/.config', recursive: true);
});

afterEach(function (): void {
    if (is_file($this->base.'/.config/pipeline.php')) {
        unlink($this->base.'/.config/pipeline.php');
    }

    if (is_dir($this->base.'/.config')) {
        rmdir($this->base.'/.config');
    }

    if (is_dir($this->base)) {
        rmdir($this->base);
    }
});

it('returns null when the project has not opted in, rather than throwing', function (): void {
    $loader = new PipelineLoader($this->base);

    expect($loader->exists())->toBeFalse()
        ->and($loader->load())->toBeNull();
});

it('loads a config that returns a Pipeline, naming it default', function (): void {
    // The shape every project used before names existed. It keeps working, and
    // arrives as a map of one so nothing downstream has to know which shape was
    // written.
    file_put_contents(
        $this->base.'/.config/pipeline.php',
        '<?php return '.Pipeline::class.'::configure();'
    );

    $pipelines = new PipelineLoader($this->base)->load();

    // Nullsafe throughout, and no `toBeInstanceOf` to narrow it first: the Pest
    // static-analysis plugin disagrees with itself across versions about whether
    // that narrowing happens, so one version calls `?->` redundant and another
    // calls `->` a call on null. A null loader result still fails the first
    // assertion, so nothing is lost.
    expect($pipelines?->names())->toBe(['default'])
        ->and($pipelines?->isSingle())->toBeTrue()
        // The name is not just a label: `sole()` and the map agree on which
        // pipeline it points at.
        ->and($pipelines?->sole())->toBe($pipelines?->get('default'));
});

it('loads a config that returns a map of named pipelines', function (): void {
    file_put_contents(
        $this->base.'/.config/pipeline.php',
        '<?php return ["pr" => '.Pipeline::class.'::configure(), "release" => '.Pipeline::class.'::configure()];'
    );

    $pipelines = new PipelineLoader($this->base)->load();

    expect($pipelines?->names())->toBe(['pr', 'release'])
        ->and($pipelines?->isSingle())->toBeFalse()
        ->and($pipelines?->get('release'))->toBeInstanceOf(Pipeline::class)
        ->and($pipelines?->get('nope'))->toBeNull();
});

it('fails loudly when the config exists but returns neither shape', function (): void {
    file_put_contents($this->base.'/.config/pipeline.php', '<?php return "a pipeline, honest";');

    new PipelineLoader($this->base)->load();
})->throws(InvalidPipelineConfigException::class, 'must return a Pipeline instance, got string');

it('names the integer key when the config returns a list rather than a map', function (): void {
    // The likely mistake: pipelines written without names. PHP gives them integer
    // keys, so the message says that rather than quoting the name pattern at
    // someone who wrote no name at all.
    file_put_contents(
        $this->base.'/.config/pipeline.php',
        '<?php return ['.Pipeline::class.'::configure()];'
    );

    new PipelineLoader($this->base)->load();
})->throws(InvalidPipelineConfigException::class, 'a key is int [0]');

it('refuses a config that declares no pipelines at all', function (): void {
    file_put_contents($this->base.'/.config/pipeline.php', '<?php return [];');

    new PipelineLoader($this->base)->load();
})->throws(InvalidPipelineConfigException::class, 'declares no pipelines at all');

it('names the key when a map value is not a Pipeline', function (): void {
    file_put_contents(
        $this->base.'/.config/pipeline.php',
        '<?php return ["pr" => '.Pipeline::class.'::configure(), "release" => "nope"];'
    );

    new PipelineLoader($this->base)->load();
})->throws(InvalidPipelineConfigException::class, 'declares pipeline [release] as string');

it('refuses a pipeline name that could not address a receipt file', function (string $name): void {
    // The name becomes a filename component, so it is validated rather than
    // sanitised: rewriting `../escape` into something safe would hide the mistake
    // and still not be the pipeline the caller asked for.
    file_put_contents(
        $this->base.'/.config/pipeline.php',
        '<?php return ['.var_export($name, true).' => '.Pipeline::class.'::configure()];'
    );

    new PipelineLoader($this->base)->load();
})->with([
    'a path traversal' => '../escape',
    'a separator' => 'pr/release',
    'an empty name' => '',
    'uppercase' => 'PR',
    'a leading dash' => '-pr',
    'a dot' => 'pr.release',
    'a space' => 'pull request',
])->throws(InvalidPipelineConfigException::class, 'is not usable');

it('accepts a name of digits and dashes', function (): void {
    file_put_contents(
        $this->base.'/.config/pipeline.php',
        '<?php return ["pre-release-2" => '.Pipeline::class.'::configure()];'
    );

    expect(new PipelineLoader($this->base)->load()?->names())->toBe(['pre-release-2']);
});

it('refuses to resolve a sole pipeline when several are declared', function (): void {
    // The binding that predates names resolves through this. It throws rather
    // than picking one: "the pipeline" has no answer in a project with three, and
    // guessing hands back a walk nobody asked for.
    file_put_contents(
        $this->base.'/.config/pipeline.php',
        '<?php return ["pr" => '.Pipeline::class.'::configure(), "release" => '.Pipeline::class.'::configure()];'
    );

    new PipelineLoader($this->base)->load()?->sole();
})->throws(InvalidPipelineConfigException::class, 'so there is no single one to resolve');

it('implies a name only for a file that returned a bare Pipeline', function (): void {
    // Declaration shape, not count. A project that writes `['pr' => ...]` today
    // and adds `release` tomorrow would otherwise find every call site that
    // omitted the name breaking on the day the second one arrives.
    file_put_contents(
        $this->base.'/.config/pipeline.php',
        '<?php return '.Pipeline::class.'::configure();'
    );

    $bare = new PipelineLoader($this->base)->load();

    file_put_contents(
        $this->base.'/.config/pipeline.php',
        '<?php return ["pr" => '.Pipeline::class.'::configure()];'
    );

    $mapOfOne = new PipelineLoader($this->base)->load();

    expect($bare?->implied())->toBe('default')
        ->and($bare?->requiresName())->toBeFalse()
        ->and($mapOfOne?->implied())->toBeNull()
        ->and($mapOfOne?->requiresName())->toBeTrue()
        // Counting still finds the sole pipeline, which is what a bare
        // `pipeline:verify` and the legacy container aliases ask about.
        ->and($mapOfOne?->soleName())->toBe('pr')
        ->and($mapOfOne?->isSingle())->toBeTrue();
});

it('has no sole name once a project declares several', function (): void {
    file_put_contents(
        $this->base.'/.config/pipeline.php',
        '<?php return ["pr" => '.Pipeline::class.'::configure(), "release" => '.Pipeline::class.'::configure()];'
    );

    $several = new PipelineLoader($this->base)->load();

    expect($several?->soleName())->toBeNull()
        ->and($several?->implied())->toBeNull();
});

it('lets two pipelines declare the same step id, because the walks are separate', function (): void {
    // Uniqueness is asserted per walk, and these are two walks. A PR pipeline and
    // a release pipeline both running phpstan is the expected shape, not a clash.
    file_put_contents($this->base.'/.config/pipeline.php', <<<'PHP'
        <?php

        use SanderMuller\BoostPipeline\Config\Pipeline;
        use SanderMuller\BoostPipeline\Phases\Defaults\StaticAnalysis;
        use SanderMuller\BoostPipeline\Phases\Steps;
        use SanderMuller\BoostPipeline\Steps\Shell;

        $declare = fn (Steps $steps) => $steps->in(StaticAnalysis::class)
            ->append(Shell::run('vendor/bin/phpstan', id: 'phpstan'));

        return [
            'pr' => Pipeline::configure()->withSteps($declare),
            'release' => Pipeline::configure()->withSteps($declare),
        ];
        PHP);

    $pipelines = new PipelineLoader($this->base)->load();

    expect($pipelines?->get('pr')?->walk()->count())->toBe(1)
        ->and($pipelines?->get('release')?->walk()->count())->toBe(1);
});
