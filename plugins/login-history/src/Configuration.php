<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;
final class Configuration
{
    public function render(History $history)
    {
        return view('Blessing\\LoginHistory::config', ['legacy_available'=>$history->legacyAvailable()]);
    }
}
