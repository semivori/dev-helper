# OSP Worktree Tool

OSPanel-aware wrapper over `wt.php`. It creates or removes a Git worktree and keeps `.osp/project.ini` in sync.

## Commands

`php osp-wt.php create <branch> [base-ref]`

- Creates the Git worktree through the shared worktree library.
- Upserts the matching domain section in `.osp/project.ini`.

`php osp-wt.php remove <branch|path>`

- Removes the matching OSPanel domain section.
- Removes the Git worktree.

`php osp-wt.php list`

- Prints worktrees together with the derived OSPanel domain and marks registered sections with `[osp]`.

`php osp-wt.php sync`

- Ensures `primary_domain`.
- Upserts the main domain section.
- Rebuilds OSPanel sections for all current Git worktrees.

## Project Config

Put `osp-wt-config.php` into the repository root. The local config is merged over the global config next to `osp-wt.php`.

Example for a layout like `erp/.osp`, `erp/src`, `erp/worktrees`:

```php
<?php

return array(
    'project_ini' => '.osp/project.ini',
    'osp_base_dir' => '.',
    'primary_domain' => 'erp.loc',
    'main_domain' => 'erp.loc',
    'main_project_root' => 'src',
    'main_web_root' => 'src/public',
    'worktree_domain_pattern' => '{branch_domain}.{main_domain}',
    'worktree_web_root' => 'public',
    'section_defaults' => array(
        'php_engine' => 'PHP-7.3',
        'nginx_engine' => 'Nginx-1.28',
        'node_engine' => '',
        'ip' => '127.0.0.1',
    ),
);
```

## Notes

- `osp-wt` reads `wt-config.php` too, because the real worktree path still comes from the base tool.
- Domain placeholders available in `worktree_domain_pattern`: `{branch}`, `{branch_slug}`, `{branch_domain}`, `{main_domain}`.
- `worktree_web_root` is relative to each created worktree root.
