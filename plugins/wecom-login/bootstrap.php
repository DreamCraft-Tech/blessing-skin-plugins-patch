<?php

declare(strict_types=1);

use App\Services\Hook;
use Blessing\Filter;
use Blessing\WeCom\Routes;
use Blessing\WeCom\Settings;

return function (Filter $filter): void {
    config(['wecom' => [
        'corp_id' => env('WECOM_CORP_ID', ''),
        'agent_id' => env('WECOM_AGENT_ID', ''),
        'redirect_uri' => env('WECOM_REDIRECT_URI', ''),
        'secret' => env('WECOM_SECRET', ''),
    ]]);
    Hook::addRoute(fn () => Routes::register());
    Hook::addMenuItem('user', 1, [
        'title' => '企业微信绑定', 'link' => 'user/wecom', 'icon' => 'fa-building',
    ]);
    $filter->add('auth_page_rows:login', function (array $rows): array {
        if (app(Settings::class)->ready()) {
            $rows[] = 'WeCom::login-row';
        }
        return $rows;
    });
};
