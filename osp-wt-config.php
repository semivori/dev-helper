<?php

$example = array(
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
    'unset_keys' => array(
        'aliases',
        'server_aliases',
    ),
);

return array(
    'project_ini' => '.osp/project.ini',
    'osp_base_dir' => '.',
    'primary_domain' => '',
    'main_domain' => '',
    'main_project_root' => 'src',
    'main_web_root' => 'src/public',
    'worktree_domain_pattern' => '{branch_domain}.{main_domain}',
    'worktree_web_root' => 'public',
    'section_defaults' => array(),
    'unset_keys' => array(
        'aliases',
        'server_aliases',
    ),
);
