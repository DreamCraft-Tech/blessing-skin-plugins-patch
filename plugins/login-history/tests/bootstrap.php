<?php

$server = getenv('BLESSING_SERVER_PATH');
if (!$server || !is_file($server.'/vendor/autoload.php')) {
    throw new RuntimeException('Set BLESSING_SERVER_PATH to a Blessing Skin Server source checkout with development dependencies.');
}
chdir($server);
require $server.'/vendor/autoload.php';
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('Blessing\\LoginHistory\\', dirname(__DIR__).'/src');
$plugins = getenv('BLESSING_PLUGINS_PATH');
$loader->addPsr4('Yggdrasil\\', $plugins.'/plugins/yggdrasil-api/src');
$loader->addPsr4('LittleSkin\\YggdrasilConnect\\', $plugins.'/plugins/yggdrasil-connect/src');
$loader->register();
require_once $server.'/tests/TestCase.php';

$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
