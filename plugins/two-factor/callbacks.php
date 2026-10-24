<?php

require_once __DIR__.'/src/Installer.php';

return [App\Events\PluginWasEnabled::class => fn () => Blessing\TwoFactor\Installer::install()];
