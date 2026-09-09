#!/usr/bin/env bash
set -euo pipefail
if [[ $# -ne 1 || "$1" == "--help" ]]; then
    echo 'Usage: auto-pull.sh /absolute/path/to/installation'
    echo 'Fetch origin/main and fast-forward a clean main branch. Requires git and flock.'
    exit 0
fi
cd -- "$1"
git_dir=$(git rev-parse --absolute-git-dir)
exec 9>"$git_dir/auto-pull.lock"
flock -n 9 || exit 0
[[ $(git branch --show-current) == main ]] || { echo 'Refusing to update a branch other than main.' >&2; exit 1; }
[[ -z $(git status --porcelain --untracked-files=no) ]] || { echo 'Tracked files have local changes; update aborted.' >&2; exit 1; }
git -c core.hooksPath=/dev/null fetch origin main
if [[ $(git rev-parse HEAD) == $(git rev-parse origin/main) ]]; then
    exit 0
fi
git -c core.hooksPath=/dev/null merge --ff-only origin/main
printf '[%s] Updated to %s\n' "$(date -Iseconds)" "$(git rev-parse HEAD)"
