#!/usr/bin/env bash
# Copies the versioned hooks into .git/hooks (no git config change).
set -eu
cd "$(git rev-parse --show-toplevel)"
install -m 0755 scripts/git-hooks/pre-commit .git/hooks/pre-commit
echo "installed .git/hooks/pre-commit"
