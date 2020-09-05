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
        'ns' => './models',
    ],
    'php' => 'php',
    'yii_folder' => './app',
];

return [
    'aliases' => [
        'gb' => 'git/createBranch',
        'gc' => 'git/checkoutPattern',
        'gh' => 'git/help',
        'go' => 'git/open',
        'gp' => 'git/pushAll',

        'gim' => 'gii/model',

        'm' => 'migrate/exec',
        'mc' => 'migrate/create',
    ],
];