<?php

$server = getenv('BLESSING_SERVER_PATH');
if (!$server || !is_file($server.'/vendor/autoload.php')) {
    throw new RuntimeException('Set BLESSING_SERVER_PATH');
}
chdir($server);
require $server.'/vendor/autoload.php';
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('Blessing\\DailyLuck\\', dirname(__DIR__).'/src');
$loader->register();
require $server.'/tests/TestCase.php';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
