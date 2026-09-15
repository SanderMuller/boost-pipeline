# A run record that travels with the push

<!-- spec:planned-at ff0967fbb00e3f0f773f287874a11e16bf063e2b 2026-09-07 -->

## Overview

Nothing a run writes leaves the machine. The receipt sits under `storage/logs/`, which Laravel
gitignores, so a reviewer reading a pull request sees no record and a CI job has nothing to read. A
PR checkbox saying the pipeline ran is therefore backed by nothing a second party can check.

> **Superseded premise, read before implementing.** This spec was written while the tree
> fingerprint keyed on `rev-parse HEAD` plus dirty contents. It now keys on tree CONTENT, so a
> commit no longer moves it. Both STOP conditions still hold — a note still disturbs nothing, and
> committing a receipt still adds a file and so still moves a content digest — but one consequence
> reverses: a receipt written before the commit now describes the commit's tree, so `publish` no
> longer implies a walk that ran *after* committing. The "refuse when the receipt does not describe
> `HEAD`" rule below needs rewriting against `TreeDigest` before anyone builds it.

This publishes the receipt as a **git note** on the commit, which travels with a push and does not
disturb the tree fingerprint. It deliberately does not claim to prove that the steps ran: a local
receipt is a self-report, and no mechanism inside this package can make one unforgeable. What it can
make checkable is the **absence** — that no run was ever claimed for this commit — which is the one
row in the false-green registry marked as unmitigated.

## Assumptions

- **Committing the receipt cannot work, and this is not a preference.** The tree fingerprint is
  `rev-parse HEAD` plus the contents of every dirty path, so committing a receipt changes HEAD and
  the receipt then describes a different tree than the commit carrying it. Verified while planning:
  the digest changed across a commit that added one file. Any design that puts the record in the
  tree is self-invalidating. Load-bearing.
- **A git note does not disturb the fingerprint.** Notes live under `refs/notes/`, outside the
  commit's tree and outside `git status`. Verified while planning: the fingerprint was
  byte-identical
  before and after `git notes add`. Load-bearing — it is the only reason this approach exists.
- **Unforgeability is not achievable here, and pretending otherwise would be the worst thing this
  package could ship.** The verdicts in a receipt are a self-report. A note can be hand-written.
  Even
  signing only proves that whoever held the key wrote it, never that a step ran. Only re-execution
  by
  a party that does not trust the working copy attests to that, which is what CI already is.
- **The valuable half is the absence, and absence cannot be forged.** A present note is a claim: it
  says which steps this author says ran, against which tree, at which time. A missing note is
  evidence — no run was claimed for this commit. Registry row 5 records that a run which never
  happened produces no signal at all, and that closing it "is a requirement on the consumer". A
  pushed note makes that requirement mechanisable.
- **This is the first time the package would WRITE to git.** Everything today reads: `status`,
  `rev-parse`. Writing a note mutates the repository, which is a different kind of permission to
  hold
  and needs to be opt-in rather than a side effect of resolving a step. Load-bearing.
- **Notes are not fetched by default**, by `git clone`, `git fetch`, or `actions/checkout`. A CI job
  that forgets the refspec sees every commit as unclaimed. That failure is indistinguishable from a
  real absence unless the read side separates them, so it must.
- **A rebase or squash orphans the note.** A note binds to a commit SHA. Rebasing rewrites the
SHA, so
  the note no longer applies and the commit reads as unclaimed. That is the correct direction — a
  rewritten commit was never run against — and it means the record is worth publishing late rather
  than early.

---

## 1. Current state

`JsonReceiptStore` writes `storage/logs/pipeline/receipts/<pipeline>.json`, and its docblock names
the gitignored location as the reason it lives there. `pipeline:verify` and `pipeline:history`
read it
from that path in the consumer's own process. `GitTreeFingerprint` shells out to git but only ever
reads — a grep for git subcommands in `src/` returns `status` and `rev-parse` and nothing else.

The README states the consequence plainly today: "This is a local gate, not a CI one. The receipt
lives under `storage/logs/`, which Laravel gitignores, so it does not travel with a push."

`.ai/docs/invariants.md` row 5 is the only row marked **Yes** under "can this produce a false
green",
with the mechanism column reading "Nothing here can close it". The note beneath the table explains
that a consumer must treat "no run" as failing, and that wiring this into a PR flow was deferred
rather than half-done.

## 2. What this can and cannot say

| Question a reader has | Answerable? |
|---|---|
| Was a run claimed for this exact commit? | **Yes.** The note exists or it does not, and a missing note cannot be faked. |
| Which steps does the author say ran, and which were only acknowledged? | **Yes**, as a claim. The receipt already separates a server verdict from an acknowledgement. |
| Does the claim describe this commit, or an earlier one? | **Yes.** The receipt carries a tree fingerprint, which a reader recomputes. |
| Did the steps actually run and pass? | **No.** A self-report cannot answer this. CI re-running the checks does. |

The third row is what makes the first two worth having: a claim bound to a commit cannot be recycled
from an earlier green run, which is the mistake an honest author actually makes.

## 3. Proposed changes

Two commands, one writing and one reading, so the permission to mutate a repository is never held by
the read side.

**`php artisan pipeline:publish`** writes the current receipt as a git note on `HEAD`, under
`refs/notes/boost-pipeline/<pipeline>` so two pipelines cannot overwrite each other. It refuses when
the receipt does not describe `HEAD`'s tree, because publishing a record of a different tree is the
whole failure being avoided. It never runs as a side effect of a step.

**`php artisan pipeline:claimed <commit>`** reads the note and exits 0 only when one exists for that
commit, its recorded tree matches the commit, and it reports the verdicts asked for. Its success
message says what it is: a claim by the author, checked against this commit, not an attestation that
anything ran.

It distinguishes three outcomes, because they need different actions:

- **No notes ref at all** — almost certainly a CI job that did not fetch `refs/notes/*`. Names the
  refspec. Exits non-zero, because it cannot answer.
- **Notes ref present, nothing for this commit** — no run was claimed. Exits non-zero. This is the
  answer registry row 5 wanted.
- **A note that describes a different tree** — the claim is about other code. Exits non-zero.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Receipt describes a tree other than `HEAD` | `publish` refuses. Publishing a record of another tree is the failure being avoided, not a case to tolerate. |
| Commit amended or rebased after publishing | The note is orphaned and the commit reads as unclaimed. Correct direction, and documented: publish late. |
| Squash merge | The merge commit has no note. The claim covers the branch commit it was made against, which is the honest scope. |
| CI never fetched `refs/notes/*` | Distinguished from a real absence and told the refspec. Both exit non-zero; a job that cannot answer must not pass. |
| Two pipelines in one project | One notes ref per pipeline name, so publishing `change` cannot erase `release`. |
| `publish` run twice on one commit | Overwrites its own pipeline's note. The newest receipt is the claim; an older one describes a superseded run. |
| No receipt recorded at all | `publish` refuses with the same reasoning `pipeline:verify` already uses: nothing was recorded, so there is nothing to publish. |
| A hand-written note | Passes `claimed` if its tree matches. Documented as the limit of what this can do, and the reason the command never uses the word proof. |
| Not a git repository | `publish` and `claimed` both refuse, stating why. Consistent with `GitTreeFingerprint` returning null rather than throwing. |
| Notes ref diverges between two machines | A push of `refs/notes/*` can be rejected as non-fast-forward. Report the git error rather than forcing; a lost note reads as absent, which fails closed. |

## Implementation

### Phase 1: Publish (Priority: HIGH)

**ID:** publish · **Depends:** none

- [ ] Add a write seam for git. Everything today reads through `GitTreeFingerprint`'s private
helper; publishing needs `notes add`, which mutates. Keep it separate from the fingerprint class
so the read path cannot grow write powers by accident.
- [ ] Add `pipeline:publish`, writing the receipt for the resolved pipeline as a note on `HEAD`
under `refs/notes/boost-pipeline/<pipeline>`.
- [ ] Refuse when the receipt is absent, or when its tree does not match `HEAD`. Reuse
`pipeline:verify`'s wording for those two cases rather than inventing new sentences for the same
facts.
- [ ] Never publish as a side effect of a run. It is a command a person or a hook invokes.
- [ ] Tests — a published note round-trips the receipt; the note does not change the tree
fingerprint (assert the digest before and after, since the whole design rests on it); publishing
refuses a receipt describing another tree; refuses with no receipt; two pipelines do not overwrite
each other.

### Phase 2: Read the claim (Priority: HIGH)

**ID:** claimed · **Depends:** publish

- [ ] Add `pipeline:claimed <commit>`, exiting 0 only when a note exists for that commit, its tree
matches, and the verdicts asked for hold.
- [ ] Separate the three failing outcomes: no notes ref, no note for this commit, note describing
another tree. Name the fetch refspec in the first.
- [ ] The success message states that this is the author's claim checked against this commit, and
that it is not evidence the steps ran. The word proof appears nowhere.
- [ ] Tests — each of the three failures exits non-zero with its own message; a matching claim
exits 0; a hand-written note with a matching tree also exits 0, pinned deliberately so the limit
is visible in the suite rather than only in prose.

### Phase 3: Say what it is (Priority: HIGH)

**ID:** docs · **Depends:** claimed

- [ ] README — a section that leads with what this cannot do. The table from `## 2` belongs there
almost verbatim.
- [ ] The CI paragraph currently says the receipt does not travel. It now can, so it needs the
distinction: the record travels, the attestation does not, and CI re-running the checks is still
the only thing that proves anything.
- [ ] `UPGRADING.md` — two new commands, opt-in, no behaviour change to anything existing.
- [ ] `.ai/docs/invariants.md` — row 5 moves from "nothing here can close it" to naming what the
absence of a note now settles, and what it still cannot.
- [ ] A row in the README's *deliberately does not do* table: prove that a step ran.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **A note does not disturb the tree fingerprint.** Verified at spec time and asserted by a Phase 1
   test. If adding a note ever moves the digest, this design is dead rather than adjustable: the
   record would invalidate the thing it describes.
2. **The receipt is publishable unchanged.** If publishing needs a different shape from the one on
   disk, stop. Two shapes for one record is how a reader ends up trusting the weaker of them.
3. **Nothing describes this as proof or attestation.** Not the messages, not the command name, not
   the docs. The package's whole value is refusing to launder a claim into a verdict, and a portable
   receipt is the easiest place it could happen.
4. **`publish` stays out of the run path.** If it starts happening automatically at any point in a
   walk, stop: writing to a repository is not something a step resolution should do.

---

## Open Questions

1. **Should this ship the read side at all?** `pipeline:claimed` is the half a consumer's CI runs,
and
   it is also the half most likely to be read as an attestation despite its own message. Shipping
   only
   `publish` would leave the record available and let each consumer decide what to make of it, at
   the
   cost of everyone writing the same check.

   Recommend shipping both, because a consumer writing that check by hand is the party most likely
   to
   get the three-way distinction wrong and pass a job that cannot answer.

2. **Is `claimed` the right name?** It is honest and it is unusual. `pipeline:attested` would read
   more naturally to a CI author and would be a lie. Flagging rather than settling, since the name
   is
   the first thing a reader takes the semantics from.

---

## Resolved Questions

1. **Commit the receipt, or attach it to the commit?** **Decision:** Attach. **Rationale.**
Committing
   changes `HEAD`, and the fingerprint is `rev-parse HEAD` plus dirty contents — so the receipt
   would
   describe a tree other than the commit carrying it, and `pipeline:verify` would refuse it.
   Verified
   rather than reasoned: a commit adding one file changed the digest, and `git notes add` left it
   byte-identical.

2. **Should the note be signed?** **Decision:** No. **Rationale.** A signature proves that whoever
   held the key wrote the note. It does not prove a step ran, because the same key signs a
   fabricated
   receipt just as well. Adding one would buy no guarantee and would make the record look like an
   attestation, which is precisely the confusion this spec is trying not to create.

3. **What is the guarantee, stated plainly?** **Decision:** The absence, not the presence. A missing
   note is reliable evidence that no run was claimed for this commit; a present note is a claim
   bound
   to that commit. **Rationale.** It is the only asymmetry available — a claim can be fabricated, an
   absence cannot — and it happens to be the half registry row 5 needs.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
