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
- There is no committed version badge: it went stale (it showed 0.1.2 after 0.3.1, because the tags sit on `main` merge commits that `develop` does not contain, so `git describe` never saw them). The version shown in the README is the live **Last successful release** badge below.
- **coverage**: from `build/coverage/cobertura.xml` (or `clover.xml`) of the last `make coverage`. Without a report the existing badge is kept, so a commit never runs the suite.
- The pre-commit hook runs the script and `git add`s README / SVGs only if they changed (and only if you had no unstaged edits in them).

### "Last successful version"

Two live badges, no secrets and no committed state:

- **CI**: the GitHub Actions status badge of `ci.yml`.
- **Last successful release**: shields.io `github/v/release`, i.e. the latest GitHub Release. `.github/workflows/release.yml` runs on a `v*` tag, calls the whole `ci.yml` gate (PHP 8.2 to 8.5, audit, validate, style, stan, coverage) and only then creates the release with the built-in `GITHUB_TOKEN` (`contents: write` on that one job). A tag whose gate fails produces no release, so the badge keeps the previous good version.

Both shields.io and the Actions badge render only for a public repository (or a signed-in viewer for the Actions badge). Nothing to configure in Settings.

## CI

`ci.yml` runs on branches and pull requests: matrix PHP 8.2 to 8.5 on `composer.lock` with parallel tests, plus two extra legs that ignore the lock because consumers do (PHP 8.2 with `composer update --prefer-lowest --prefer-stable`, PHP 8.5 with the highest versions `composer.json` admits, which is where Laravel 11 is exercised; both run `composer audit --no-dev` too); on 8.3 also `composer validate --strict`, `composer audit --locked`, phpstan and pint. A `coverage` job (PHP 8.3, pcov) uploads the Cobertura / Clover / HTML report as the `coverage` artifact. The job then runs `php scripts/coverage-floor.php 92` and fails when line coverage in `cobertura.xml` drops below **92%** (measured 96.5% on 2026-10-24; raise the number in `ci.yml` as coverage grows). `phpunit.xml.dist` sets `cacheDirectory` (paratest's coverage cache warm-up exits 1 without it) and `memory_limit=1G` (merging worker coverage exhausts the 128M default).

**Redis in CI.** `WATCHER_TEST_REDIS` points the contract tests (TTL, locks, Lua scripts) at a real Redis (`redis:7.4` service in `ci.yml`); locally they are skipped when it is unset (`docker run --rm -d -p 6391:6379 redis:7.4`, `WATCHER_TEST_REDIS=127.0.0.1:6391`). **Predis is only exercised through test doubles** (`FakePredis` over `FakeRedisServer`): `predis/predis` is a `suggest`, not in `require-dev`, so no job runs the adapter against real Predis. Adding it (both `^2.0` and `^3.0` legs, running the contract test against the real server) is the open follow-up.

## Portability notes for tests and checks

Found on CI in 0.2.1; each one passed on a developer Mac and failed on the Ubuntu runner.

- **Local PHP is not CI PHP.** CI installs the newest patch release of each PHP version. A local PHP that is months old, or one with bundled time zone data, can hide failures. Keep local PHP current and test 8.2 and the newest version.
- **Time zones.** Ubuntu PHP reads the system time zone files. `DateTimeZone::getTransitions()` then lists only transitions stored in the file, not those from the rule at its end, so it is wrong for the current years. Find a transition by comparing offsets, not by listing.
- **Stat cache.** PHP caches `stat`, `lstat` and `realpath`. A check that decides on a file mode, owner or inode must call `clearstatcache(true)` first.
- **Inode numbers are reused.** After `unlink()` the next new file can get the same inode. A test that swaps a file must keep the original alive (rename it) or it cannot see the swap.
- **Token-shaped test values** are built from parts, or the GitHub secret scan blocks the push.
- **Dependency legs.** CI runs the newest dependencies on PHP 8.5 (Guzzle 8, Pint 1.30, PHPUnit 11). Run PHPStan, Pint and the tests there before a release.

## Supported versions

- PHP: 8.2 to 8.5 are tested. 8.1 was dropped in 0.1.2 (past security support since December 2025); 8.2 ends December 2026.
- Laravel: `illuminate/*` ^10 or ^11. 10 gets no upstream security fixes. Lumen is unmaintained upstream and has no dedicated test.
- Composer floors in `composer.json` must be versions CI really passes on: the lowest leg resolves them. Raise a floor when the lowest leg fails or when `composer audit --no-dev` reports an advisory at it (this is why Guzzle is `^7.15.2`).
- Commits must use the public identity (see `publishing.md`); `make hooks` installs a pre-commit check for it.
