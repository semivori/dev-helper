#!/usr/bin/env php
<?php

require __DIR__.DIRECTORY_SEPARATOR.'worktree-lib.php';

main($argv);

function main(array $argv)
{
    try {
        $app = new WorktreeCli($argv);
        $app->run();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Error: '.$exception->getMessage().PHP_EOL);
        exit(1);
    }
}

class WorktreeCli
{
    /** @var array */
    private $argv;

    /** @var string */
    private $scriptPath;

    /** @var WorktreeManager */
    private $manager;

    public function __construct(array $argv)
    {
        $workingDirectory = getcwd();
        $repoRoot = detectRepositoryRoot($workingDirectory);
        $config = loadMergedPhpConfig(__DIR__, $repoRoot, 'wt-config.php');

        $this->argv = $argv;
        $this->scriptPath = isset($argv[0]) ? (string) $argv[0] : __FILE__;
        $this->manager = new WorktreeManager($repoRoot, $config);
    }

    public function run()
    {
        $command = isset($this->argv[1]) ? $this->argv[1] : 'help';

        if (in_array($command, array('--help', '-h'), true)) {
            $command = 'help';
        }

        switch ($command) {
            case 'create':
            case 'add':
                $branchName = $this->requireArgument(2, 'Branch name is required.');
                $baseRef = isset($this->argv[3]) ? $this->argv[3] : null;
                $this->manager->createWorktree($branchName, $baseRef);
                break;

            case 'remove':
            case 'rm':
                $target = $this->requireArgument(2, 'Branch name or worktree path is required.');
                $this->manager->removeWorktree($target);
                break;

            case 'list':
            case 'ls':
                $this->manager->printWorktrees();
                break;

            case 'prune':
                $this->manager->pruneWorktrees();
                break;

            case 'help':
            default:
                $this->printHelp();
                break;
        }
    }

    private function printHelp()
    {
        $scriptName = basename($this->scriptPath);

        echo 'Usage:'.PHP_EOL;
        echo '  php '.$scriptName.' create <branch> [base-ref]'.PHP_EOL;
        echo '  php '.$scriptName.' remove <branch|path>'.PHP_EOL;
        echo '  php '.$scriptName.' list'.PHP_EOL;
        echo '  php '.$scriptName.' prune'.PHP_EOL;
        echo PHP_EOL;
        echo 'Project config:'.PHP_EOL;
        echo '  Put wt-config.php into the repository root.'.PHP_EOL;
    }

    private function requireArgument($index, $message)
    {
        if (!isset($this->argv[$index]) || trim((string) $this->argv[$index]) === '') {
            throw new InvalidArgumentException($message);
        }

        return (string) $this->argv[$index];
    }
}
