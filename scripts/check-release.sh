#!/usr/bin/env bash
# Release guards for .github/workflows/release.yml, run before the GitHub Release is created.
# Usage: scripts/check-release.sh <pinned|annotated|tip|version|changelog> <tag>
# RELEASE_SHA (the workflow passes github.sha) is the commit the run was started for and the archive is built from.
# A failed guard prints one line on stderr and exits 1. MAIN_REF overrides origin/main (tests).
set -euo pipefail

guard=${1:?usage: check-release.sh <pinned|annotated|tip|version|changelog> <tag>}
tag=${2:?usage: check-release.sh <pinned|annotated|tip|version|changelog> <tag>}
version=${tag#v}
main=${MAIN_REF:-origin/main}

fail() {
	echo "release guard $guard: $1" >&2
	exit 1
}

case "$guard" in
pinned)
	# The guards read the tag as it is now; the archive is built from RELEASE_SHA. They must be the same commit,
	# or a tag moved after the push would be checked while the old commit is released.
	: "${RELEASE_SHA:?RELEASE_SHA is not set}"
	commit=$(git rev-parse "refs/tags/$tag^{commit}")
	[ "$commit" = "$RELEASE_SHA" ] || fail "$tag now points at $commit, but this run was started for $RELEASE_SHA"
	;;
annotated)
	[ "$(git cat-file -t "refs/tags/$tag")" = tag ] || fail "$tag is a lightweight tag; create it with git tag -a"
	;;
tip)
	commit=$(git rev-parse "refs/tags/$tag^{commit}")
	tip=$(git rev-parse "$main")
	[ "$commit" = "$tip" ] || fail "$tag ($commit) is not the tip of $main ($tip)"
	;;
version)
	commit=$(git rev-parse "refs/tags/$tag^{commit}")
	got=$(git show "$commit:VERSION" 2>/dev/null | tr -d '[:space:]') || fail "$tag has no VERSION file"
	[ "$got" = "$version" ] || fail "VERSION is $got, but $tag says $version"
	;;
changelog)
	commit=$(git rev-parse "refs/tags/$tag^{commit}")
	changelog=$(git show "$commit:CHANGELOG.md" 2>/dev/null) || fail "$tag has no CHANGELOG.md"
	heading="^## $(printf '%s' "$version" | sed 's/\./\\./g')( |\$)"
	grep -Eq "$heading" <<<"$changelog" || fail "CHANGELOG.md has no '## $version' heading"
	;;
*)
	echo "release guard: unknown guard '$guard'" >&2
	exit 2
	;;
esac
