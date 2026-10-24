<?php

$server = getenv('BLESSING_SERVER_PATH');
if (!$server || !is_file($server.'/vendor/autoload.php')) {
    throw new RuntimeException('Set BLESSING_SERVER_PATH');
}
chdir($server);
require $server.'/vendor/autoload.php';
require dirname(__DIR__).'/vendor/autoload.php';
require $server.'/tests/TestCase.php';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
