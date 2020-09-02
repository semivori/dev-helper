<?php

$example = [
    'php' => 'php',
    'yii_folder' => './app',
    'model' => [
        'ns' => './models',
    ],
    'git' => [
        'branch_regex' => '/^(f|b)-(\d+)-(.+)/',
        'commit_regex' => '/^(\d+)( |-|_)(.+)/',
        'default_commit' => function ($branchName) {
            return preg_replace('/^.-/', '', $branchName);
        }
    ]
];

return [
    'php' => 'php',
    'yii_folder' => './app',
    'model' => [
        'ns' => './app/models',
    ],
    'git' => [
        'branch_regex' => '/^(f|b)-(\d+)-(.+)/',
        'commit_regex' => '/^(\d+)( |-|_)(.+)/',
        'default_commit' => function ($branchName) {
            return preg_replace('/^.-/', '', $branchName);
        }
    ]
];