# Contract: known-issue lines and labels

## Compact digest line (section kind `minor`, after the findings)

`📌 known (3): ABC-123 ×45 error · DEF-9 ×12 warn · #4521 ×3 error`

- Entries ordered by count desc, then fingerprint; at most 10, then ` · N more`.
- Only the configured ticket key, the level name and the group count appear. No fingerprint, no template, no model text.
- Two keys for one fingerprint: `ABC-123/ABC-130`.

## Finding labels (prefix of the finding title line, code-built)

| Situation | Label |
|---|---|
| `known_grown` | `known, grown · ABC-123` |
| entry `resolved` | `regression of ABC-123 (marked resolved)` |
| entry expired (`until`) | `link ABC-123 expired` |
| finding with several fingerprints, all known | the key list; otherwise only the keys the finding has |

Labels are produced by code from configuration and are escaped like any package text; the model never writes them.

## JSON summary (additive keys, only when the feature is enabled and has entries)

`known`: int (groups shown as known), `known_grown`: int.

## Informational `check` line

`known_issues: 3 entries (1 resolved, 0 expired)`; entries not seen for `state.retention`: `known_issues: 1 entry not seen for 30 days`.
