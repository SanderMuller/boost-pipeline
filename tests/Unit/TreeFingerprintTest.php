<?php

declare(strict_types=1);

use SanderMuller\BoostPipeline\Runner\GitTreeFingerprint;
use SanderMuller\BoostPipeline\Runner\TreeDigest;
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

it('survives staging and committing the same bytes', function (): void {
    // The transition the whole design exists for. Verify, commit, gate is the
    // correct order to work in: the walk is what makes it safe to commit, and the
    // commit is what makes a gate receipt worth having. Neither step edits a file,
    // so a digest that moved across them threw away a suite that had just passed.
    file_put_contents($this->repo.'/src.php', "<?php // two\n");
    file_put_contents($this->repo.'/new.php', "<?php\n");

    $unstaged = $this->fingerprint->capture();

    git($this->repo, 'add', '-A');
    $staged = $this->fingerprint->capture();

    git($this->repo, 'commit', '--quiet', '-m', 'second');
    $committed = $this->fingerprint->capture();

    expect(TreeDigest::sameContent($unstaged, $staged))->toBeTrue()
        ->and(TreeDigest::sameContent($staged, $committed))->toBeTrue();
});

it('still reports the commit it was taken at, and whether anything was uncommitted', function (): void {
    // Content survives a commit deliberately, so a gate that must not ship
    // unverified code needs the commit recorded separately rather than folded in.
    $clean = $this->fingerprint->capture();

    file_put_contents($this->repo.'/src.php', "<?php // two\n");
    $dirty = $this->fingerprint->capture();

    git($this->repo, 'add', '-A');
    git($this->repo, 'commit', '--quiet', '-m', 'second');
    $committed = $this->fingerprint->capture();

    expect(TreeDigest::wasClean($clean))->toBeTrue()
        ->and(TreeDigest::wasClean($dirty))->toBeFalse()
        ->and(TreeDigest::wasClean($committed))->toBeTrue()
        ->and(TreeDigest::sameCommit($clean, $committed))->toBeFalse();
});

it('counts an untracked file as uncommitted, so a gate cannot read the tree as clean', function (): void {
    // `git diff --quiet HEAD` is the natural reach for "does the tree match HEAD"
    // and it answers CLEAN here. The digest counts untracked paths, so the
    // cleanliness test has to count them too, or a gate ships without a file the
    // run saw.
    file_put_contents($this->repo.'/new.php', "<?php\n");

    expect(TreeDigest::wasClean($this->fingerprint->capture()))->toBeFalse();
});

it('moves when a file gains its executable bit, with no byte changed', function (): void {
    // A step invoked as `./script` does something different after chmod +x while
    // every byte of it is identical, so a digest of content alone cannot see it.
    $script = $this->repo.'/run.sh';
    file_put_contents($script, "#!/bin/sh\necho hi\n");
    git($this->repo, 'add', '-A');
    git($this->repo, 'commit', '--quiet', '-m', 'script');

    $before = $this->fingerprint->capture();
    chmod($script, 0o755);

    expect(TreeDigest::sameContent($this->fingerprint->capture(), $before))->toBeFalse();
});

it('returns to the earlier digest when an edit is undone', function (): void {
    // Content addressing makes edit-then-undo a valid receipt again, where a
    // history-keyed digest read it as stale forever. Asserted against the earlier
    // VALUE rather than merely against freshness, so that mixing anything
    // order- or history-dependent back into the key fails here.
    $before = $this->fingerprint->capture();

    file_put_contents($this->repo.'/src.php', "<?php // edited\n");
    $edited = $this->fingerprint->capture();

    file_put_contents($this->repo.'/src.php', "<?php // one\n");

    expect(TreeDigest::sameContent($edited, $before))->toBeFalse()
        ->and(TreeDigest::sameContent($this->fingerprint->capture(), $before))->toBeTrue();
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

it('fingerprints a repository that has no commits yet', function (): void {
    // rev-parse HEAD fails before the first commit. Answering null there turned
    // expiry off for a whole new repository, which is the opposite of the rule.
    $fresh = sys_get_temp_dir().'/bp-fp-unborn-'.bin2hex(random_bytes(4));
    mkdir($fresh);
    git($fresh, 'init', '--quiet');

    $fingerprint = new GitTreeFingerprint($fresh);
    $before = $fingerprint->capture();

    file_put_contents($fresh.'/new.php', "<?php\n");

    expect($before)->not->toBeNull()
        ->and($fingerprint->capture())->not->toBe($before);

    new Process(['rm', '-rf', $fresh])->run();
});

it('handles a numerically-named path, which PHP turns into an int array key', function (): void {
    // Entries are collected by path so the working tree can REPLACE the index
    // value, and a path of "123" becomes an int key on the way in. The digest
    // must stay deterministic and must still notice an edit to that file.
    file_put_contents($this->repo.'/123', "one\n");
    git($this->repo, 'add', '-A');
    git($this->repo, 'commit', '--quiet', '-m', 'numeric');

    $before = $this->fingerprint->capture();

    expect($this->fingerprint->capture())->toBe($before);

    file_put_contents($this->repo.'/123', "two\n");

    expect(TreeDigest::sameContent($this->fingerprint->capture(), $before))->toBeFalse();
});

it('stays invariant for a CRLF file under text=auto, which git stores normalised', function (): void {
    // Git applies .gitattributes filters on the way into the index, so for a
    // tracked text file holding CRLF it stores LF-normalised bytes. A hash of the
    // bytes ON DISK therefore never equals the blob git wrote, and staging that
    // file moves the digest permanently — silently, because nothing looks wrong.
    // This repository sets `* text=auto eol=lf`, so it is not a hypothetical.
    file_put_contents($this->repo.'/.gitattributes', "* text=auto eol=lf\n");
    file_put_contents($this->repo.'/crlf.txt', "one\r\ntwo\r\n");
    git($this->repo, 'add', '-A');
    git($this->repo, 'commit', '--quiet', '-m', 'crlf');

    // Rewrite with CRLF: the checkout normalised it to LF on the way out.
    file_put_contents($this->repo.'/crlf.txt', "one\r\ntwo\r\nthree\r\n");

    $unstaged = $this->fingerprint->capture();

    git($this->repo, 'add', '-A');
    $staged = $this->fingerprint->capture();

    git($this->repo, 'commit', '--quiet', '-m', 'more');

    expect(TreeDigest::sameContent($unstaged, $staged))->toBeTrue()
        ->and(TreeDigest::sameContent($staged, $this->fingerprint->capture()))->toBeTrue();
});

it('still produces a digest while a tracked file is deleted', function (): void {
    // A deleted path is in the dirty set, and handing it to `git hash-object`
    // fails the whole batch — which would either make the digest unavailable for
    // as long as any file is deleted, or record the fatal line as a hash. Its
    // contribution is that its index entry is DROPPED: not replaced, not hashed.
    file_put_contents($this->repo.'/second.php', "<?php // second\n");
    git($this->repo, 'add', '-A');
    git($this->repo, 'commit', '--quiet', '-m', 'two files');

    $before = $this->fingerprint->capture();

    unlink($this->repo.'/second.php');
    $deleted = $this->fingerprint->capture();

    expect($deleted)->not->toBeNull()
        ->and(TreeDigest::sameContent($deleted, $before))->toBeFalse();

    // And the deletion survives being staged, like any other change.
    git($this->repo, 'add', '-A');

    expect(TreeDigest::sameContent($this->fingerprint->capture(), $deleted))->toBeTrue();
});
