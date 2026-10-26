#!/usr/bin/env bash
# Runs scripts/check-release.sh against a scratch repository: one good tag, then one bad tag per guard.
set -euo pipefail

root=$(cd "$(dirname "$0")/../.." && pwd)
script="$root/scripts/check-release.sh"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
failures=0

git init -q --bare -b main "$work/origin.git"
git init -q -b main "$work/repo"
cd "$work/repo"
git config user.name test
git config user.email test@example.invalid
git config commit.gpgsign false
git config tag.gpgsign false
git remote add origin "$work/origin.git"

# release <version> <changelog heading version>: commit a VERSION and CHANGELOG.md pair.
release() {
	printf '%s\n' "$1" > VERSION
	printf '# Changelog\n\n## %s — 2026-10-26 — test\n' "$2" > CHANGELOG.md
	git add VERSION CHANGELOG.md
	git commit -q -m "release $1"
}

# expect <label> <0|1> <guard> <tag>: exit status must match, a failure prints exactly one line.
expect() {
	local out got=0
	out=$(cd "$work/repo" && "$script" "$3" "$4" 2>&1) || got=$?
	if [ "$got" -ne "$2" ]; then
		echo "FAIL $1: exit $got, want $2: $out"
		failures=$((failures + 1))
	elif [ "$2" -ne 0 ] && [ "$(printf '%s\n' "$out" | wc -l | tr -d ' ')" -ne 1 ]; then
		echo "FAIL $1: the message is not one line: $out"
		failures=$((failures + 1))
	else
		echo "ok   $1"
	fi
}

release 0.2.1 0.2.1                              # c1
git tag -a v0.2.1 -m "release 0.2.1"             # v0.2.1 is on c1, which main will leave behind
git push -q origin main
release 0.2.2 0.2.2                              # c2
git tag -a v0.2.2 -m "release 0.2.2"
git push -q origin main

expect "good tag on the tip" 0 tip v0.2.2
expect "good tag: version matches" 0 version v0.2.2
expect "good tag: changelog heading" 0 changelog v0.2.2
expect "good tag: annotated" 0 annotated v0.2.2

expect "stale tag is refused" 1 tip v0.2.1
expect "stale tag: its own version and changelog still match" 0 version v0.2.1

git tag -a v0.2.3 -m "release 0.2.3"             # tagged on c2 while VERSION says 0.2.2
expect "VERSION mismatch is refused" 1 version v0.2.3

release 0.2.4 0.2.10                             # c3: heading 0.2.10 must not satisfy 0.2.4
git tag -a v0.2.4 -m "release 0.2.4"
git push -q origin main
expect "missing changelog heading is refused" 1 changelog v0.2.4

release 0.2.5 0.2.5                              # c4
git tag v0.2.5                                   # lightweight, no tag object
git push -q origin main
expect "lightweight tag is refused" 1 annotated v0.2.5
expect "unknown guard is a usage error" 2 nope v0.2.5

if [ "$failures" -ne 0 ]; then
	echo "$failures check(s) failed"
	exit 1
fi
echo "all release guard checks passed"
