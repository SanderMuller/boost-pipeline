# Plan 007: Design a machine-readable receipt read path (spike, not a build)

> **Executor instructions**: This is a DESIGN/SPIKE plan. The deliverable is a
> spec file plus a small prototype proving the shape — not a shipped feature.
> Follow the steps, honor the STOP conditions, and when done update the status
> row for this plan in `plans/README.md` — unless a reviewer dispatched you and
> told you they maintain the index.
>
> **Drift check (run first)**: `git diff --stat a05b7fa..HEAD -- src/Console/VerifyCommand.php src/Run/Receipt.php specs/ tests/Feature/VerifyCommandTest.php`
> On drift, compare the "Current state" excerpts against the live code; on a
> mismatch, STOP.

## Status

- **Priority**: P2
- **Effort**: M (coarse — this is a spike; the build that may follow is scoped by the spec this produces)
- **Risk**: MED — a published read shape becomes a compatibility surface
- **Depends on**: none (but see Maintenance notes on audit finding "verify-policy extraction")
- **Category**: direction
- **Planned at**: commit `a05b7fa`, 2026-08-25

## Why this matters

The design record names this as the open thread, in the maintainer's own words (`.ai/docs/design-history.md`, "Two things left open"):

> The real fix is to let `evaluate` read a pipeline run and skip on a **receipt** instead of a recollection.

Today the only receipt reader is `pipeline:verify`, which answers with an exit code and prose. A downstream skill that wants "which steps passed, against which tree" has to parse English or shell out once per question. So the pilot consumer runs Formatting/StaticAnalysis/Tests through the pipeline, then `/evaluate` runs them again — the design's central saving, not collected. A machine-readable read path converts the receipt from a gate into an interoperable artifact, and it is the prerequisite for the deferred wiring work ("Wiring into `evaluate` / `final-verification-review` / `pull-requests` / `pr.gates`", deferred with reason "Prototype first" — the package that deferral was waiting on has shipped).

## Current state

**The reader** — `src/Console/VerifyCommand.php`:

```php
    protected $signature = 'pipeline:verify
        {--pipeline= : Which pipeline to ask about. Required when the project declares more than one.}
        {--only= : Ask whether this scope was verified, rather than the whole tree.}
        {--server-verified : Ask whether every verdict the server produced is a pass, setting aside steps it could only acknowledge.}';
```

Its acceptance rules (fingerprint match, empty-verdicts refusal, state/coverage checks, the `--server-verified` guards) live as ordered command branches that print and return `FAILURE`. Guard order is load-bearing — the empty-receipt check deliberately answers before the tree check.

**The data** — `src/Run/Receipt.php::toArray()`:

```php
        return array_filter([
            'run' => $this->runId,
            'state' => $this->state,
            'all_verified' => $this->allVerified,
            'tree' => $this->tree,
            'stale' => $this->stale,
            'verdicts' => $this->verdicts,
            'recorded_at' => $this->recordedAt,
            'scope' => $this->scope,
            'coverage' => $this->coverage,
            'asserted' => $this->asserted,
        ], static fn (mixed $value): bool => $value !== null);
```

**Standing decisions the design MUST honor** — `specs/verifying-what-the-server-ran.md`, Resolved Questions (quoted):

- RQ1, per-scope verify: "No. A tag-scoped predicate needs the walk, so `VerifyCommand` would load and execute consumer PHP to answer a question about a JSON file ... Revisit only when a consumer needs per-scope verify."
- RQ2, `--step=<id>`: "No. A step id is project-chosen, so a downstream skill cannot name one generically; it would need a check-to-step-id mapping written down somewhere, which is coupling with none of the validation a config-declared tag gets."
- RQ3, naming passing steps in prose output: "No. It gives a caller prose to parse instead of an exit code, and makes every message a compatibility surface. The exit code is the interface."
- RQ4, consumers reading `receipt.json` directly: "No. Freshness is a comparison against a fingerprint whose algorithm lives here. A consumer doing it themselves either re-implements that or omits it, and the second is both more likely and more dangerous."

Read RQ3 and RQ4 together and the unclaimed territory is precise: the package computes the answers (freshness included) and emits them in a STRUCTURED shape — for example `pipeline:verify --json`. Against RQ4 this is clean: the consumer never touches `receipt.json` or the fingerprint algorithm — the package hands over the computed answer, which is the shape RQ4 implicitly demands. Against RQ3, be honest about both of its clauses: the "prose to parse" objection is not triggered (a declared JSON contract is not prose), but the "every message a compatibility surface" objection IS — `--json` deliberately creates a versioned compatibility surface, and answers that objection with an explicit guaranteed-key list under semver rather than by denial. The spec must say this outright. Supporting context: the shipped success path already names the passing steps in its output (`src/Console/VerifyCommand.php:316-319`, with a comment explaining why), so the repo has already accepted that exit 0 alone is too thin an answer; RQ3's refusal was about the NON-ZERO prose paths.

Vocabulary trap the executor must not fall into: "stale" is overloaded here. `Receipt::$stale` is a SELF-REPORTED field the run recorded about itself, with its own guard (`VerifyCommand.php:84-88`); the tree-mismatch guard (`VerifyCommand.php:75-82`) is a SEPARATE comparison of `receipt->tree` against the current fingerprint. This plan's `fresh` key means ONLY the second: `fresh` = receipt tree equals the tree on disk now. The self-reported `stale` field is carried through as its own key, never folded into `fresh`.

**Also standing** (`.ai/docs/design-history.md`, "Rejected, with the reason to keep rejecting it"): pipeline-level baselining, oracle steps, step-to-step data passing. None of these may creep into the design.

## Commands you will need

| Purpose | Command | Expected on success |
|---|---|---|
| Full gate | `composer qa-check` | exit 0 |
| One file | `vendor/bin/pest tests/Feature/VerifyCommandTest.php` | all pass |

## Suggested executor toolkit

- If the `write-spec` skill is available in your environment, use it for the spec file — the repo's three existing specs follow its shape (Overview / Assumptions / numbered design sections / Edge Cases / Implementation phases / STOP conditions / Open and Resolved Questions). Otherwise copy the structure of `specs/verifying-what-the-server-ran.md`.
- Read `.ai/docs/invariants.md` and `.ai/docs/design-history.md` in full before designing — required background (`.ai/docs/README.md` mandates the invariants doc "Before touching `src/Run/`, `src/Runner/` or `src/Mcp/`"; this spike edits `src/Console/`, which is not on that list, but the invariants govern what the JSON may CLAIM, so read it anyway).
- Repo writing rule (`AGENTS.md`): specs are synthetic and provenance-free — no internal PR/ticket numbers, no consumer-app names, neutral example nouns only.

## Scope

**In scope**:
- `specs/receipt-read-path.md` (create — the primary deliverable)
- A prototype: `--json` on `pipeline:verify` in `src/Console/VerifyCommand.php`, plus tests in `tests/Feature/VerifyCommandTest.php` (prototype quality gates still apply: `composer qa-check` green)
- `plans/README.md` (status row for this plan only)

**Out of scope** (do NOT touch):
- `CHANGELOG.md` — CI-managed (`AGENTS.md`: a workflow prepends release bodies; never hand-edit it, despite the "Update CHANGELOG" commits in the log).
- Any consumer skill (`evaluate` etc.) — wiring lives in consumer repos; this spike only proves the shape they would consume.
- Per-scope verify, `--step`, changes to exit-code semantics, new receipt keys — all settled (see Current state).
- `src/Mcp/*` — no new MCP tool in this spike; whether the read path should ALSO be an MCP tool is an open question for the spec, not a prototype target.
- README — document only when the feature ships, not the spike.

## Git workflow

- Branch from `main`: `spike/receipt-read-path`
- Commit style: plain imperative sentence (see `git log --oneline`).
- Commit signing is enabled (ssh). If signing fails, STOP — never commit unsigned.
- Do NOT push or open a PR unless the operator instructed it.

## Steps

### Step 1: Draft the spec

Write `specs/receipt-read-path.md` answering, at minimum:

1. **Shape**: the JSON document `--json` emits. Starting point — everything the command already computes: `run`, `state`, `all_verified`, `coverage`, `scope`, `verdicts` (id → verdict string), `asserted`, `recorded_at`, the self-reported `stale` field, plus the COMPUTED answers a consumer must not derive: `fresh` (bool — receipt tree vs current tree ONLY, see the vocabulary trap in Current state), a failure `category` when not verified, and the `--server-verified` answer. Enumerate the categories in the spec and use those exact literals in the tests — provisional list: `no_run`, `empty_verdicts`, `tree_mismatch`, `self_stale`, `scope_mismatch`, `not_verified`, `no_pipeline_selected`, `unknown_pipeline`. The last two matter because `storeFor()` (`VerifyCommand.php:131-166`) refuses BEFORE any receipt exists — the shape must define what those receipt-less documents contain. Decide and record: is the raw `tree` fingerprint included or withheld?
2. **Contract**: exit code semantics unchanged (`--json` changes the FORMAT of the answer, never the answer); the JSON keys are a compatibility surface governed by semver — say which keys are guaranteed and what "additive only" means here.
3. **Consumer story**: one worked example — a skill (call it a "review skill", no product names) asking "did static analysis pass against this exact tree?" via `pipeline:verify --json` + a `jq` expression, and what it does on `fresh: false`.
4. **Open questions** (recorded, not resolved): should this also be an MCP tool or resource; does `--json` on the multi-pipeline `--pipeline` selector need a list mode; how does a consumer discover step ids generically given RQ2's standing rejection.

**Verify**: `grep -c 'Resolved Question' specs/receipt-read-path.md` → at least 2 (the spec must cite RQ3 and RQ4 of `specs/verifying-what-the-server-ran.md` by name and answer both clauses of RQ3 — see Current state).

### Step 2: Prototype `--json`

Add the flag to `VerifyCommand`. Implementation constraint: do NOT restructure the guard ordering — the cheapest honest prototype collects the same answers the existing branches compute and emits one `$this->output->writeln(json_encode(...))` instead of the prose lines when the flag is set.

Know the real shape of the work before starting — the guards are NOT uniform: `storeFor()` prints and returns `null` before any receipt exists; `scopeMismatch()` and `explainUnverified()` return message strings; `answerServerVerified()`/`reportAssertions()` print inline across several guards. Roughly a dozen `$this->components->error(...)` sites across four methods must route to the single JSON emitter, and under `--json` ALL `components->*` output must be suppressed or the emitted document will not parse. If that forces duplicating guard logic, note the duplication in the spec's findings (it is the evidence for the "extract a verification policy object" refactor recorded under "Audited, not planned" in plans/README.md) rather than doing the refactor here.

**Verify**: `vendor/bin/pest tests/Feature/VerifyCommandTest.php` → existing tests untouched and green.

### Step 3: Test the prototype

In `tests/Feature/VerifyCommandTest.php` (model on its existing cases — it covers every guard):

1. Green receipt + `--json` → exit 0, output parses as JSON, `all_verified: true`, `fresh: true`.
2. Tree mismatch + `--json` → build a receipt recording one fingerprint against a tree reporting another (the file's own helpers: `receipt(tree: 'tree-a')` + a fingerprint double returning `'tree-b'` — read how existing tree-mismatch cases arrange it) → exit 1, `fresh: false`, `category: "tree_mismatch"`.
3. Self-reported stale receipt + `--json` → `receipt(stale: '...')` with a MATCHING tree → exit 1, `fresh: true` (the tree did not move), `category: "self_stale"`. This is the case that proves the vocabulary split in Current state.
4. No receipt + `--json` → exit 1, output parses as JSON, `category: "no_run"`.
5. Multi-pipeline project without `--pipeline` + `--json` → exit 1, output parses as JSON, `category: "no_pipeline_selected"` (the receipt-less document shape from step 1).
6. `--json --server-verified` → the server-verified answer appears in the document.

Every case also asserts the exit code equals the prose path's exit code for the same arrangement.

**Verify**: `vendor/bin/pest tests/Feature/VerifyCommandTest.php` → all pass, including 6 new.

### Step 4: Record findings in the spec

Whatever the prototype surfaced — guard duplication, shape awkwardness, a key that wanted to exist — goes into the spec's Findings section. The spec, not the code, is the artifact the maintainer reviews to decide whether the build proceeds.

**Verify**: `composer qa-check` → exit 0.

## Test plan

Covered in step 3. Verification: `vendor/bin/pest` → all pass.

## Done criteria

- [ ] `specs/receipt-read-path.md` exists, cites RQ3/RQ4, records open questions and prototype findings
- [ ] `pipeline:verify --json` works for the six tested cases; exit codes identical to the prose path in every case
- [ ] `composer qa-check` exits 0
- [ ] No files outside the in-scope list are modified (`git status`)
- [ ] `plans/README.md` status row updated

## STOP conditions

Stop and report back (do not improvise) if:

- Emitting JSON requires changing any guard's ORDER or any exit code — that is a behaviour change this spike must not make.
- The design pulls toward per-scope answers, `--step`, or a consumer-side freshness check — all standing rejections; the spec may DISCUSS them, the prototype may not implement them.
- The prototype needs a new key persisted INTO the receipt — receipt-shape changes are a different piece of work with compat implications (see `specs/verifying-what-the-server-ran.md` RQ5/RQ6 for how carefully the last key was added); record the need in the spec and stop there.

## Maintenance notes

- The verify-policy extraction lead ("verify-policy logic locked inside `VerifyCommand`", see "Audited, not planned" in plans/README.md) is the natural refactor BEFORE the production build of this feature: a policy object returning a structured result makes `--json` a serializer instead of a second copy of the guards. The spike deliberately tolerates duplication to keep scope honest; the spec should say whether the build requires the refactor first.
- Once the JSON shape ships, every key is public API — additions are minor versions, removals are major. The spec's "guaranteed keys" list is the thing reviewers must gate hardest.
- The consumer wiring (`evaluate` reading the receipt) is the follow-up this spike exists to unblock; it lives in the consumer's repo and its skills, not here.
