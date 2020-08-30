#!/usr/bin/env php
<?php

if (file_exists( __DIR__ . '/h-config.php')) {
    $config = require __DIR__ . '/h-config.php';
}

$yiiPath = $config['yii_path'] ?? './yii';

/**
 * Создает файл миграции и открывает его в редакторе кода, установленном по умолчанию
 *
 * @param string $name - имя миграции
 */
function mc(string $name)
{
    echo yii("migrate/create $name --interactive=0");
    $newFile = array_pop(scandir('./migrations'));
    exec("start migrations/$newFile");
}

/**
 * Генерирует @see \yii\db\ActiveRecord модель
 * В случае отсутсвия параметра $class будет использоваться имя таблицы в UpperCamelCase
 * Namespace допускает использование обыного и бротного слэшей
 *
 * @param string $table - имя таблицы БД
 * @param string|null $class - имя и namespace класса, н-р app/models/User/User
 */
function gm(string $table, string $class = null)
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

    echo yii("$command --interactive=0");
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
 * @return mixed
 */
function yii(string $command = null)
{
    global $yiiPath;
    return exec("php $yiiPath $command");
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
 * Если $argv[1] - существущая фунция, то она будет вополнена, иначе вызыватся @see help()
 */
if(isset($argv[1]) && function_exists($argv[1])) {
    $parameters = array_slice($argv, 2);
    call_user_func($argv[1], ...$parameters);
} else {
    help();
}