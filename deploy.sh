#!/bin/bash
# Deploy the current branch: push it to GitHub, then have the server pull it.
#
#   ./deploy.sh            deploy the checked-out branch
#   ./deploy.sh --dry-run  say what would happen, change nothing
#
# The server's working copy is a real clone. It checks out the same branch
# name and fast-forwards to origin, so a server that has drifted (edited
# tracked files, or a branch that can't fast-forward) stops the deploy
# instead of being overwritten.
set -euo pipefail

HOST="${DEPLOY_HOST:-resume}"                # ssh Host from ~/.ssh/config
DEST="${DEPLOY_PATH:-resume.robnugen.com}"   # project root, relative to ~

cd "$(dirname "$0")"
DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

BRANCH=$(git rev-parse --abbrev-ref HEAD)
if [ "$BRANCH" = "HEAD" ]; then
    echo "Detached HEAD: check out a branch to deploy." >&2
    exit 1
fi
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "Uncommitted changes to tracked files. Commit them first:" >&2
    git status --short --untracked-files=no >&2
    exit 1
fi
HEAD_SHA=$(git rev-parse --short HEAD)

if [ "$DRY_RUN" = 1 ]; then
    echo "Would push $BRANCH ($HEAD_SHA) to origin and pull it on $HOST:~/$DEST."
    ssh "$HOST" "cd $DEST && echo \"Server is on \$(git rev-parse --abbrev-ref HEAD) \$(git rev-parse --short HEAD)\" && git status --short --untracked-files=no"
    exit 0
fi

echo "Pushing $BRANCH ($HEAD_SHA) to origin..."
git push -u origin "$BRANCH"

echo "Pulling $BRANCH on $HOST..."
# shellcheck disable=SC2029  # $BRANCH and $DEST are meant to expand locally
ssh "$HOST" "set -e
cd $DEST
if [ -n \"\$(git status --porcelain --untracked-files=no)\" ]; then
    echo 'Server has uncommitted changes to tracked files. Not deploying:' >&2
    git status --short --untracked-files=no >&2
    exit 1
fi
git fetch origin $BRANCH
git checkout $BRANCH 2>/dev/null || git checkout -b $BRANCH --track origin/$BRANCH
git merge --ff-only origin/$BRANCH
echo \"Server is on \$(git rev-parse --abbrev-ref HEAD) \$(git rev-parse --short HEAD)\""

echo "Deployed $BRANCH ($HEAD_SHA)."
