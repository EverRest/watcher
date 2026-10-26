# Publishing to Packagist

Package: `everrest/watcher`, repository `https://github.com/EverRest/watcher` (public). Status: pre-1.0.

## Before the first tag (all must be true)

1. `make ci; echo $?` prints `0` on `main`, and the CI run on GitHub is green (all PHP versions).
2. `composer validate --strict` and `composer audit --locked` are clean.
3. T114 (specs/001-scheduled-log-watch/tasks.md) is done: a live smoke run against one real environment (quickstart section 3, `docs/smoke-test.md`) and its result is recorded in `CHANGELOG.md`.
4. The CI `coverage` job is green (it enforces the floor in `scripts/coverage-floor.php`); the number is in its job summary. There is no committed coverage badge.
5. `CHANGELOG.md`: rename `## 0.1.0 — unreleased draft` to `## 0.1.0 — YYYY-MM-DD — <title>` (keep a fresh `## Unreleased` above).
6. `grep -rniE '<owner-internal-names>' --exclude-dir=.git --exclude-dir=vendor .` returns nothing (the identifier list is kept outside the repository).

## Submit to Packagist (once)

1. Sign in at https://packagist.org with the GitHub account that owns `EverRest/watcher`.
2. Submit: https://packagist.org/packages/submit with `https://github.com/EverRest/watcher`.
3. Enable the GitHub webhook so new tags appear without manual updates: either install the Packagist GitHub App / "Sync with GitHub" from the package page, or add the webhook `https://packagist.org/api/github?username=<packagist-user>` (content type `application/json`, secret = the Packagist API token, event: push) under the repository's Settings > Webhooks. Prefer the GitHub App; it needs no secret in the repository.

## Release

Run these in order. Each tag is cut from the tip of `main`, never from a branch or an older commit.

1. On `develop`: promote the changelog. Rename `## Unreleased` to `## X.Y.Z — YYYY-MM-DD — <title>` and add the line `Product release label **vX.Y.Z** (root VERSION)`.
2. On `develop`: set `VERSION` to `X.Y.Z` (the same version as the heading). Commit both files together.
3. Merge `develop` into `main` and wait for the CI run on `main` to be green (all PHP versions).
4. Fetch and check that `main` is where you expect: `git fetch origin && git rev-parse origin/main`.
5. Tag the tip of `main` with an annotated tag: `git tag -a vX.Y.Z origin/main -m "release X.Y.Z"`. A lightweight tag is refused by the release workflow.
6. Push the tag: `git push origin vX.Y.Z`.
7. `.github/workflows/release.yml` runs the full CI gate (`ci.yml`) for the tag. Then four guards run, each failing with one line, before anything is released (`scripts/check-release.sh`, tested by `make release-guards-test`):
   - `annotated`: the tag is an annotated tag object.
   - `tip`: the tagged commit is the tip of `origin/main`, not an older commit that main has since passed.
   - `version`: `VERSION` in the tagged commit equals the tag without the `v`.
   - `changelog`: `CHANGELOG.md` in the tagged commit has a `## X.Y.Z` heading for that version.

   Only when all of them pass does the workflow create the GitHub Release (`gh release create --verify-tag`, generated notes, a build-provenance attestation for the `git archive` tarball; tags containing `-`, such as `v0.2.0-rc1`, become pre-releases). A failed gate or guard creates no release. Packagist builds from the tag itself, so also protect `v*` tags (see `repository-settings.md`): the workflow cannot stop someone with write access from pushing a tag, it only refuses to release it.

8. The webhook makes Packagist pick up `v0.1.0` within a minute; check https://packagist.org/packages/everrest/watcher. `composer.json` carries no `version` field on purpose: the tag is the version, and `main` resolves to `0.1.x-dev` (branch alias).
9. Verify in a scratch directory: `composer require everrest/watcher` (not in this repository).

### After a wrong tag

A tag that is already pushed is published: Packagist and forks may have read it. Never move, re-point or delete it, and never force-push it. Fix forward instead:

1. Say in `CHANGELOG.md` which tag was wrong and what it points to (for example `v0.2.0` on `36673a5`, which predates the kill switch and the API context), and tell users not to install it.
2. Put the fix on `develop`, promote the changelog and bump `VERSION` to the next patch (`X.Y.(Z+1)`), then run the release steps above for that version.

## History

Commits before the anonymisation commit still contain the original service and company names, ticket keys and
a committer e-mail address. Rewriting the history (for example with `git filter-repo`, then force-push) is the
owner's decision and is not done here; if the names matter, rewrite before the first tag, because Packagist and forks
keep what was public. The unredacted originals of the changed documents are kept outside the repository by the owner.
Tokens that the audit mentions as committed in other (private) repositories are not in this one; they should be
rotated regardless.

## Commit identity

Commits must use the public identity `EverRest <EverRest@users.noreply.github.com>`
(`git config user.name EverRest; git config user.email EverRest@users.noreply.github.com` in this clone, and
enable "Keep my email address private" and "Block command line pushes that expose my email" on GitHub). `scripts/git-hooks/pre-commit`
refuses a commit when the e-mail is empty or ends with a domain listed in `.git/hooks/blocked-author-domains`
(one domain per line, optional, never committed; add the domains of any employer or private mail you must not expose).
