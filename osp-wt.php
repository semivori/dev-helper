#!/usr/bin/env php
<?php

require __DIR__.DIRECTORY_SEPARATOR.'worktree-lib.php';

main($argv);

function main(array $argv)
{
    try {
        $app = new OspWorktreeCli($argv);
        $app->run();
    } catch (Throwable $exception) {
        fwrite(STDERR, 'Error: '.$exception->getMessage().PHP_EOL);
        exit(1);
    }
}

class OspWorktreeCli
{
    /** @var array */
    private $argv;

    /** @var string */
    private $scriptPath;

    /** @var string */
    private $repoRoot;

    /** @var array */
    private $config;

    /** @var WorktreeManager */
    private $manager;

    public function __construct(array $argv)
    {
        $workingDirectory = getcwd();
        $this->repoRoot = detectRepositoryRoot($workingDirectory);
        $wtConfig = loadMergedPhpConfig(__DIR__, $this->repoRoot, 'wt-config.php');
        $this->config = loadMergedPhpConfig(__DIR__, $this->repoRoot, 'osp-wt-config.php');
        $this->manager = new WorktreeManager($this->repoRoot, $wtConfig);
        $this->argv = $argv;
        $this->scriptPath = isset($argv[0]) ? (string) $argv[0] : __FILE__;
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
                $worktree = $this->manager->createWorktree($branchName, $baseRef);
                $this->upsertWorktreeDomain($branchName, $worktree['path']);
                break;

            case 'remove':
            case 'rm':
                $target = $this->requireArgument(2, 'Branch name or worktree path is required.');
                $worktree = $this->manager->findWorktreeByTarget($target);
                if (!$worktree) {
                    throw new RuntimeException('Worktree not found for target: '.$target);
                }
                if (!$worktree['is_main'] && $worktree['branch']) {
                    $this->removeWorktreeDomain($worktree['branch']);
                }
                $this->manager->removeWorktree($target);
                break;

            case 'list':
            case 'ls':
                $this->printWorktreesWithDomains();
                break;

            case 'sync':
                $this->syncProjectIni();
                break;

            case 'help':
            default:
                $this->printHelp();
                break;
        }
    }

    private function syncProjectIni()
    {
        $this->ensureProjectIniDirectory();
        $projectIniPath = $this->getProjectIniPath();
        $ini = parseIniFileStructure($projectIniPath);

        $primaryDomain = $this->getConfigValue('primary_domain', $this->getMainDomain());
        if ($primaryDomain !== '') {
            $ini['globals']['primary_domain'] = $primaryDomain;
        }

        $mainDomain = $this->getMainDomain();
        if ($mainDomain !== '') {
            $ini['sections'][$mainDomain] = $this->buildMainSection(
                isset($ini['sections'][$mainDomain]) ? $ini['sections'][$mainDomain] : array()
            );
        }

        foreach ($this->manager->listWorktrees() as $worktree) {
            if ($worktree['is_main'] || !$worktree['branch']) {
                continue;
            }

            $ini['sections'][$this->buildDomainName($worktree['branch'])] = $this->buildWorktreeSection(
                $worktree['branch'],
                $worktree['path'],
                $ini
            );
        }

        writeIniFileStructure($projectIniPath, $ini);
        echo 'Synced: '.$projectIniPath.PHP_EOL;
    }

    private function upsertWorktreeDomain($branchName, $worktreePath)
    {
        $this->ensureProjectIniDirectory();
        $projectIniPath = $this->getProjectIniPath();
        $ini = parseIniFileStructure($projectIniPath);

        $primaryDomain = $this->getConfigValue('primary_domain', $this->getMainDomain());
        if ($primaryDomain !== '') {
            $ini['globals']['primary_domain'] = $primaryDomain;
        }

        $mainDomain = $this->getMainDomain();
        if ($mainDomain !== '') {
            $ini['sections'][$mainDomain] = $this->buildMainSection(
                isset($ini['sections'][$mainDomain]) ? $ini['sections'][$mainDomain] : array()
            );
        }

        $domainName = $this->buildDomainName($branchName);
        $ini['sections'][$domainName] = $this->buildWorktreeSection($branchName, $worktreePath, $ini);

        writeIniFileStructure($projectIniPath, $ini);
        echo 'Updated OSPanel config for '.$domainName.PHP_EOL;
    }

    private function removeWorktreeDomain($branchName)
    {
        $projectIniPath = $this->getProjectIniPath();
        if (!file_exists($projectIniPath)) {
            return;
        }

        $ini = parseIniFileStructure($projectIniPath);
        $domainName = $this->buildDomainName($branchName);

        if (isset($ini['sections'][$domainName])) {
            unset($ini['sections'][$domainName]);
            writeIniFileStructure($projectIniPath, $ini);
            echo 'Removed OSPanel config for '.$domainName.PHP_EOL;
        }
    }

    private function printWorktreesWithDomains()
    {
        $ini = parseIniFileStructure($this->getProjectIniPath());

        foreach ($this->manager->listWorktrees() as $worktree) {
            $branch = $worktree['branch'] ?: '(detached)';
            $suffix = $worktree['is_main'] ? ' [main]' : '';
            if ($worktree['prunable']) {
                $suffix .= ' [prunable]';
            }

            $domain = $worktree['is_main']
                ? $this->getMainDomain()
                : ($worktree['branch'] ? $this->buildDomainName($worktree['branch']) : '');

            $ospMarker = ($domain !== '' && isset($ini['sections'][$domain])) ? ' [osp]' : '';
            echo $branch.' -> '.$worktree['path'].' -> '.$domain.$suffix.$ospMarker.PHP_EOL;
        }
    }

    private function buildMainSection(array $existingSection)
    {
        $section = $existingSection;
        $this->applyDefaultSectionValues($section);
        $section['project_root'] = ospPath($this->getConfigValue('main_project_root', 'src'));
        $section['web_root'] = ospPath($this->getConfigValue('main_web_root', 'src\\public'));

        return $section;
    }

    private function buildWorktreeSection($branchName, $worktreePath, array $ini)
    {
        $domainName = $this->buildDomainName($branchName);
        $section = isset($ini['sections'][$domainName]) ? $ini['sections'][$domainName] : array();
        $this->applyDefaultSectionValues($section);
        $this->inheritFromMainSection($section, $ini);
        $this->unsetConfiguredKeys($section);

        $relativeProjectRoot = $this->relativeToOspBaseDir($worktreePath);
        $relativeWebRoot = normalizeRelativePath(
            joinPath($relativeProjectRoot, $this->getConfigValue('worktree_web_root', 'public'))
        );

        $section['project_root'] = ospPath($relativeProjectRoot);
        $section['web_root'] = ospPath($relativeWebRoot);

        return $section;
    }

    private function applyDefaultSectionValues(array &$section)
    {
        foreach ($this->getConfigValue('section_defaults', array()) as $key => $value) {
            if (!array_key_exists($key, $section)) {
                $section[$key] = $value;
            }
        }
    }

    private function inheritFromMainSection(array &$section, array $ini)
    {
        $mainDomain = $this->getMainDomain();
        if ($mainDomain === '' || !isset($ini['sections'][$mainDomain])) {
            return;
        }

        foreach ($ini['sections'][$mainDomain] as $key => $value) {
            if (!array_key_exists($key, $section)) {
                $section[$key] = $value;
            }
        }
    }

    private function unsetConfiguredKeys(array &$section)
    {
        foreach ($this->getConfigValue('unset_keys', array('aliases', 'server_aliases')) as $key) {
            if (isset($section[$key])) {
                unset($section[$key]);
            }
        }
    }

    private function relativeToOspBaseDir($absolutePath)
    {
        $baseDir = $this->getOspBaseDir();
        return relativePath($baseDir, $absolutePath);
    }

    private function getOspBaseDir()
    {
        $configured = $this->getConfigValue('osp_base_dir', '.');

        if (isAbsolutePath($configured)) {
            return normalizePath($configured);
        }

        return joinPath($this->repoRoot, $configured);
    }

    private function getProjectIniPath()
    {
        $configured = $this->getConfigValue('project_ini', '.osp\\project.ini');

        if (isAbsolutePath($configured)) {
            return normalizePath($configured);
        }

        return joinPath($this->repoRoot, $configured);
    }

    private function ensureProjectIniDirectory()
    {
        $directory = dirname($this->getProjectIniPath());

        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory: '.$directory);
        }
    }

    private function buildDomainName($branchName)
    {
        $pattern = $this->getConfigValue('worktree_domain_pattern', '{branch_domain}.{main_domain}');

        return strtr($pattern, array(
            '{branch}' => $branchName,
            '{branch_slug}' => sanitizeBranchName($branchName),
            '{branch_domain}' => sanitizeDomainLabel($branchName),
            '{main_domain}' => $this->getMainDomain(),
        ));
    }

    private function getMainDomain()
    {
        return (string) $this->getConfigValue('main_domain', '');
    }

    private function getConfigValue($key, $default = null)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }

    private function printHelp()
    {
        $scriptName = basename($this->scriptPath);

        echo 'Usage:'.PHP_EOL;
        echo '  php '.$scriptName.' create <branch> [base-ref]'.PHP_EOL;
        echo '  php '.$scriptName.' remove <branch|path>'.PHP_EOL;
        echo '  php '.$scriptName.' list'.PHP_EOL;
        echo '  php '.$scriptName.' sync'.PHP_EOL;
        echo PHP_EOL;
        echo 'Project config:'.PHP_EOL;
        echo '  Put osp-wt-config.php into the repository root.'.PHP_EOL;
    }

    private function requireArgument($index, $message)
    {
        if (!isset($this->argv[$index]) || trim((string) $this->argv[$index]) === '') {
            throw new InvalidArgumentException($message);
        }

        return (string) $this->argv[$index];
    }
}
