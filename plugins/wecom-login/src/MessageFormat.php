<?php

declare(strict_types=1);
namespace Blessing\WeCom;

final class MessageFormat
{
    public const DEFAULT_TITLE = '皮肤站登录通知';
    public const DEFAULT_TEMPLATE = "{reason}\n{site_name} 登录通知\n用户：{username}({uid})\n时间：{login_time}\n方式：{login_method}\nIP：{ip}({city})\n设备：{user_agent}\n可在账号页面查看绑定与通知设置。";
    public const COMPACT_TEMPLATE = "{reason}\n用户：{username}({uid})\n时间：{login_time}\nIP：{ip}({city})";
    public const VARIABLES = ['reason', 'site_name', 'username', 'uid', 'login_time', 'login_method', 'ip', 'city', 'user_agent'];

    public function settings(): array
    {
        $type = (string) option('wecom_message_type', 'textcard');
        $style = (string) option('wecom_message_style', 'detailed');
        return ['message_type' => in_array($type, ['textcard', 'text', 'markdown'], true) ? $type : 'textcard',
            'message_style' => in_array($style, ['detailed', 'compact', 'custom'], true) ? $style : 'detailed',
            'message_title' => (string) option('wecom_message_title', self::DEFAULT_TITLE),
            'message_template' => (string) option('wecom_message_template', self::DEFAULT_TEMPLATE),
            'message_button' => (string) option('wecom_message_button', '查看账号')];
    }

    public function render(array $context): string
    {
        $settings = $this->settings();
        $template = match ($settings['message_style']) {'compact' => self::COMPACT_TEMPLATE, 'custom' => $settings['message_template'], default => self::DEFAULT_TEMPLATE};
        $replace = [];
        foreach (self::VARIABLES as $key) {
            $value = (string) ($context[$key] ?? '');
            $value = preg_replace('/[\x00-\x1f\x7f]/u', ' ', $value) ?? '';
            if ($settings['message_type'] === 'markdown') {
                $value = strtr($value, ['\\' => '\\\\', '`' => '\\`', '*' => '\\*', '_' => '\\_', '[' => '\\[', ']' => '\\]', '(' => '\\(', ')' => '\\)', '<' => '&lt;', '>' => '&gt;']);
            }
            $replace['{'.$key.'}'] = $value;
        }
        return strtr($template, $replace);
    }

    public function validTemplate(string $template): bool
    {
        preg_match_all('/\{([^{}]+)\}/u', $template, $matches);
        return array_diff($matches[1], self::VARIABLES) === [];
    }
}
