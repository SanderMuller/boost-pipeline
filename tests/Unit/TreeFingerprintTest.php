<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Runner\GitTreeFingerprint;
use Symfony\Component\Process\Process;

/**
 * The fingerprint decides whether a receipt still holds, so what it does and does
 * NOT notice is the whole contract. Ignored paths are the load-bearing half: the
 * run writes its own logs under storage/logs/ while it is running, and a digest
 * that moved for those would report every clean run as stale.
 */
function git(string $cwd, string ...$arguments): void
{
    $process = new Process(['git', ...$arguments], $cwd);
    $process->mustRun();
}

beforeEach(function (): void {
    $this->repo = sys_get_temp_dir().'/bp-fp-'.bin2hex(random_bytes(4));
    mkdir($this->repo);

    git($this->repo, 'init', '--quiet');
    git($this->repo, 'config', 'user.email', 'test@example.com');
    git($this->repo, 'config', 'user.name', 'Test');

    file_put_contents($this->repo.'/.gitignore', "ignored/\n");
    file_put_contents($this->repo.'/src.php', "<?php // one\n");
    git($this->repo, 'add', '-A');
    git($this->repo, 'commit', '--quiet', '-m', 'initial');

    $this->fingerprint = new GitTreeFingerprint($this->repo);
});

afterEach(function (): void {
    if (is_dir($this->repo)) {
        new Process(['rm', '-rf', $this->repo])->run();
    }
});

it('returns the same digest when nothing changed', function (): void {
    expect($this->fingerprint->capture())->toBe($this->fingerprint->capture());
});

it('moves when a tracked file is edited without being committed', function (): void {
    $before = $this->fingerprint->capture();

    file_put_contents($this->repo.'/src.php', "<?php // two\n");

    expect($this->fingerprint->capture())->not->toBe($before);
});

it('moves when an untracked file appears', function (): void {
    $before = $this->fingerprint->capture();

    file_put_contents($this->repo.'/new.php', "<?php\n");

    expect($this->fingerprint->capture())->not->toBe($before);
});

it('moves when a commit is made, even with an identical tree', function (): void {
    file_put_contents($this->repo.'/src.php', "<?php // two\n");
    $dirty = $this->fingerprint->capture();

    git($this->repo, 'add', '-A');
    git($this->repo, 'commit', '--quiet', '-m', 'second');

    // Same bytes on disk, different HEAD — and a receipt naming a commit that is
    // no longer HEAD is not a receipt for what is checked out now.
    expect($this->fingerprint->capture())->not->toBe($dirty);
});

it('ignores what git ignores, so a run writing its own logs stays current', function (): void {
    $before = $this->fingerprint->capture();

    mkdir($this->repo.'/ignored');
    file_put_contents($this->repo.'/ignored/r-4f2a-pint.log', 'output');

    expect($this->fingerprint->capture())->toBe($before);
});

it('returns null outside a repository rather than inventing a digest', function (): void {
    $bare = sys_get_temp_dir().'/bp-fp-none-'.bin2hex(random_bytes(4));
    mkdir($bare);

    try {
        expect(new GitTreeFingerprint($bare)->capture())->toBeNull();
    } finally {
        rmdir($bare);
    }
});
