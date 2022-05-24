#!/usr/bin/env php
<?php

$config = [];

$configFileName = 'h2-config.php';

/**
 * Импорт глобального конфига
 */
if (file_exists(__DIR__.DIRECTORY_SEPARATOR.$configFileName)) {
    $config = require __DIR__.DIRECTORY_SEPARATOR.$configFileName;
}

/**
 * Папка из которой зупущен данный скрипт
 */
$FOLDER = exec('echo %CD%');

/**
 * Импорт локального конфига
 */
if (file_exists($FOLDER.DIRECTORY_SEPARATOR.$configFileName)) {
    $config = array_merge($config, require $FOLDER.DIRECTORY_SEPARATOR.$configFileName);
}

/**
 * Папка c файлом yii
 */
$YII_FOLDER = $config['yii_folder'] ?? '';

/**
 * Путь к файлу yii
 */
$YII_PATH = normalizeFilePath($FOLDER.DIRECTORY_SEPARATOR."$YII_FOLDER/yii");

function main($argv, $config = [])
{
    if (isset($argv[1])) {
        $parameters = array_slice($argv, 2);

        $route = $argv[1];
        if (isset($config['aliases'][$route])) {
            $route = $config['aliases'][$route];
        }

        list($class, $method) = explode('/', $route);

        $instance = new $class($config);
        $instance->$method(...$parameters);
    } else {
        if (isset($config['aliases'])) {
            echo 'Aliases:', PHP_EOL;

            foreach ($config['aliases'] as $key => $value) {
                if ($value) {
                    echo "\t$key:\t$value";
                }
                echo PHP_EOL;
            }
        }

        $classes = array_filter(
            get_declared_classes(),
            function ($className) {
                return !call_user_func(
                    array(new ReflectionClass($className), 'isInternal')
                );
            }
        );

        if ($classes) {
            echo 'Classes: ', PHP_EOL;
        }

        foreach ($classes as $class) {
            if (in_array($class, ['Yii', 'YiiComponent'])) {
                continue;
            }

            echo "\t$class", PHP_EOL;
            if (false) {
                $instance = new $class($config);

                if (method_exists($instance, 'help')) {
                    printHelp($instance->help());
                    echo PHP_EOL;
                }
            }
        }
    }
}

function printHelp(array $data)
{
    foreach ($data as $key => $datum) {
        echo "\t", $key, PHP_EOL;
        foreach ($datum as $row) {
            echo "\t\t", $row, PHP_EOL;
        }
    }
}

/**
 * Преобразует строку в UpperCamelCase
 *
 * @param  string  $input
 * @param  string|null  $separator
 * @return string|string[]
 */
function camelize(string $input, string $separator = '_')
{
    return str_replace($separator, '', ucwords($input, $separator));
}

function normalizeFilePath(string $path)
{
    $wrongSlash = DIRECTORY_SEPARATOR == '/' ? '\\' : '/';
    $path = str_replace($wrongSlash, DIRECTORY_SEPARATOR, $path);
    $path = str_replace(DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, $path);

    return $path;
}

trait Console
{
    /** @var string */
    private $output;

    /**
     * @param  string  $command
     * @param  null  $output
     * @return string|array|null
     */
    public function consExec(string $command, &$output = null)
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
            if (is_array($output)) {
                $this->write(...$output);
            } else {
                echo $output;
                if (count($outputs) > 1) {
                    $this->newLine();
                }
            }
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
    use Console;

    /** @var array */
    private $config = [];

    /**
     * Git constructor.
     * @param  array  $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config['git'] ?? [];
    }

    /**
     * @param  string  $command
     * @param  null  $output
     * @return string
     */
    public function exec(string $command, &$output = null)
    {
        $res = $this->consExec("git $command", $output);
        if (count($res) == 1) {
            return array_shift($res);
        }

        return $res;
    }

    public function plus()
    {
        $args = func_get_args();
        call_user_func_array(
            [$this, 'pushAll'],
            array_merge(
                ['commit' => ''],
                $args
            )
        );
        $this->pullRequest();
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
    public function pushAll(string $commit = '')
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

        if (
            !$forceCommit
            && isset($this->config['commit_regex'])
            && !preg_match($this->config['commit_regex'], $commit)
        ) {
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
        $this->consExec("start $prUrl");

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

        $branches = array_map(
            function ($branch) {
                return trim($branch);
            },
            $branches
        );

        if (count($branches) == 1) {
            $branch = array_shift($branches);
            goto checkout;
        }

        $this->write("Several branches are available: ", PHP_EOL, implode(PHP_EOL, $branches), PHP_EOL);
        $this->write("Please enter more characters: ");
        $pattern = stream_get_line(STDIN, 1024, PHP_EOL);

        $fullMatch = array_filter(
            $branches,
            function ($branch) use ($pattern) {
                return $branch == $pattern;
            }
        );

        if ($fullMatch) {
            $branch = array_shift($fullMatch);
            goto checkout;
        }

        $this->checkoutPattern($pattern);

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

    public function listBranches(string $pattern = null)
    {
        if (is_numeric($pattern)) {
            $pattern = "-$pattern-";
        }

        $branches = [];
        $this->exec("branch --list *$pattern*", $branches);

        if (!$branches) {
            echo 'No matching branch';

            return false;
        }

        $this->write(implode(PHP_EOL, $branches), PHP_EOL);

        return true;
    }

    /**
     * Открывает страницу репозитория в браузере
     */
    function open()
    {
        $repoUrl = $this->exec("config --get remote.origin.url");
        $this->consExec("start $repoUrl");
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
                "@param string \$commit - сообщение коммита",
            ],
        ];
    }
}

class Yii
{
    use Console;

    /** @var string */
    protected $YII_PATH;

    /** @var array */
    private $config = [];

    /**
     * Yii constructor.
     * @param  string  $YII_PATH
     * @param  array  $config
     */
    public function __construct(string $YII_PATH, array $config = [])
    {
        $this->config = $config;
        $this->YII_PATH = $YII_PATH;
    }

    public function getFolder()
    {
        return str_replace('yii', '', $this->getPath());
    }

    public function getPath()
    {
        return $this->YII_PATH;
    }

    /**
     * @param  string  $command
     * @param  null  $output
     * @return string
     */
    public function exec(string $command, &$output = null)
    {
        return $this->consExec("{$this->getPath()} $command", $output);
    }
}

class YiiComponent
{
    use Console;

    /** @var array */
    protected $config = [];

    /** @var Yii */
    protected $yii;

    /**
     * Gii constructor.
     *
     * @param  array  $globalConfig
     */
    public function __construct(array $globalConfig = [])
    {
        global $YII_PATH;

        $this->yii = new Yii($YII_PATH, $globalConfig);
        $this->config = $globalConfig;
    }
}

class Migrate extends YiiComponent
{
    public function exec(string $subCommand = null, $interactive = 0)
    {
        $subCommand = $subCommand ? "/$subCommand" : null;

        return $this->yii->exec("migrate$subCommand --interactive=$interactive");
    }

    public function apply()
    {
        $this->write($this->exec());
    }

    public function getPath()
    {
        return $this->yii->getFolder().DIRECTORY_SEPARATOR.'migrations';
    }

    public function open(string $name)
    {
        exec("start {$this->getPath()}/$name");
    }

    public function getLast()
    {
        $items = scandir($this->getPath());

        return array_pop($items);
    }

    public function openLast()
    {
        $fileName = $this->getLast();
        $this->open($fileName);
    }

    /**
     * Создает файл миграции и открывает его в редакторе кода, установленном по умолчанию
     *
     * @param  string  $name  - имя миграции
     */
    public function create(string $name)
    {
        $this->write($this->exec("create $name"));
        $this->openLast();
    }
}

class Gii extends YiiComponent
{
    /**
     * @param  string  $command
     * @param  null  $output
     * @return string
     */
    public function exec(string $command, &$output = null)
    {
        return $this->yii->exec("gii $command", $output);
    }

    /**
     * Генерирует \yii\db\ActiveRecord модель
     * В случае отсутсвия параметра $class будет использоваться имя таблицы в UpperCamelCase
     * Namespace допускает использование обыного и бротного слэшей
     *
     * @param  string  $table  - имя таблицы БД
     * @param  string|null  $class  - имя и namespace класса, н-р app/models/User/User
     */
    function model(string $table, string $class = null)
    {
        $ns = $this->config['model']['ns'] ?? null;
        $class = preg_replace('#/#', '\\', $class ?: camelize($table));

        if (strpos($class, '\\')) {
            $ns = substr($class, 0, strrpos($class, '\\'));
            $class = substr($class, strrpos($class, '\\') + 1);
        }

        $command = "gii/model --tableName=$table --modelClass=$class";
        if ($ns) {
            $command .= " --ns=$ns";
        }

        yii("$command --interactive=0");
    }
}

class Helper
{
    use Console;

    public function resetPasswords(
        string $db = 'erp',
        $pass = '$2y$13$e4msSNe509BHXoNmFJHqG.5WqNa4dnncrx6EQFukBUMr/.tEpXxxu'
    ) {
        $this->write(
            $this->consExec("mysql --user=root --password=root -D {$db} -e \"UPDATE user SET `password` = '{$pass}'\"")
        );
    }
}

main($argv, $config);
