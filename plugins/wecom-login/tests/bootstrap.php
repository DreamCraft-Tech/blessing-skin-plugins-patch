<?php

$server = getenv('BLESSING_SERVER_PATH');
if (!$server || !is_file($server.'/vendor/autoload.php')) {
    throw new RuntimeException('Set BLESSING_SERVER_PATH to a Blessing Skin Server source checkout with development dependencies.');
}
chdir($server);
require $server.'/vendor/autoload.php';
$loader = new Composer\Autoload\ClassLoader();
$loader->addPsr4('Blessing\\WeCom\\', dirname(__DIR__).'/src');
$loader->register();
require_once $server.'/tests/TestCase.php';

require_once dirname(__DIR__).'/vendor/autoload.php';
