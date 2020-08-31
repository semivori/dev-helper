#!/usr/bin/env php
<?php

$config = [];

/**
 * Импорт глобального конфига
 */
if (file_exists( __DIR__ . '/h-config.php')) {
    $config = require __DIR__ . '/h-config.php';
}

/**
 * Папка из которой зупущен данный скрипт
 */
$FOLDER = exec('echo %CD%');

/**
 * Импорт локального конфига
 */
if (file_exists( $FOLDER . '/h-config.php')) {
    $config = array_merge_recursive($config, require $FOLDER . '/h-config.php');
}

/**
 * Папка c файлом yii
 */
$YII_FOLDER = $config['yii_folder'] ?? '.';

/**
 * Путь к файлу yii
 */
$yiiPath = "$YII_FOLDER/yii";

/**
 * Выполняет все миграции
 */
function m()
{
    yii("migrate --interactive=0");
}

/**
 * Создает файл миграции и открывает его в редакторе кода, установленном по умолчанию
 *
 * @param string $name - имя миграции
 */
function mc(string $name)
{
    global $YII_FOLDER;
    yii("migrate/create $name --interactive=0");
    $newFile = array_pop(scandir("$YII_FOLDER/migrations"));
    exec("start $YII_FOLDER/migrations/$newFile");
}

/**
 * Генерирует @see \yii\db\ActiveRecord модель
 * В случае отсутсвия параметра $class будет использоваться имя таблицы в UpperCamelCase
 * Namespace допускает использование обыного и бротного слэшей
 *
 * @param string $table - имя таблицы БД
 * @param string|null $class - имя и namespace класса, н-р app/models/User/User
 */
function gim(string $table, string $class = null)
{
    global $config;

    $ns = $config['model']['ns'] ?? null;
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

/**
 * Преобразует строку в UpperCamelCase
 *
 * @param string $input
 * @param string|null $separator
 * @return string|string[]
 */
function camelize(string $input, string $separator = '_')
{
    return str_replace($separator, '', ucwords($input, $separator));
}

/**
 * Выполняет yii команду
 *
 * @param string|null $command
 */
function yii(string $command = null)
{
    global $yiiPath;
    echo exec("php $yiiPath $command");
}

/**
 * Выводит список доступных функций с их параметрами
 */
function help()
{
    foreach (get_defined_functions()['user'] as $fKey => $function) {
        try {
            $refFunc = new ReflectionFunction($function);
            $params = [];
            foreach($refFunc->getParameters() as $key => $param) {
                $paramName = $param->name;
                $paramDefVal = null;
                if ($param->isDefaultValueAvailable()) {
                    $paramDefVal = ' = ';
                    $paramDefVal .= empty($param->getDefaultValue()) ? 'null' : "'{$param->getDefaultValue()}'";
                }
                $paramColor = "\e[". (91 + $key) ."m";
                $params[] = "$paramColor$paramName$paramDefVal";
            }

            if ($fKey != 0) {
                echo PHP_EOL;
            }
            echo "\e[34m$function\e[39m";
            if ($params) {
                echo ': ', implode("\e[39m, ", $params), "\e[39m";
            }
        } catch (Exception $exception) {}
    }
}

/**
 * Выводит конфигурацию
 */
function pc()
{
    global $config;
    print_r($config);
}

/**
 * Добавляет все файлы в гит и пушит их
 *
 * @param string $commit - сообщение коммита
 */
function gp(string $commit)
{
    global $config;

    if (isset($config['git']['commit_regex']) && !preg_match($config['git']['commit_regex'], $commit)) {
        echo 'Please enter commit message in the format: ' . $config['git']['commit_regex'];
        exit();
    }

    echo `git add *`;
    echo `git commit -m "$commit"`;
    echo PHP_EOL;
    echo `git push`;
}

/**
 * Создает новую ветку локально и удаленно и переходит в нее
 *
 * @param  string  $branch  - имя ветки
 * @param  int  $fromMaster - если = 1, то ветка будет создана с удаленного мастера
 */
function gb(string $branch, int $fromMaster = 1)
{
    global $config;

    if (isset($config['git']['branch_regex']) && !preg_match($config['git']['branch_regex'], $branch)) {
        echo 'Please enter branch name in the format: ' . $config['git']['branch_regex'];
        exit();
    }

    if ($fromMaster == 1) {
        echo `git checkout master`;
        echo PHP_EOL;
        echo `git checkout pull`;
        echo PHP_EOL;
    }

    echo `git checkout -b $branch`;
    echo PHP_EOL;
    echo `git push -u origin $branch`;
}

/**
 * Если $argv[1] - существущая фунция, то она будет вополнена, иначе вызыватся @see help()
 */
if(isset($argv[1]) && function_exists($argv[1])) {
    $parameters = array_slice($argv, 2);
    call_user_func_array($argv[1], $parameters);
} else {
    help();
}