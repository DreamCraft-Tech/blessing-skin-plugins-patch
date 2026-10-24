<?php

namespace Blessing\CdnRealIp;

final class Profiles
{
    public static function all(): array
    {
        return [
            'tencent' => ['name' => '腾讯云 CDN', 'ip' => 'X-Forwarded-For', 'mode' => 'chain', 'proto' => 'X-Forwarded-Proto', 'docs' => 'https://cloud.tencent.com/document/product/228/37851'],
            'edgeone' => ['name' => '腾讯云 EdgeOne', 'ip' => 'EO-Connecting-IP', 'mode' => 'single', 'proto' => 'X-Forwarded-Proto', 'docs' => 'https://intl.cloud.tencent.com/zh/document/product/1145/54211'],
            'aliyun' => ['name' => '阿里云 CDN / DCDN', 'ip' => 'Ali-Cdn-Real-Ip', 'mode' => 'single', 'proto' => 'X-Client-Scheme', 'docs' => 'https://www.alibabacloud.com/help/en/cdn/user-guide/configure-custom-request-headers'],
            'cloudflare' => ['name' => 'Cloudflare', 'ip' => 'CF-Connecting-IP', 'mode' => 'single', 'proto' => 'X-Forwarded-Proto', 'docs' => 'https://developers.cloudflare.com/fundamentals/reference/http-headers/'],
            'huawei' => ['name' => '华为云 CDN', 'ip' => 'X-Forwarded-For', 'mode' => 'chain', 'proto' => 'X-Forwarded-Proto', 'docs' => 'https://support.huaweicloud.com/bestpractice-cdn/cdn_05_0007.html'],
            'volcengine' => ['name' => '火山引擎 / BytePlus CDN', 'ip' => 'X-Real-IP', 'mode' => 'single', 'proto' => 'X-Forwarded-Proto', 'docs' => 'https://docs.byteplus.com/en/docs/byteplus-cdn/docs-origin-request-headers'],
            'custom' => ['name' => '通用 / 自定义反向代理', 'ip' => 'X-Forwarded-For', 'mode' => 'chain', 'proto' => 'X-Forwarded-Proto', 'docs' => 'https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/X-Forwarded-For'],
        ];
    }

    public static function headers(): array
    {
        return ['X-Forwarded-For', 'Forwarded', 'X-Real-IP', 'CF-Connecting-IP', 'CF-Connecting-IPv6', 'EO-Connecting-IP', 'Ali-Cdn-Real-Ip',
            'True-Client-IP', 'Client-IP', 'X-Client-IP', 'X-Forwarded', 'X-Cluster-Client-IP', 'Forwarded-For',
            'X-Forwarded-Proto', 'X-Client-Scheme', 'X-Forwarded-Protocol', 'X-Forwarded-SSL', 'X-Forwarded-Host', 'X-Forwarded-Port', 'X-Forwarded-Prefix'];
    }
}
