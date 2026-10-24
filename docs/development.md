# Development

| Command | What it does |
|---|---|
| `make test` | `composer test`: the suite in parallel with ParaTest (one process per CPU) |
| `make test-serial` | `composer test:serial`: plain PHPUnit, for debugging a single failing test |
| `make coverage` | `composer coverage`: needs **pcov** (preferred) or Xdebug; writes `build/coverage/cobertura.xml`, `clover.xml`, `summary.txt` and `html/` (gitignored) |
| `make badges` | `php scripts/badges.php`: rewrites the README badge block and `docs/badges/*.svg` (no network) |
| `make hooks` | installs `scripts/git-hooks/pre-commit` into `.git/hooks` |
| `make ci` | style, phpstan, tests |

Tests must stay parallel-safe: any directory a test writes to is unique per test (`sys_get_temp_dir() . '/x-' . bin2hex(random_bytes(4))`), and `putenv` / `ini_set` are restored in `finally` (each ParaTest worker is its own process).

## Badges

The block between `<!-- badges:start -->` and `<!-- badges:end -->` in `README.md` is generated; do not edit it by hand.

- **php**: `require.php` of `composer.json`. **license**: `license` of `composer.json`.
- **version**: latest git tag, else the first `## X.Y.Z` heading of `CHANGELOG.md`, else `unreleased`.
- **coverage**: from `build/coverage/cobertura.xml` (or `clover.xml`) of the last `make coverage`. Without a report the existing badge is kept, so a commit never runs the suite.
- The pre-commit hook runs the script and `git add`s README / SVGs only if they changed (and only if you had no unstaged edits in them).

### "Last successful version"

Two live badges, no secrets and no committed state:

- **CI**: the GitHub Actions status badge of `ci.yml`.
- **Last successful release**: shields.io `github/v/release`, i.e. the latest GitHub Release. `.github/workflows/release.yml` runs on a `v*` tag, calls the whole `ci.yml` gate (PHP 8.1 to 8.5, audit, validate, style, stan, coverage) and only then creates the release with the built-in `GITHUB_TOKEN` (`contents: write` on that one job). A tag whose gate fails produces no release, so the badge keeps the previous good version.

Both shields.io and the Actions badge render only for a public repository (or a signed-in viewer for the Actions badge). Nothing to configure in Settings.

## CI

`ci.yml` runs on branches and pull requests: matrix PHP 8.1 to 8.5 with parallel tests; on 8.3 also `composer validate --strict`, `composer audit --locked`, phpstan and pint. A `coverage` job (PHP 8.3, pcov) uploads the Cobertura / Clover / HTML report as the `coverage` artifact. The job then runs `php scripts/coverage-floor.php 92` and fails when line coverage in `cobertura.xml` drops below **92%** (measured 96.5% on 2026-10-24; raise the number in `ci.yml` as coverage grows). `phpunit.xml.dist` sets `cacheDirectory` (paratest's coverage cache warm-up exits 1 without it) and `memory_limit=1G` (merging worker coverage exhausts the 128M default).
