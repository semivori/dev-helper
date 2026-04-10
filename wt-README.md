# Worktree Tool

Generic PHP CLI for creating and removing Git worktrees on Windows with shared directories via junctions.

## Commands

`php wt.php create <branch> [base-ref]`

- Reuses a local branch if it already exists.
- Creates a tracking branch from `origin/<branch>` when only the remote branch exists.
- Creates a new branch from `base-ref` or from configured `base_ref`.

`php wt.php remove <branch|path>`

- Finds the worktree by branch name or exact path.
- Removes configured junctions before `git worktree remove --force`.
- Runs `git worktree prune` after removal.

`php wt.php list`

- Prints registered worktrees and marks `main` and `prunable` entries.

`php wt.php prune`

- Runs `git worktree prune`.

## Project Config

Put `wt-config.php` into the repository root. The local config is merged over the global config next to `wt.php`.

Example:

```php
<?php

return array(
    'worktree_root' => 'worktrees',
    'base_ref' => 'master',
    'shared_paths' => array(
        'app/vendor',
        'vite-vue/node_modules',
    ),
);
```

## Notes

- `shared_paths` are relative to the repository root.
- Shared directories use Windows junctions, not file copies.
- Remove refuses to touch the main worktree.
