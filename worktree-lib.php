<?php

class WorktreeManager
{
    /** @var string */
    private $repoRoot;

    /** @var array */
    private $config;

    public function __construct($repoRoot, array $config)
    {
        $this->repoRoot = normalizePath($repoRoot);
        $this->config = $config;
    }

    public function getRepoRoot()
    {
        return $this->repoRoot;
    }

    public function getConfig()
    {
        return $this->config;
    }

    public function createWorktree($branchName, $baseRef = null)
    {
        $worktreePath = $this->getWorktreePathForBranch($branchName);

        if (file_exists($worktreePath)) {
            throw new RuntimeException('Worktree path already exists: '.$worktreePath);
        }

        $this->ensureDirectory(dirname($worktreePath));
        $baseRef = $baseRef ?: $this->getConfigValue('base_ref', 'master');
        $command = $this->buildCreateCommand($branchName, $worktreePath, $baseRef);

        $this->log('Creating worktree for branch: '.$branchName);
        $this->runGit($command);

        try {
            $sharedPaths = $this->getSharedPaths();
            if ($sharedPaths) {
                $this->log('Sharing directories:');
            }

            foreach ($sharedPaths as $relativePath) {
                $this->shareDirectory($worktreePath, $relativePath);
            }
        } catch (Throwable $exception) {
            $this->log('Create failed after worktree add, removing incomplete worktree.');
            $this->safeRemoveWorktreePath($worktreePath);
            throw $exception;
        }

        $this->log('Created: '.$worktreePath);

        return $this->findWorktreeByTarget($branchName);
    }

    public function removeWorktree($target)
    {
        $worktree = $this->findWorktreeByTarget($target);

        if (!$worktree) {
            throw new RuntimeException('Worktree not found for target: '.$target);
        }

        if ($worktree['is_main']) {
            throw new RuntimeException('Refusing to remove the main worktree.');
        }

        $path = $worktree['path'];

        if (!is_dir($path)) {
            $this->log('Worktree path is missing, pruning stale entry: '.$path);
            $this->pruneWorktrees();
            return $worktree;
        }

        foreach ($this->getSharedPaths() as $relativePath) {
            $this->unlinkSharedDirectory($path, $relativePath);
        }

        $this->log('Removing worktree: '.$path);
        $this->runGit('worktree remove --force '.escapeShellArgument($path));
        $this->pruneWorktrees();
        $this->log('Removed: '.$path);

        return $worktree;
    }

    public function listWorktrees()
    {
        return $this->getWorktrees();
    }

    public function printWorktrees()
    {
        foreach ($this->getWorktrees() as $worktree) {
            $branch = $worktree['branch'] ?: '(detached)';
            $suffix = $worktree['is_main'] ? ' [main]' : '';
            if ($worktree['prunable']) {
                $suffix .= ' [prunable]';
            }

            echo $branch.' -> '.$worktree['path'].$suffix.PHP_EOL;
        }
    }

    public function pruneWorktrees()
    {
        $this->runGit('worktree prune');
        $this->log('Pruned stale worktree metadata.');
    }

    public function getWorktreePathForBranch($branchName)
    {
        $root = $this->resolveConfiguredPath(
            $this->getConfigValue('worktree_root', $this->defaultWorktreeRoot())
        );

        return joinPath($root, sanitizeBranchName($branchName));
    }

    public function findWorktreeByTarget($target)
    {
        return $this->findWorktree($this->getWorktrees(), $target);
    }

    private function shareDirectory($worktreePath, $relativePath)
    {
        $sourcePath = joinPath($this->repoRoot, $relativePath);
        $destinationPath = joinPath($worktreePath, $relativePath);

        if (!is_dir($sourcePath)) {
            $this->log('  - skipped, source directory does not exist: '.$relativePath);
            return;
        }

        $this->deletePath($destinationPath);
        $this->ensureDirectory(dirname($destinationPath));
        $this->createJunction($destinationPath, $sourcePath);
        $this->log('  - '.$relativePath);
    }

    private function unlinkSharedDirectory($worktreePath, $relativePath)
    {
        $destinationPath = joinPath($worktreePath, $relativePath);

        if (!file_exists($destinationPath) && !is_dir($destinationPath)) {
            return;
        }

        if (!$this->isLinkDirectory($destinationPath)) {
            return;
        }

        $this->removeDirectoryLink($destinationPath);
    }

    private function buildCreateCommand($branchName, $worktreePath, $baseRef)
    {
        if ($this->localBranchExists($branchName)) {
            return sprintf(
                'worktree add %s %s',
                escapeShellArgument($worktreePath),
                escapeShellArgument($branchName)
            );
        }

        if ($this->remoteBranchExists($branchName)) {
            return sprintf(
                'worktree add --track -b %s %s %s',
                escapeShellArgument($branchName),
                escapeShellArgument($worktreePath),
                escapeShellArgument('origin/'.$branchName)
            );
        }

        return sprintf(
            'worktree add -b %s %s %s',
            escapeShellArgument($branchName),
            escapeShellArgument($worktreePath),
            escapeShellArgument($baseRef)
        );
    }

    private function findWorktree(array $worktrees, $target)
    {
        $normalizedTarget = normalizePath($target);

        foreach ($worktrees as $worktree) {
            if ($worktree['branch'] === $target) {
                return $worktree;
            }

            if (normalizePath($worktree['path']) === $normalizedTarget) {
                return $worktree;
            }
        }

        return null;
    }

    private function getWorktrees()
    {
        $result = $this->runGitRaw('worktree list --porcelain');
        $stdout = trim($result['stdout']);

        if ($stdout === '') {
            return array();
        }

        $lines = preg_split('/\r\n|\n|\r/', $stdout);
        $blocks = array();
        $current = array();

        foreach ($lines as $line) {
            if ($line === '') {
                if ($current) {
                    $blocks[] = $current;
                    $current = array();
                }
                continue;
            }

            $current[] = $line;
        }

        if ($current) {
            $blocks[] = $current;
        }

        $worktrees = array();

        foreach ($blocks as $block) {
            $item = array(
                'path' => '',
                'branch' => null,
                'head' => null,
                'prunable' => false,
                'is_main' => false,
            );

            foreach ($block as $line) {
                if (strpos($line, 'worktree ') === 0) {
                    $item['path'] = normalizePath(substr($line, 9));
                    $item['is_main'] = normalizePath($item['path']) === normalizePath($this->repoRoot);
                    continue;
                }

                if (strpos($line, 'HEAD ') === 0) {
                    $item['head'] = substr($line, 5);
                    continue;
                }

                if (strpos($line, 'branch ') === 0) {
                    $item['branch'] = preg_replace('#^refs/heads/#', '', substr($line, 7));
                    continue;
                }

                if (strpos($line, 'prunable ') === 0) {
                    $item['prunable'] = true;
                }
            }

            $worktrees[] = $item;
        }

        return $worktrees;
    }

    private function safeRemoveWorktreePath($worktreePath)
    {
        if (!is_dir($worktreePath)) {
            return;
        }

        foreach ($this->getSharedPaths() as $relativePath) {
            $this->unlinkSharedDirectory($worktreePath, $relativePath);
        }

        try {
            $this->runGit('worktree remove --force '.escapeShellArgument($worktreePath));
        } catch (Throwable $exception) {
            $this->deletePath($worktreePath);
            $this->pruneWorktrees();
        }
    }

    private function defaultWorktreeRoot()
    {
        return dirname($this->repoRoot).DIRECTORY_SEPARATOR.basename($this->repoRoot).'-worktrees';
    }

    private function getSharedPaths()
    {
        $paths = $this->getConfigValue('shared_paths', array());
        $normalized = array();

        foreach ($paths as $path) {
            $path = trim((string) $path);
            if ($path === '') {
                continue;
            }

            $normalized[] = normalizeRelativePath($path);
        }

        return array_values(array_unique($normalized));
    }

    private function getConfigValue($key, $default = null)
    {
        return array_key_exists($key, $this->config) ? $this->config[$key] : $default;
    }

    private function resolveConfiguredPath($path)
    {
        if (isAbsolutePath($path)) {
            return normalizePath($path);
        }

        return joinPath($this->repoRoot, $path);
    }

    private function localBranchExists($branchName)
    {
        $result = $this->runGitRaw(
            'show-ref --verify --quiet '.escapeShellArgument('refs/heads/'.$branchName),
            false
        );

        return $result['exit_code'] === 0;
    }

    private function remoteBranchExists($branchName)
    {
        $result = $this->runGitRaw(
            'show-ref --verify --quiet '.escapeShellArgument('refs/remotes/origin/'.$branchName),
            false
        );

        return $result['exit_code'] === 0;
    }

    private function runGit($command, $throwOnError = true)
    {
        $result = $this->runGitRaw($command, $throwOnError);

        if ($result['stdout'] !== '') {
            echo $result['stdout'];
            if (substr($result['stdout'], -1) !== PHP_EOL) {
                echo PHP_EOL;
            }
        }

        return $result;
    }

    private function runGitRaw($command, $throwOnError = true)
    {
        return runCommand('git '.$command, $this->repoRoot, $throwOnError);
    }

    private function ensureDirectory($path)
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory: '.$path);
        }
    }

    private function deletePath($path)
    {
        if (!file_exists($path) && !is_dir($path)) {
            return;
        }

        if (is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Unable to delete file: '.$path);
            }

            return;
        }

        if ($this->isLinkDirectory($path)) {
            $this->removeDirectoryLink($path);
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            throw new RuntimeException('Unable to read directory: '.$path);
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $this->deletePath(joinPath($path, $item));
        }

        if (!rmdir($path)) {
            throw new RuntimeException('Unable to delete directory: '.$path);
        }
    }

    private function createJunction($linkPath, $targetPath)
    {
        $script = sprintf(
            '$link = %s; $target = %s; New-Item -ItemType Junction -Path $link -Target $target | Out-Null',
            toPowerShellLiteral($linkPath),
            toPowerShellLiteral($targetPath)
        );

        $result = runCommand(buildPowerShellCommand($script), $this->repoRoot, false);

        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Unable to create junction: '.$linkPath.PHP_EOL.$result['stderr']);
        }
    }

    private function removeDirectoryLink($path)
    {
        $result = runCommand(
            'cmd /c rmdir '.escapeShellArgument($path),
            $this->repoRoot,
            false
        );

        if ($result['exit_code'] !== 0) {
            $message = $result['stderr'] !== '' ? $result['stderr'] : $result['stdout'];
            throw new RuntimeException('Unable to remove linked directory: '.$path.PHP_EOL.$message);
        }
    }

    private function isLinkDirectory($path)
    {
        if (!is_dir($path)) {
            return false;
        }

        $script = sprintf(
            '$item = Get-Item -LiteralPath %s -Force; if ($null -eq $item.LinkType) { exit 1 } else { exit 0 }',
            toPowerShellLiteral($path)
        );

        $result = runCommand(buildPowerShellCommand($script), $this->repoRoot, false);

        return $result['exit_code'] === 0;
    }

    private function log($message)
    {
        echo $message.PHP_EOL;
    }
}

function detectRepositoryRoot($directory)
{
    $result = runCommand('git rev-parse --show-toplevel', $directory);

    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('Current directory is not inside a Git repository.');
    }

    return normalizePath(trim($result['stdout']));
}

function loadMergedPhpConfig($scriptDir, $repoRoot, $configFileName)
{
    $config = array();
    $globalConfigPath = joinPath($scriptDir, $configFileName);
    $localConfigPath = joinPath($repoRoot, $configFileName);

    if (file_exists($globalConfigPath)) {
        $config = require $globalConfigPath;
    }

    if (file_exists($localConfigPath)) {
        $config = array_replace_recursive($config, require $localConfigPath);
    }

    return $config;
}

function runCommand($command, $workingDirectory, $throwOnError = true)
{
    $descriptorSpec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'w'),
    );

    $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start process: '.$command);
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $result = array(
        'stdout' => trimTrailingWhitespace($stdout),
        'stderr' => trimTrailingWhitespace($stderr),
        'exit_code' => $exitCode,
    );

    if ($throwOnError && $exitCode !== 0) {
        $message = $result['stderr'] !== '' ? $result['stderr'] : $result['stdout'];
        throw new RuntimeException($message !== '' ? $message : 'Command failed: '.$command);
    }

    return $result;
}

function buildPowerShellCommand($script)
{
    return 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command '.escapeShellArgument($script);
}

function escapeShellArgument($value)
{
    return escapeshellarg((string) $value);
}

function toPowerShellLiteral($value)
{
    return '\''.str_replace('\'', '\'\'', (string) $value).'\'';
}

function sanitizeBranchName($branchName)
{
    return trim(preg_replace('/[^A-Za-z0-9._-]+/', '--', (string) $branchName), '-');
}

function sanitizeDomainLabel($value)
{
    $value = strtolower((string) $value);
    $value = preg_replace('/[^a-z0-9-]+/', '-', $value);
    $value = trim($value, '-');

    return $value !== '' ? $value : 'worktree';
}

function normalizeRelativePath($path)
{
    $path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, (string) $path);
    $segments = explode(DIRECTORY_SEPARATOR, $path);
    $normalized = array();

    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..' && $normalized && end($normalized) !== '..') {
            array_pop($normalized);
            continue;
        }

        $normalized[] = $segment;
    }

    return implode(DIRECTORY_SEPARATOR, $normalized);
}

function joinPath()
{
    $parts = func_get_args();
    $normalized = array();

    foreach ($parts as $index => $part) {
        if ($part === null || $part === '') {
            continue;
        }

        $part = $index === 0 ? rtrim($part, '\\/') : trim($part, '\\/');
        if ($part === '') {
            continue;
        }

        $normalized[] = $part;
    }

    return normalizePath(implode(DIRECTORY_SEPARATOR, $normalized));
}

function normalizePath($path)
{
    return str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, (string) $path);
}

function isAbsolutePath($path)
{
    return preg_match('/^[A-Za-z]:[\\\\\\/]/', (string) $path) === 1;
}

function trimTrailingWhitespace($value)
{
    return rtrim((string) $value);
}

function relativePath($from, $to)
{
    $from = explode(DIRECTORY_SEPARATOR, trim(normalizePath($from), DIRECTORY_SEPARATOR));
    $to = explode(DIRECTORY_SEPARATOR, trim(normalizePath($to), DIRECTORY_SEPARATOR));

    if (!$from || !$to) {
        return '';
    }

    if (isset($from[0], $to[0]) && preg_match('/^[A-Za-z]:$/', $from[0]) && preg_match('/^[A-Za-z]:$/', $to[0])) {
        if (strcasecmp($from[0], $to[0]) !== 0) {
            return normalizePath($to[0].DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, array_slice($to, 1)));
        }
    }

    while ($from && $to && strcasecmp($from[0], $to[0]) === 0) {
        array_shift($from);
        array_shift($to);
    }

    $relative = array_merge(array_fill(0, count($from), '..'), $to);

    if (!$relative) {
        return '';
    }

    return implode(DIRECTORY_SEPARATOR, $relative);
}

function ospPath($relativePath)
{
    $relativePath = normalizeRelativePath($relativePath);

    if ($relativePath === '') {
        return '{base_dir}';
    }

    return '{base_dir}\\'.str_replace('/', '\\', str_replace('\\', '/', $relativePath));
}

function parseIniFileStructure($path)
{
    $data = array(
        'globals' => array(),
        'sections' => array(),
    );

    if (!file_exists($path)) {
        return $data;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        throw new RuntimeException('Unable to read INI file: '.$path);
    }

    $currentSection = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || strpos($trimmed, ';') === 0 || strpos($trimmed, '#') === 0) {
            continue;
        }

        if (preg_match('/^\[(.+)\]$/', $trimmed, $matches)) {
            $currentSection = $matches[1];
            if (!isset($data['sections'][$currentSection])) {
                $data['sections'][$currentSection] = array();
            }
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = rtrim(substr($line, 0, $pos));
        $value = ltrim(substr($line, $pos + 1));

        if ($currentSection === null) {
            $data['globals'][$key] = $value;
            continue;
        }

        $data['sections'][$currentSection][$key] = $value;
    }

    return $data;
}

function writeIniFileStructure($path, array $data)
{
    $lines = array();

    foreach ($data['globals'] as $key => $value) {
        $lines[] = $key.' = '.$value;
    }

    foreach ($data['sections'] as $sectionName => $sectionValues) {
        if ($lines) {
            $lines[] = '';
        }

        $lines[] = '['.$sectionName.']';

        foreach ($sectionValues as $key => $value) {
            $lines[] = $key.' = '.$value;
        }
    }

    $content = implode(PHP_EOL, $lines);
    if ($content !== '') {
        $content .= PHP_EOL;
    }

    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Unable to write INI file: '.$path);
    }
}
