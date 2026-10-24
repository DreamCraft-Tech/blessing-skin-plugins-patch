<?php

namespace Blessing\TwoFactor;

final class Menus
{
    public static function register($event): void
    {
        foreach ($event->menu['user'] as $item) {
            if (($item['link'] ?? '') === 'user/two-factor') {
                return;
            }
        }
        $entry = ['title' => '两步验证', 'link' => 'user/two-factor', 'icon' => 'fa-shield-alt'];
        foreach ($event->menu['user'] as $i => $item) {
            if (($item['link'] ?? '') === 'user/profile') {
                array_splice($event->menu['user'], $i + 1, 0, [$entry]);

                return;
            }
        }
        $event->menu['user'][] = $entry;
    }
}
