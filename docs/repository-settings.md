# Repository settings checklist (owner, GitHub UI)

These cannot be kept in the repository; switch them on once and re-check after changes of ownership.

1. **Branch protection / ruleset on `main`**: require a pull request, require the `CI` status checks (every `test` leg and `coverage`), block force-push and deletion, require branches to be up to date.
2. **Tag ruleset for `v*`**: restrict creation to maintainers, block update and deletion. The tag is the Packagist version, and `release.yml` only checks that the tag is on `main`.
3. **Private vulnerability reporting**: Settings > Code security > enable (`SECURITY.md` points there).
4. **Secret scanning and push protection**: enable both. `.github/secret_scanning.yml` excludes the synthetic test tokens.
5. **Dependabot alerts and security updates**: enable. `.github/dependabot.yml` covers version updates for Actions and Composer.
6. **Actions**: Settings > Actions > General: default `GITHUB_TOKEN` permission "Read repository contents"; allow only GitHub-owned actions plus the pinned `shivammathur/setup-php`.
7. **Author e-mail privacy**: "Keep my email addresses private" and "Block command line pushes that expose my email".
8. **Packagist**: two-factor authentication on the maintainer account; prefer the Packagist GitHub App over a webhook secret.
9. Optional: require signed commits on `main` and sign release tags (`git tag -s`).
