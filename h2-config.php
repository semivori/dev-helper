<?php

$example = [
    'aliases' => [
        'gp' => 'git/push'
    ],
    'git' => [
        'branch_regex' => '/^(f|b)-(\d+)-(.+)/',
        'commit_regex' => '/^(\d+)( |-|_)(.+)/',
        'default_commit' => function ($branchName) {
            return preg_replace('/^.-/', '', $branchName);
        }
    ],
    'model' => [
        'ns' => '/app/models',
    ],
    'php' => 'php',
    'yii_folder' => '/app',
];

return [
    'aliases' => [
        'gb' => 'git/createBranch',
        'gc' => 'git/checkoutPattern',
        'gh' => 'git/help',
        'go' => 'git/open',
        'gp' => 'git/pushAll',
        'gpr' => 'git/pullRequest',
        'gl' => 'git/listBranches',
        '+' => 'git/plus',
        'gk' => 'git/commit',
        'gcm' => 'git/commit',
        null,
        'gim' => 'gii/model',
        null,
        'm' => 'migrate/apply',
        'mc' => 'migrate/create',
        'ml' => 'migrate/openLast',
        null,
        'rp' => 'helper/resetPasswords'
    ],
    'git' => [
        'branch_regex' => '/^(f|b)-(\d+)-(.+)/',
        'commit_regex' => '/^(\d+)( |-|_)(.+)/',
        'default_commit' => function ($branchName) {
            return preg_replace('/^.-/', '', $branchName);
        }
    ],
    'model' => [
        'ns' => '/app/models',
    ],
    'php' => 'php',
    'yii_folder' => '/app',
];