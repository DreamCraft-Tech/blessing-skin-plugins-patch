# CDN 真实客户端地址 1.0.0

适用 Blessing Skin 6.x（当前 PHP 8.5 分支）。统一 Laravel 的客户端 IP、客户端协议，以及主站 `client_ip` 过滤器；供登录限流、登录记录、企业微信通知和其他使用 Laravel Request 的插件读取。配置保存在主站 options 中，无额外数据库表或 Composer 依赖。

## 安装与腾讯云配置

1. 将运行包解压到站点 `plugins`，目录为 `plugins/cdn-real-ip/package.json`，在插件管理启用，打开插件配置。
2. 腾讯云 CDN 卡片默认启用。按下列任一方式配置回源来源：
   - 在“可信回源 IP / CIDR”填写实际 CDN 回源节点地址。腾讯云可通过 DescribeIpStatus 获取，指定域名、`Layer=last`、`ShowIpv6=true`，按业务区域选择 Area。
   - 在“回源验证值”点击生成，将生成值复制到腾讯云 CDN 的自定义回源请求头 `X-Blessing-CDN-Auth`，保存 CDN 和插件两边的配置。CDN 侧设置为覆盖同名请求头。插件只保存验证值的 SHA-256 摘要，之后留空保存会保留已有值。
3. 同时配置地址范围与验证值时，两项均需匹配。网段支持 IPv4、IPv6 和 CIDR，每行一个。
4. 保留 CDN 的 `X-Forwarded-For`、`X-Forwarded-Proto`。经过额外的负载均衡、OpenResty 反向代理或容器代理时，在“内部反向代理”中填写实际代理地址，并由该代理追加 X-Forwarded-For。
5. 从 CDN 域名访问配置页，在“当前请求诊断”确认客户端 IP、HTTPS 和匹配规则。修改后对新请求生效。

OpenResty 到 PHP-FPM 的 FastCGI 参数 `REMOTE_ADDR` 应传递 `$remote_addr`。本插件修改 PHP 应用看到的地址；OpenResty 自身访问日志中的地址由 OpenResty real_ip 配置决定。若 Web 服务器已还原真实 IP，诊断中可能直接显示该客户端连接地址。

## 厂商预设

| 接入 | 客户端地址 | 客户端协议 |
| --- | --- | --- |
| 腾讯云 CDN | X-Forwarded-For | X-Forwarded-Proto |
| 腾讯云 EdgeOne | EO-Connecting-IP；缺省时解析 X-Forwarded-For | X-Forwarded-Proto（quic 映射为 https） |
| 阿里云 CDN / DCDN | Ali-Cdn-Real-Ip；缺省时解析 X-Forwarded-For | X-Client-Scheme |
| Cloudflare | CF-Connecting-IP；Pseudo IPv4 对应 CF-Connecting-IPv6 | X-Forwarded-Proto |
| 华为云 CDN | X-Forwarded-For | X-Forwarded-Proto（在 CDN 回源头配置中传递客户端协议） |
| 火山引擎 / BytePlus | X-Real-IP；缺省时解析 X-Forwarded-For | X-Forwarded-Proto |
| 自定义代理 | 可配置单个地址头或代理链头 | 可配置协议头 |

Cloudflare 提供“获取官方地址段”，带加载状态与更新时间。获取结果填入表单，点击“保存配置”后应用。其他厂商可填写控制台/API 查询的实际回源网段，或使用自定义回源验证头。

地址链按可信代理边界从右向左解析；腾讯云仅使用验证值且未配置网段时，按其覆盖 X-Forwarded-For 的行为读取客户端首项。协议接受 http/https。多条规则匹配相同来源时优先选择最具体的网段，同优先级冲突会显示诊断结果。模拟解析使用已保存的配置，在本地解析输入的请求头。

超级管理员保存配置、获取地址段和模拟解析；管理员可查看诊断和配置。配置页响应设置为 private/no-store。历史记录中的既有 IP 保持原值。

## 开发与验证

运行包包含 PHP、Twig 和原生 JavaScript，目标 PHP 8.5，无前端构建和插件 vendor 目录。源码包额外提供测试。通过主系统提供 PHPUnit 与依赖：

```sh
BLESSING_SERVER_PATH=/absolute/path/to/blessing-skin-server php /absolute/path/to/blessing-skin-server/vendor/bin/phpunit -c /absolute/path/to/cdn-real-ip/phpunit.xml
```

覆盖多厂商 IPv4/IPv6、代理链、协议、回源验证、伪造头、登录限流、权限、CSRF、配置渲染及 Cloudflare 获取/失败保留配置。网络获取使用测试替身，实际 CDN 链路需部署后通过诊断页确认。

## 更新日志

### 1.0.0

- 聚合七类 CDN / 代理接入，统一客户端 IP 与请求协议。
- 提供可信网段、回源验证值、多层代理、当前请求诊断和模拟解析。
- 支持 Cloudflare 官方地址段获取与保存。

## 技术资料

- 腾讯云 CDN：https://cloud.tencent.com/document/product/228/37851
- 腾讯云回源节点 API：https://cloud.tencent.com/document/product/228/41954
- EdgeOne：https://intl.cloud.tencent.com/zh/document/product/1145/54211
- 阿里云：https://www.alibabacloud.com/help/en/cdn/user-guide/configure-custom-request-headers
- Cloudflare：https://developers.cloudflare.com/fundamentals/reference/http-headers/
- Cloudflare 地址段接口：https://api.cloudflare.com/client/v4/ips
- 华为云：https://support.huaweicloud.com/bestpractice-cdn/cdn_05_0007.html
- BytePlus：https://docs.byteplus.com/en/docs/byteplus-cdn/docs-origin-request-headers
