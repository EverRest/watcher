# Publishing to Packagist

Package: `everrest/watcher`, repository `https://github.com/EverRest/watcher` (public). Status: pre-1.0.

## Before the first tag (all must be true)

1. `make ci; echo $?` prints `0` on `main`, and the CI run on GitHub is green (all PHP versions).
2. `composer validate --strict` and `composer audit --locked` are clean.
3. T114 (specs/001-scheduled-log-watch/tasks.md) is done: a live smoke run against one real environment (quickstart section 3, `docs/smoke-test.md`) and its result is recorded in `CHANGELOG.md`.
4. The coverage number is read from the first CI coverage artifact (`build/coverage/summary.txt`), then `make badges` is run so `docs/badges/coverage.svg` shows that number, not a placeholder.
5. `CHANGELOG.md`: rename `## 0.1.0 — unreleased draft` to `## 0.1.0 — YYYY-MM-DD — <title>` (keep a fresh `## Unreleased` above). The version badge shows `unreleased` while the heading says "unreleased" and no tag exists.
6. `grep -rniE '<owner-internal-names>' --exclude-dir=.git --exclude-dir=vendor .` returns nothing (the identifier list is kept outside the repository).

## Submit to Packagist (once)

1. Sign in at https://packagist.org with the GitHub account that owns `EverRest/watcher`.
2. Submit: https://packagist.org/packages/submit with `https://github.com/EverRest/watcher`.
3. Enable the GitHub webhook so new tags appear without manual updates: either install the Packagist GitHub App / "Sync with GitHub" from the package page, or add the webhook `https://packagist.org/api/github?username=<packagist-user>` (content type `application/json`, secret = the Packagist API token, event: push) under the repository's Settings > Webhooks. Prefer the GitHub App; it needs no secret in the repository.

## Release

1. Merge to `main` with the changelog promoted (step 5 above).
2. Tag and push: `git tag v0.1.0 && git push origin v0.1.0`.
3. `.github/workflows/release.yml` runs the full CI gate (`ci.yml`) for the tag; only when it passes does it create the GitHub Release (`gh release create`, generated notes). A failed gate creates no release.
4. The webhook makes Packagist pick up `v0.1.0` within a minute; check https://packagist.org/packages/everrest/watcher. `composer.json` carries no `version` field on purpose: the tag is the version, and `main` resolves to `0.1.x-dev` (branch alias).
5. Verify in a scratch directory: `composer require everrest/watcher` (not in this repository).

## History

Commits before the anonymisation commit still contain the original service and company names, ticket keys and
a committer e-mail address. Rewriting the history (for example with `git filter-repo`, then force-push) is the
owner's decision and is not done here; if the names matter, rewrite before the first tag, because Packagist and forks
keep what was public. The unredacted originals of the changed documents are kept outside the repository by the owner.
Tokens that the audit mentions as committed in other (private) repositories are not in this one; they should be
rotated regardless.
