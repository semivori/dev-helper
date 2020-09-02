#!/usr/bin/env php
<?php

$config = [];

/**
 * Импорт глобального конфига
 */
if (file_exists(__DIR__.'/h-config.php')) {
    $config = require __DIR__.'/h-config.php';
}

/**
 * Папка из которой зупущен данный скрипт
 */
$FOLDER = exec('echo %CD%');

/**
 * Импорт локального конфига
 */
if (file_exists($FOLDER.'/h-config.php')) {
    $config = array_merge_recursive($config, require $FOLDER.'/h-config.php');
}

/**
 * Папка c файлом yii
 */
$YII_FOLDER = $config['yii_folder'] ?? '.';

/**
 * Путь к файлу yii
 */
$yiiPath = "$YII_FOLDER/yii";

trait Console
{
    /** @var string */
    private $output;

    /**
     * @param  string  $command
     * @param  null  $output
     * @return string
     */
    public function exec(string $command, &$output = null)
    {
        $output = $output ?: $this->output;
        exec($command, $output);
        return $output;
    }

    /**
     * @param  mixed  ...$outputs
     */
    public function write(...$outputs)
    {
        foreach ($outputs as $output) {
            echo $output;
        }
    }

    /**
     * @return void
     */
    public function newLine()
    {
        echo PHP_EOL;
    }
}


class Git
{
    use Console {
        exec as baseExec;
    }

    /** @var array */
    private $config = [];

    /**
     * Git constructor.
     * @param  array  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
    }

    /**
     * @param  string  $command
     * @param  null  $output
     * @return string
     */
    public function exec(string $command, &$output = null)
    {
        return $this->baseExec("git $command", $output);
    }

    /**
     * Добавляет все файлы в гит и пушит их
     *
     * Флаги:
     * -f => форсированный коммит, имя коммита не будет проверятся
     * -pr => открывает страницу создания Pull Request @param  string  $commit  - сообщение коммита
     * @see gpr()
     *
     */
    public function pushAll(string $commit)
    {
        $args = func_get_args();
        $forceCommit = in_array('-f', $args);
        $openPullRequest = in_array('-pr', $args);

        if ($commit == "" && isset($this->config['default_commit'])) {
            $defaultCommit = $this->config['default_commit'];
            if ($defaultCommit instanceof Closure) {
                $branchName = exec("git symbolic-ref -q --short HEAD");
                $commit = call_user_func($defaultCommit, $branchName);
            } else {
                if (is_string($defaultCommit)) {
                    $commit = $defaultCommit;
                } else {
                    $this->write("config ['default_commit'] must be string or Closure");
                    exit();
                }
            }
        }

        if (!$forceCommit && isset($this->config['commit_regex']) && !preg_match($this->config['commit_regex'],
                $commit)) {
            $this->write('Please enter commit message in the format: ', $this->config['commit_regex']);
            exit();
        }

        $this->write($this->exec("add *"));
        $this->write($this->exec("commit -m \"$commit\""));
        $this->newLine();
        $this->write($this->exec("push"));

        if ($openPullRequest) {
            $this->pullRequest();
        }
    }

    /**
     * Открывает в браузере страницу создания Pull Request на https://github.com для текущей в ветки
     */
    public function pullRequest()
    {
        $repoUrl = $this->exec("config --get remote.origin.url");

        if (!preg_match('/github.com/', $repoUrl)) {
            echo 'Only Github repos are supported';
            return false;
        }

        $branchName = $this->exec("symbolic-ref -q --short HEAD");
        $repoUrl = preg_replace('/.git$/', '', $repoUrl);
        $prUrl = "$repoUrl/compare/$branchName?expand=1";
        $this->baseExec("start $prUrl");
        return true;
    }

    /**
     * Создает новую ветку локально и удаленно и переходит в нее
     *
     * @param  string  $branch  - имя ветки
     * @param  int  $fromMaster  - если = 1, то ветка будет создана с удаленного мастера
     */
    public function createBranch(string $branch, int $fromMaster = 1)
    {
        if (isset($this->config['branch_regex']) && !preg_match($this->config['branch_regex'], $branch)) {
            $this->write('Please enter branch name in the format: '.$this->config['branch_regex']);
            exit();
        }

        if ($fromMaster == 1) {
            $this->write(
                $this->exec("checkout master"),
                PHP_EOL,
                $this->exec("pull"),
                PHP_EOL
            );
        }

        $this->write($this->exec("checkout -b $branch"));
        $this->newLine();
        $this->write($this->exec("push -u origin $branch"));
    }

    /**
     * Ищет ветку по шаблону и переключается в нее
     * Если найдено несколько веток, то выводит все совпадения, и ожидает ввода более точного имени
     * Если найдена только одна ветки, то переключается в нее
     * Если $pattern - numeric, пытается перейти в ветку по шаблону "-$pattern-"
     *
     * @param  string  $pattern
     * @return mixed
     */
    public function checkoutPattern(string $pattern)
    {
        $branch = null;

        if (is_numeric($pattern)) {
            $pattern = "-$pattern-";
        }

        $branches = [];
        $this->exec("branch --list *$pattern*", $branches);

        if (!$branches) {
            goto noMatchingBranch;
        }

        $branches = array_map(function ($branch) {
            return trim($branch);
        }, $branches);

        if (count($branches) == 1) {
            $branch = array_shift($branches);
            goto checkout;
        }

        $this->write("Several branches are available: ", PHP_EOL, implode(PHP_EOL, $branches), PHP_EOL);
        $this->write("Please enter more characters: ");
        $pattern = stream_get_line(STDIN, 1024, PHP_EOL);
        return gcb($pattern);

        checkout:
        if ($branch) {
            $this->write($this->exec("checkout $branch"));
            $this->write($this->exec("pull"));
            return true;
        }

        noMatchingBranch:
        echo 'No matching branch';
        return false;
    }

    /**
     * Открывает страницу репозитория в браузере
     */
    function open()
    {
        $repoUrl = $this->exec("config --get remote.origin.url");
        $this->baseExec("start $repoUrl");
    }

    /**
     * @return void
     */
    public function status()
    {
        $this->write($this->exec('status'));
    }

    public function help()
    {
        return [
            'pushAll' => [
                "Добавляет все файлы в гит и пушит их",
                "Флаги: ",
                "-f => форсированный коммит, имя коммита не будет проверятся",
                "-pr => открывает страницу создания Pull Request @see gpr()",
                "@param string \$commit - сообщение коммита"
            ]
        ];
    }
}

if (isset($argv[1])) {
    $parameters = array_slice($argv, 2);
    list($class, $method) = explode('/', $argv[1]);

    $instance = new $class();
    $instance->$method($parameters);
} else {
    $classes = array_filter(
        get_declared_classes(),
        function ($className) {
            return !call_user_func(
                array(new ReflectionClass($className), 'isInternal')
            );
        }
    );

    foreach ($classes as $class) {
        echo $class, PHP_EOL;
    }
}
