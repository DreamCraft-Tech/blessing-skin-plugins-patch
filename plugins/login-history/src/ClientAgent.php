<?php

declare(strict_types=1);
namespace Blessing\LoginHistory;

final class ClientAgent
{
    public function label(string $agent): string
    {
        if (trim($agent) === '') {return '未记录';}
        $os = match (true) {
            (bool) preg_match('/iPad|Macintosh.*Mobile/i', $agent) => 'iPadOS',
            (bool) preg_match('/iPhone|iPod/i', $agent) => 'iOS',
            (bool) preg_match('/Windows Phone/i', $agent) => 'Windows Phone',
            (bool) preg_match('/Android/i', $agent) => 'Android',
            (bool) preg_match('/Windows NT|Win64|Win32/i', $agent) => 'Windows',
            (bool) preg_match('/Macintosh|Mac OS X|Darwin/i', $agent) => 'macOS',
            (bool) preg_match('/Linux|X11/i', $agent) => 'Linux',
            default => '',
        };
        if (preg_match('/\bwxwork\b|\bWeCom\b|\bWeWork\b/i', $agent)) {return '企业微信'.($os !== '' ? ' · '.$os : '');}
        if (preg_match('/MicroMessenger/i', $agent)) {return '微信'.($os !== '' ? ' · '.$os : '');}
        if ($os === 'iPadOS') {return '平板 · iPadOS';}
        if (in_array($os, ['iOS', 'Android', 'Windows Phone'], true)) {return '手机 · '.$os;}
        if ($os !== '') {return '电脑 · '.$os;}
        if (preg_match('/Mobile|Mobi\b/i', $agent)) {return '手机';}
        return '其他客户端';
    }
}
