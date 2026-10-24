<?php

// A newly enabled plugin's namespace may not yet be registered in this request.
require_once __DIR__.'/src/Installer.php';

return [
    App\Events\PluginWasEnabled::class => fn () => Blessing\WeCom\Installer::install(),
];
