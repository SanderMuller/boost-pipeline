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

it('loads a config that returns a Pipeline', function (): void {
    file_put_contents(
        $this->base.'/.config/pipeline.php',
        '<?php return \SanderMuller\BoostPipeline\Config\Pipeline::configure();'
    );

    expect((new PipelineLoader($this->base))->load())->toBeInstanceOf(Pipeline::class);
});

it('fails loudly when the config exists but returns the wrong thing', function (): void {
    file_put_contents($this->base.'/.config/pipeline.php', '<?php return ["not", "a", "pipeline"];');

    (new PipelineLoader($this->base))->load();
})->throws(InvalidPipelineConfigException::class, 'must return a Pipeline instance, got array');
