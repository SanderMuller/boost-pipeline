# Version the config digest format

<!-- spec:planned-at f92f94ce264cf4f167559528eef9f730edd70ee4 2026-08-31 -->

## Overview

`PipelineFingerprint` writes a bare digest into every receipt, so the digest is a persisted data
format and not only an API. Changing the hashing algorithm later would make every existing digest
stop matching, and `pipeline:verify` would read that as a declaration mismatch — failing every
consumer's gate with a message blaming a stale server that was never stale.

Tagging the digest with a format version lets a future change read as *unknown* instead, which the
command already degrades gracefully on. This is cheap now and gets more expensive as digests
accumulate: v0.14.0 shipped hours ago, so almost none exist yet.

## Assumptions

- **A digest algorithm change is a realistic future event, not a hypothetical.** This spec exists
  because two inputs were already corrected once — env values excluded, floats normalised — after
  the format shipped. Both changed what the digest produces. A third correction is likelier than not.
- **The failure mode is a false failure, and that is why it is worth pre-empting.** A changed
  algorithm produces a digest that differs from the recorded one, which is indistinguishable from a
  changed declaration. `pipeline:verify` refuses, names a stale server first among the causes, and
  the reader hunts something that does not exist. Load-bearing: if a mismatch were merely reported
  rather than gating, this would be cosmetic.
- **Unprefixed digests already on disk were produced by the CURRENT algorithm.** Only v0.14.0 writes
  them, and nothing has changed the algorithm since. So an unprefixed digest stays comparable by
  computing today's digest — it is legacy in FORM, not in content. Load-bearing: if the algorithm
  changes in the same release as this change, that stops being true and the two must not ship
  together.
- **An unrecognised format is unknown, and unknown follows the rule already set.** The bare call
  ignores it, `--server-verified` refuses it. That is where `coverage`, `asserted` and an absent
  `config` already sit, so this adds no new policy — see Resolved Question 2.
- **The prefix is part of the recorded string, not a second field.** A separate receipt key would
  make the pair splittable: a digest could arrive with no format, or a format with no digest, and
  every reader would need to handle both. One string cannot come apart.
- **Nothing outside this package parses the digest.** It is written and read by `Receipt`, compared by
  `VerifyCommand`, and compared by `PipelineOverview`. A consumer reading it would be reading an
  opaque token, which is what it stays.

---

## 1. Current state

`src/Config/PipelineFingerprint.php:45` returns a bare 16-character digest:

```php
return substr(hash('xxh3', serialize(self::canonical($pipeline))), 0, self::DIGEST_LENGTH);
```

`Receipt` stores it verbatim in `config` (`src/Run/Receipt.php`), and two places compare it by string
equality:

- `src/Console/VerifyCommand.php` — `PipelineFingerprint::for($pipeline) === $receipt->config`,
  refusing the run when they differ.
- `src/Run/PipelineOverview.php` — `digestMatches()`, feeding `config_matches` on the page,
  `pipeline:history` and its list rows.

Nothing records which algorithm produced a digest. A digest from a future algorithm is therefore
indistinguishable from a digest of a different declaration, and both take the mismatch branch.

## 2. Proposed changes

Prefix the digest with a format version:

```php
private const string FORMAT = 'v1';

// v1:6f3a9c2b18d4e057
return self::FORMAT.':'.substr(hash('xxh3', serialize(self::canonical($pipeline))), 0, self::DIGEST_LENGTH);
```

Comparison becomes a three-way answer rather than a boolean, expressed as one enum-free helper on
`PipelineFingerprint`:

```php
public static function matches(Pipeline $pipeline, string $recorded): ?bool
```

- `true` — same format, same digest.
- `false` — same format, different digest. A real declaration change.
- `null` — a format this build cannot produce. Unknown, and treated exactly as an absent digest is.

An **unprefixed** recorded digest is accepted as v1 content, because only v0.14.0 wrote one and the
algorithm has not changed since. That keeps every receipt written today comparable rather than
turning it unknown on upgrade — which would be a self-inflicted version of the very false failure
this spec prevents.

Both call sites then read the same three-way answer, and `null` routes into the paths that already
exist for an absent digest.

## Edge Cases

| Scenario | Handling |
|----------|----------|
| Receipt written by v0.14.0 (no prefix) | Compared as v1 content, so it keeps working. The next run rewrites it prefixed. Tested. |
| Receipt from a future format (`v2:…`) | Unknown: bare call ignores it, `--server-verified` refuses it naming the format. Never a mismatch. Tested. |
| Receipt whose value is malformed (`v1:` with nothing, `:abc`, `v1:v1:abc`) | Unknown, not a mismatch. A value this build cannot have produced says nothing about the declaration. Tested. |
| A digest that happens to contain a colon in its hash portion | Cannot occur — `hash('xxh3')` is hex — but the parser splits on the FIRST colon only, so it stays correct if the alphabet ever widens. Tested. |
| Surfaces (`page`, `pipeline:history`) see an unknown format | `config_matches` is null, which those surfaces already render as unknown rather than as mismatched. Tested. |
| The algorithm changes in the same release as this change | A STOP condition, not an edge case: unprefixed digests would no longer be comparable and the grandfathering above would be wrong. |

## Implementation

- [ ] Add a `FORMAT` constant and prefix the digest in `src/Config/PipelineFingerprint.php` — one place produces it, so one place tags it.
- [ ] Add `PipelineFingerprint::matches(Pipeline $pipeline, string $recorded): ?bool`, returning null for a format this build cannot produce. Put the parsing beside the producing, so a future format's rules live next to the code that made the last one.
- [ ] Accept an unprefixed recorded digest as v1 content, with the reason in a comment: only v0.14.0 wrote one and the algorithm is unchanged, so treating it as unknown would cause the same false failure this spec prevents.
- [ ] Use it in `src/Console/VerifyCommand.php`. `null` must take the absent-digest path, NOT the mismatch path — that is the entire point of the change.
- [ ] Use it in `src/Run/PipelineOverview.php::digestMatches()`, which already returns `?bool` and already means unknown by null.
- [ ] Tests — a v1 digest round-trips and compares equal; an unprefixed digest compares equal; a `v2:` digest and every malformed shape read as unknown on both call sites; unknown refuses under `--server-verified` and passes the bare call; the page and history read unknown rather than mismatched. Mutation-check by making `matches()` return false instead of null for an unknown format and confirming the tests that pin the distinction go red.
- [ ] `UPGRADING.md` — the digest gains a `v1:` prefix, receipts written by 0.14.0 keep working, and an unrecognised format is treated as unknown rather than as a mismatch.

---

## STOP Conditions

Stop and report — do not improvise — if any of these proves false during implementation:

1. **The digest algorithm is unchanged by this work.** Grandfathering unprefixed digests as v1 is only
   correct while the current algorithm still produces what v0.14.0 produced. If any change here alters
   a digest input, the two changes must not ship together — stop, and split them.
2. **`null` reaches the absent-digest path at both call sites.** If a three-way answer gets flattened
   back into a boolean anywhere, the change is worse than not doing it: it converts unknown into a
   mismatch, which is the bug being prevented.
3. **No test asserts the exact digest string.** A prefixed digest changes every recorded value. If a
   test pins a literal, it is stating a contract this spec deliberately changes — read it before
   editing it.

---

## Open Questions

None.

---

## Resolved Questions

1. **Prefix inside the string, or a separate receipt field?** **Decision:** Inside the string.
   **Rationale.** Two fields can come apart — a digest with no format, or a format with no digest —
   and every reader would then need a policy for each half. One string cannot. It also means no
   change to the receipt shape, so nothing has to be registered in
   `Receipt::fieldsAreWellFormed()` and no reader that already handles a string needs touching.
2. **What does an unrecognised format do?** **Decision:** Exactly what an absent digest does — the
   bare call ignores it, `--server-verified` refuses it. **Rationale.** It is the same statement:
   this receipt cannot answer the question. `coverage`, `asserted` and an absent `config` all already
   sit there, so this introduces no new policy for a consumer to learn, and the bare gate does not
   break on a receipt that is otherwise sound.
3. **Are unprefixed digests grandfathered or treated as unknown?** **Decision:** Grandfathered as v1
   content. **Rationale.** Only v0.14.0 wrote them and the algorithm has not changed, so they are
   legacy in form and current in content. Treating them as unknown would refuse every existing
   receipt under `--server-verified` on upgrade — a false failure introduced by the change meant to
   prevent false failures. STOP condition 1 is what keeps this true.

---

## Findings

<!-- Notes added during implementation. Do not remove this section. -->
