<?php

declare(strict_types=1);

namespace Blessing\WeCom;

use App\Events\ConfigureUserMenu;

final class Menus
{
    public static function appendUserItem(ConfigureUserMenu $event): void
    {
        $entry = ['title' => '企业微信绑定', 'link' => 'user/wecom', 'icon' => 'fa-building'];
        foreach ($event->menu['user'] as &$item) {
            $children = $item['children'] ?? [];
            $hasOAuth = array_filter($children, fn (array $child): bool => ($child['link'] ?? '') === 'user/oauth/manage');
            if (($item['title'] ?? '') !== 'general.developer' && !$hasOAuth) {
                continue;
            }
            foreach ($children as $child) {
                if (($child['link'] ?? '') === 'user/wecom') {
                    return;
                }
            }
            $item['children'][] = $entry;
            return;
        }
        unset($item);
        // Keep the entry nested even if a customized site omitted the standard group.
        $event->menu['user'][] = [
            'title' => 'general.developer', 'icon' => 'fa-code-branch', 'children' => [$entry],
        ];
    }
}
