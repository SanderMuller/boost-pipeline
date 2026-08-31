# Validate the gate across two real processes

<!-- spec:planned-at f92f94ce264cf4f167559528eef9f730edd70ee4 2026-08-31 -->

## Overview

Every test of `pipeline:verify` runs in one process, with the config bound into the container by
hand. The config digest exists precisely because two SEPARATE processes read the config — the MCP
server writes a run, and the command compares it later — so the one property the digest depends on is
the one nothing tests.

This adds tests that write a receipt in one process and verify it in another, against a real config
file on disk. It reproduces the observable condition a stale server produces, and it is the only
shape that can catch a digest that is not stable across processes.

## Assumptions

- **Cross-process instability is a demonstrated risk, not a theoretical one.** Two inputs have
  already had to be removed from the digest for exactly this reason: env VALUES, because `withEnv()`
  resolves its array at config load, and float precision, because `serialize()` renders floats per an
  ini setting. Both were found by reading, and neither would have been caught by any existing test.
  Load-bearing — it is the whole justification for the work.
- **The gap is process boundaries, not coverage.** The behaviour is well covered in-process. A
  same-process test cannot fail on a digest that differs between processes, because there is only one.
- **`vendor/bin/testbench <command>` runs a real, separate process.** Verified before writing this:
  `vendor/bin/testbench pipeline:verify` executed and produced a genuine refusal from a real config
  load. Load-bearing — without it there is no second process to test with.
- **A stale server does not need a long-lived server process to reproduce.** What the gate observes is
  a receipt whose digest came from declaration A while the disk now produces declaration B. Writing
  the receipt, editing the config, then verifying reproduces that faithfully. The part that genuinely
  needs a live server — a client holding config across a reconnect — stays out of scope, because it
  tests the MCP client's lifecycle rather than this package's logic.
- **These tests are slower than the suite around them and that is acceptable at this count.** Each
  spawns a PHP process. A handful is worth it for the only coverage of this property; dozens would
  not be, which is why the scope below is deliberately small.
- **`tests/Feature/ConsoleServerProcessTest.php` is not precedent.** Its name suggests process-level
  testing, but it inspects `argv` detection in-process. Nothing in the suite currently spawns one, so
  this establishes the pattern rather than following it.

---

## 1. Current state

`tests/Feature/VerifyCommandTest.php` binds `Pipelines` into the container and calls
`Artisan::call('pipeline:verify')`. One process, one config object, one digest computation. The
digest's whole purpose — that a declaration hashed in the server's process still hashes the same in
the command's process — is asserted nowhere.

The two determinism corrections already made are the evidence. `PipelineFingerprint` excludes env
values and normalises floats, both because two processes could otherwise disagree about an identical
config. `tests/Unit/PipelineFingerprintTest.php` covers each by simulating the difference in one
process — it changes `serialize_precision` with `ini_set` and hashes twice. That is a good proxy and
it is not the real thing: it cannot catch an input that varies for a reason nobody thought to
simulate.

## 2. Proposed changes

A test that uses the filesystem and a subprocess as the seam:

1. Write a real `.config/pipeline.php` into the Testbench application path.
2. Open and resolve a run in the test process, writing a receipt with a digest.
3. Run `vendor/bin/testbench pipeline:verify` as a subprocess and assert the exit code.
4. Edit the config file so the declaration differs, run the subprocess again, and assert it refuses.
5. Revert the edit and assert it passes again, so the check is shown to be symmetric rather than
   sticky.

Step 3 is the one with no in-process equivalent: the subprocess loads the config from disk itself,
computes its own digest, and compares. If any digest input is environment-dependent, step 3 fails
where every current test passes.

Helpers go in the test file rather than a shared harness: one file needs them, and a harness invites
more of these than the runtime cost justifies.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| The subprocess cannot run (no `vendor/bin/testbench`, PHP not on PATH) | Skip with a stated reason, never fail. A missing local toolchain is not a defect in the package, and a test that fails for it teaches people to ignore failures. |
| The subprocess is slow or hangs | An explicit timeout on every `Process`, well under the suite's patience, so a hang reports as a failed assertion rather than a stalled run. |
| The test leaves a config file behind | `afterEach` removes it. A stray `.config/pipeline.php` changes what every other test in the suite resolves — the existing `ScopedRunTest` writes one and shows the pattern. |
| Receipt path differs between the two processes | Both resolve `storage_path()` from the same Testbench app, so they agree. Asserted directly rather than assumed: the test reads the receipt back before it verifies. |
| The digest is genuinely unstable across processes | This is the failure the tests exist to produce. It surfaces as a refusal on step 3 with an unchanged config, which no other test can produce. |
| Running under `pest --parallel` | The config path is per-application and the tests write the same one. They are grouped so they do not run concurrently with each other or with `ScopedRunTest`, which writes the same file. |

## Implementation

### Phase 1: A verify that crosses a process boundary (Priority: HIGH)

**ID:** crossing · **Depends:** none

- [ ] Add `tests/Feature/CrossProcessVerifyTest.php` — write a real config file, resolve a run in-process, then run `pipeline:verify` through `vendor/bin/testbench` as a subprocess and assert exit 0.
- [ ] Skip rather than fail when the binary or PHP is unavailable, stating why.
- [ ] Give every subprocess an explicit timeout, so a hang is a failed assertion.
- [ ] Clean the config file in `afterEach`, and keep these tests off the same file as `ScopedRunTest` when running in parallel.
- [ ] Tests — the passing case proves the digest computed in the subprocess equals the one recorded in the test process. Confirm it is not vacuous by asserting the receipt actually holds a digest before the subprocess runs.

### Phase 2: The mismatch, end to end (Priority: HIGH)

**ID:** mismatch · **Depends:** crossing

Depends on `crossing` because it reuses its harness, and because a mismatch assertion means nothing
until the matching case is known to pass.

- [ ] Edit the config file so one step's declaration differs without changing its id — the shape a stale server produces — and assert the subprocess exits 1.
- [ ] Assert the message names the declaration rather than accusing the server, matching what the command promises.
- [ ] Revert the edit and assert exit 0 again, proving the check is symmetric rather than sticky.
- [ ] Assert an inert edit still counts: a change inside the digest that alters no behaviour must refuse, because the gate answers "is this the declaration you ran" and not "does it matter".
- [ ] Tests — mutation-check by reverting the digest comparison in `VerifyCommand` and confirming the mismatch case goes green, which proves these tests exercise the gate and not just the harness.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **`vendor/bin/testbench pipeline:verify` runs and loads the project's config.** Verified at spec
   time. If it cannot be made to see the test's config file, there is no second process to test with
   and the approach needs rethinking rather than forcing.
2. **The two processes agree on `storage_path()`.** If the subprocess writes or reads a different
   receipt path, the test would be comparing nothing and would pass for the wrong reason. Assert it,
   do not assume it.
3. **A cross-process failure appears with an UNCHANGED config.** That is not a test defect to work
   around — it is the digest being unstable across processes, which is a real bug in
   `PipelineFingerprint`. Stop and report it rather than adjusting the test until it passes.

---

## Open Questions

None.

---

## Resolved Questions

1. **Should this drive a real long-lived MCP server process?** **Decision:** No. **Rationale.** What
   the gate observes is a receipt whose digest came from one declaration while the disk now produces
   another, and that is reproducible with a file edit between two processes. A live server would add
   client-lifecycle machinery to test something this package does not own, and every hour spent on it
   buys coverage of the MCP client rather than of the digest.
2. **A shared process-test harness, or helpers in one file?** **Decision:** Helpers in the file.
   **Rationale.** One file needs them. A harness would make it cheap to add more subprocess tests,
   and these cost a PHP process each — cheapness is the wrong incentive here. Extract it when a
   second file genuinely needs it.
3. **Fail or skip when the toolchain is missing?** **Decision:** Skip, with the reason stated.
   **Rationale.** A missing local binary is not a defect in the package. A suite that fails for
   environmental reasons trains people to ignore failures, which costs more than the coverage gained.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
