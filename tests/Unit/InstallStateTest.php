<?php

declare(strict_types=1);

use Illuminate\Foundation\ComposerScripts;
use SanderMuller\BoostPipeline\Config\InstallState;
use Symfony\Component\Process\Process;

/**
 * @param  array<string, string>  $packages  name => version
 * @param  array<string, string>  $dev  name => version
 */
function writeLock(string $base, array $packages, array $dev = []): void
{
    $entries = static fn (array $list): array => array_map(
        static fn (string $name, string $version): array => ['name' => $name, 'version' => $version, 'source' => ['reference' => 'ref-'.$version]],
        array_keys($list),
        $list,
    );

    file_put_contents($base.'/composer.lock', (string) json_encode(['packages' => $entries($packages), 'packages-dev' => $entries($dev)]));
}

/** @param  array<string, string>  $packages  name => version */
function writeInstalled(string $base, array $packages, bool $dev = true): void
{
    $entries = array_map(
        static fn (string $name, string $version): array => ['name' => $name, 'version' => $version, 'source' => ['reference' => 'ref-'.$version], 'autoload' => ['psr-4' => ['Acme\\' => 'src/']]],
        array_keys($packages),
        $packages,
    );

    @mkdir($base.'/vendor/composer', recursive: true);
    file_put_contents($base.'/vendor/composer/installed.json', (string) json_encode(['packages' => $entries, 'dev' => $dev]));
}

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/bp-install-'.bin2hex(random_bytes(4));
    mkdir($this->base);
});

afterEach(function (): void {
    new Process(['rm', '-rf', $this->base])->run();
});

it('matches when vendor/ holds what the lock names', function (): void {
    writeLock($this->base, ['acme/tool' => '1.0.0'], ['acme/test' => '2.0.0']);
    writeInstalled($this->base, ['acme/tool' => '1.0.0', 'acme/test' => '2.0.0']);

    expect(new InstallState($this->base)->differences())
        ->toBeEmpty();
});

it('names a package whose installed version is not the locked one', function (): void {
    writeLock($this->base, ['acme/tool' => '2.0.0']);
    writeInstalled($this->base, ['acme/tool' => '1.0.0']);

    expect(new InstallState($this->base)->differences())
        ->toBe(['acme/tool is 1.0.0 (ref-1.0.0), composer.lock names 2.0.0 (ref-2.0.0)']);
});

it('names a package that is locked but not installed, and one that is installed but not locked', function (): void {
    writeLock($this->base, ['acme/new' => '1.0.0']);
    writeInstalled($this->base, ['acme/old' => '1.0.0']);

    expect(new InstallState($this->base)->differences())->toBe([
        'acme/new 1.0.0 is not installed',
        'acme/old 1.0.0 is installed but not in composer.lock',
    ]);
});

it('compares the commit too, so a branch package moved to another commit differs', function (): void {
    writeLock($this->base, ['acme/tool' => 'dev-main']);
    writeInstalled($this->base, ['acme/tool' => 'dev-main']);

    $installed = (string) file_get_contents($this->base.'/vendor/composer/installed.json');
    file_put_contents($this->base.'/vendor/composer/installed.json', str_replace('ref-dev-main', 'other-commit', $installed));

    expect(new InstallState($this->base)->differences())->toHaveCount(1);
});

it('leaves the dev packages out when vendor/ was installed with --no-dev', function (): void {
    writeLock($this->base, ['acme/tool' => '1.0.0'], ['acme/test' => '2.0.0']);
    writeInstalled($this->base, ['acme/tool' => '1.0.0'], dev: false);

    expect(new InstallState($this->base)->differences())
        ->toBeEmpty();
});

it('counts a project without a lock file, or without installed.json, as a match', function (): void {
    writeInstalled($this->base, ['acme/tool' => '1.0.0']);

    expect(new InstallState($this->base)->differences())
        ->toBeEmpty();

    unlink($this->base.'/vendor/composer/installed.json');
    writeLock($this->base, ['acme/tool' => '1.0.0']);

    expect(new InstallState($this->base)->differences())
        ->toBeEmpty();
});

/** @param  array<string, string|list<string>>  $scripts */
function writeScripts(string $base, array $scripts): void
{
    file_put_contents($base.'/composer.json', (string) json_encode(['scripts' => $scripts]));
}

function staleManifest(string $base): void
{
    writeLock($base, ['acme/tool' => '1.0.0']);
    writeInstalled($base, ['acme/tool' => '1.0.0']);
    mkdir($base.'/bootstrap/cache', recursive: true);
    touch($base.'/bootstrap/cache/packages.php', time() - 20);
    touch($base.'/vendor/composer/installed.json', time() - 10);
}

it('reports a Laravel manifest older than installed.json when composer.json runs package:discover after the dump', function (): void {
    staleManifest($this->base);
    writeScripts($this->base, ['post-autoload-dump' => [ComposerScripts::class.'::postAutoloadDump', '@php artisan package:discover --ansi']]);

    $state = new InstallState($this->base);

    expect($state->differences())->toBe(['bootstrap/cache/packages.php is older than vendor/composer/installed.json, so package:discover has not run since the install']);

    // package:discover ran.
    touch($this->base.'/bootstrap/cache/packages.php', time());

    expect($state->differences())
        ->toBeEmpty();
});

it('follows a script that post-autoload-dump calls by name', function (): void {
    staleManifest($this->base);
    writeScripts($this->base, ['post-autoload-dump' => ['@prepare'], 'prepare' => '@php vendor/bin/testbench package:discover --ansi']);

    expect(new InstallState($this->base)->differences())->toHaveCount(1);
});

it('reads the hook again on every check, so a branch that drops it does not block', function (): void {
    staleManifest($this->base);
    writeScripts($this->base, ['post-autoload-dump' => ['@php artisan package:discover']]);
    $state = new InstallState($this->base);

    expect($state->differences())->toHaveCount(1);

    writeScripts($this->base, ['post-autoload-dump' => []]);

    expect($state->differences())
        ->toBeEmpty();
});

it('ignores the manifest in a project without a package:discover hook, so it never gets stuck', function (): void {
    staleManifest($this->base);
    writeScripts($this->base, ['post-autoload-dump' => ['@other'], 'other' => ['@post-autoload-dump']]);

    expect(new InstallState($this->base)->differences())
        ->toBeEmpty();
});

it('names five differences and counts the rest', function (): void {
    $differences = array_map(static fn (int $i): string => "acme/p{$i} differs", range(1, 7));

    expect(InstallState::summarise($differences))
        ->toBe('acme/p1 differs; acme/p2 differs; acme/p3 differs; acme/p4 differs; acme/p5 differs; and 2 more');
});

it('gives the installed set and the lock the same digest when they match, and different ones when not', function (): void {
    writeLock($this->base, ['acme/tool' => '1.0.0'], ['acme/test' => '2.0.0']);
    writeInstalled($this->base, ['acme/tool' => '1.0.0', 'acme/test' => '2.0.0']);
    $state = new InstallState($this->base);

    $installed = $state->installedDigest();

    expect($installed)->toBe(['digest' => $state->lockDigest(true), 'dev' => true])
        ->and($state->lockDigest(false))->not->toBe($installed['digest'] ?? null);

    writeLock($this->base, ['acme/tool' => '1.1.0'], ['acme/test' => '2.0.0']);

    expect($state->lockDigest(true))->not->toBe($installed['digest'] ?? null);
});

it('gives a --no-dev install the digest of the lock without its dev packages', function (): void {
    writeLock($this->base, ['acme/tool' => '1.0.0'], ['acme/test' => '2.0.0']);
    writeInstalled($this->base, ['acme/tool' => '1.0.0'], dev: false);
    $state = new InstallState($this->base);

    expect($state->installedDigest())->toBe(['digest' => $state->lockDigest(false), 'dev' => false]);
});

it('has no digest without a lock to compare it with', function (): void {
    writeInstalled($this->base, ['acme/tool' => '1.0.0']);
    $state = new InstallState($this->base);

    expect($state->installedDigest())->toBeNull()
        ->and($state->lockDigest(true))->toBeNull();
});
