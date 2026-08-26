# Plan 006: Test Laravel 12 at its ceiling, not only its floor

> **Executor instructions**: Follow this plan step by step. Run every
> verification command and confirm the expected result before moving to the
> next step. If anything in the "STOP conditions" section occurs, stop and
> report — do not improvise. When done, update the status row for this plan
> in `plans/README.md` — unless a reviewer dispatched you and told you they
> maintain the index.
>
> **Drift check (run first)**: `git diff --stat a05b7fa..HEAD -- .github/workflows/run-tests.yml`
> On any change since this plan was written, compare the "Current state"
> excerpt against the live file; on a mismatch, STOP.

## Status

- **Priority**: P2
- **Effort**: S
- **Risk**: LOW
- **Depends on**: none
- **Category**: dx
- **Planned at**: commit `a05b7fa`, 2026-08-25

## Why this matters

`composer.json` declares `illuminate/*: ^12.41.1||^13.0`, but the only Laravel 12 CI cell runs `prefer-lowest`, which pins 12.41.1. Every 12.x release after the floor ships untested — a behaviour change or deprecation in a later 12.x minor reaches consumers before it reaches CI. One extra matrix row closes the gap; the existing Pest-4 downgrade step already keys on `matrix.pest == '4'`, so nothing else changes.

## Current state

`.github/workflows/run-tests.yml`, the matrix (with its explanatory comments):

```yaml
        include:
          # PHP floor is 8.4 — Pest 5 requires PHP ^8.4. `php: ^8.4` also admits
          # 8.5, so both are covered below.
          # Laravel 12 needs testbench 10, which pins symfony/process ^7.2 while
          # Pest 5 needs ^8.1 — so the Laravel 12 cell runs the Pest 4 toolchain
          # instead (see the downgrade step below).
          # Floor — lowest supported combo (prefer-lowest)
          - { php: '8.4', laravel: '13.*', testbench: '11.*', stability: prefer-lowest }
          # Ceiling — highest supported combo
          - { php: '8.4', laravel: '13.*', testbench: '11.*', stability: prefer-stable }
          # Highest PHP the `^8.4` constraint admits
          - { php: '8.5', laravel: '13.*', testbench: '11.*', stability: prefer-stable }
          # Laravel 12 — prefer-lowest, so this cell tests the floor of the declared
          # range rather than the newest 12.x release
          - { php: '8.4', laravel: '12.*', testbench: '10.*', stability: prefer-lowest, pest: '4' }
```

The downgrade step (further down in the same file) is already conditional:

```yaml
      - name: Downgrade the test toolchain for Laravel 12
        if: matrix.pest == '4'
```

The job name template is `P${{ matrix.php }} - L${{ matrix.laravel }} - ${{ matrix.stability }}`, so a second L12 row with a different `stability` gets a distinct name — no collision.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| YAML validity | `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/run-tests.yml')); print('ok')"` | prints `ok`, exit 0 |
| Workflow lint (if installed) | `zizmor .github/workflows/run-tests.yml` | no new findings |
| Local proof of the cell | see step 2 | suite passes |

## Scope

**In scope** (the only files you should modify):
- `.github/workflows/run-tests.yml`
- `plans/README.md` (status row for this plan only)

**Out of scope** (do NOT touch, even though they look related):
- `composer.json` — no constraint changes (the `symfony/process ^7.0` floor question is a separate lead recorded under "Audited, not planned" in plans/README.md).
- The other workflow files.
- The downgrade step itself — it already handles the new cell.

## Git workflow

- Branch from `main`: `ci/laravel-12-ceiling`
- Commit style: plain imperative sentence (see `git log --oneline`, e.g. "Cover the two joins a scoped run has to get right").
- Commit signing is enabled (ssh). If signing fails, STOP — never commit unsigned.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Add the matrix row

After the existing Laravel 12 row, add:

```yaml
          # Laravel 12 — prefer-stable, so the newest 12.x release is exercised
          # too, not only the declared floor
          - { php: '8.4', laravel: '12.*', testbench: '10.*', stability: prefer-stable, pest: '4' }
```

Match the file's comment style (each cell carries a one-line reason).

**Verify**: the YAML-parse command from the table → parses cleanly.

### Step 2: Prove the cell locally before relying on CI

In a DISPOSABLE copy of the repo (a separate clone or worktree — never the working tree, because this rewrites `composer.json`/`composer.lock` and `vendor/`):

```bash
git worktree add /tmp/l12-ceiling HEAD && cd /tmp/l12-ceiling
composer remove --dev pestphp/pest-plugin-rector pestphp/pest-plugin-phpstan pestphp/pest-plugin-agent --no-update --no-interaction
composer require --dev "pestphp/pest:^4.6.3" "pestphp/pest-plugin-arch:^4.0" "pestphp/pest-plugin-laravel:^4.0" --no-update --no-interaction
composer require "laravel/framework:12.*" "orchestra/testbench:10.*" --no-interaction --no-update
composer update --prefer-stable --prefer-dist --no-interaction
vendor/bin/pest --ci
```

(These are the workflow's own steps with the new cell's values. The worktree is at `HEAD` on purpose — it does not contain step 1's YAML edit, and does not need to: this proof is about dependency RESOLUTION for the new cell's values, not about the workflow file. Note: `composer update` fires this repo's `post-update-cmd` AutoSync script, which writes files — harmless inside the disposable worktree.) Afterwards: `cd -` and `git worktree remove --force /tmp/l12-ceiling`.

**Verify**: `vendor/bin/pest --ci` in the worktree → exit 0, all tests pass. If it fails, that is a REAL latent 12.x incompatibility — STOP and report the failure output; that discovery is the finding, and fixing it is not in this plan's scope.

## Test plan

No PHP tests change. The proof is step 2's local run plus, after merge, a green `P8.4 - L12.* - prefer-stable` job in Actions.

## Done criteria

- [ ] The new row exists; the workflow file parses as YAML
- [ ] The local prefer-stable L12 run (step 2) passed, and the worktree was removed
- [ ] `git status` in the real working tree shows `.github/workflows/run-tests.yml` as the only modified TRACKED file (`plans/` may appear as untracked — that is expected, not a violation)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- The matrix or downgrade step no longer matches the excerpts (drift).
- Step 2's dependency resolution fails (testbench 10 and a current 12.x refusing to co-install) — report the resolver output; the fix may be a constraint change, which is out of scope.
- Step 2's suite fails — report the failing tests verbatim; do not patch the suite or `src/` from this plan.

## Maintenance notes

- When Laravel 14 lands, this matrix pattern (floor cell + ceiling cell per major) is the shape to repeat.
- Reviewer should scrutinize: the new cell carries `pest: '4'` — without it the downgrade step is skipped and the cell fails on the symfony/process conflict the matrix comment describes.
