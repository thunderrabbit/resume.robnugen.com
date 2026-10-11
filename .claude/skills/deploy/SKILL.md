---
name: deploy
description: Deploy the current branch to resume.robnugen.com via git push + ssh pull
disable-model-invocation: false
allowed-tools: Bash
---

Deploy the checked-out branch to the DreamHost server.

1. Run `./deploy.sh --dry-run` and read it: which branch and commit will go,
   and what the server is on now.
2. Run `./deploy.sh`. It pushes the branch to GitHub, then on the server
   checks out the same branch and fast-forwards it to `origin/<branch>`.
3. Report the server's branch and commit from the last line of output.

If it stops, report why and do not force it:

- **Uncommitted changes here:** commit first (or ask Rob).
- **Server has uncommitted changes to tracked files:** someone edited on the
  server (the sync-watch script does this). Show Rob `git status` and `git diff`
  from the server; never `git restore` or `reset` them away without his say.
- **Not a fast-forward:** the server's branch has commits origin lacks. Ask Rob.

Deploying never touches `master`; advancing master is Rob's job. New files in
`db_schemas/02_*` onward still need an admin to apply them at
`/admin/migrate_tables.php` after the deploy.
