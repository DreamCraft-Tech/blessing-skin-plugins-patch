# 企业微信登录与绑定 1.5.2

## 运行环境

Blessing Skin Server 6.0.2 开发快照（52f6fef）、Laravel 10、PHP 8.5、MariaDB。运行包包含 maxmind-db/reader 1.14.0 及前端静态资源。网站运行目录为 `public/`，PHP 启用数据库连接、HTTPS 请求与 gzip 解压所需扩展。

## 安装与更新

1. 在插件管理上传 `wecom-login_1.5.2_php85.zip` 并启用。手动部署时，将压缩包中的 `wecom-login/` 放入网站 `plugins/` 或 `PLUGINS_DIR` 指定目录。
2. 更新时覆盖原插件目录，并重新启用插件。启用过程创建和补齐插件表、字段及索引。数据库账号需具备相应 DDL 权限。OPcache 关闭时间戳检查时，更新后重启 PHP 运行环境。
3. 打开“插件配置 → 企业微信登录与绑定”，填写 CorpID、AgentID、完整回调 URL 和应用 Secret，保存配置。
4. 用户登录皮肤站后，在“高级功能 → 企业微信绑定”扫码绑定。绑定关系将企业成员身份映射至皮肤站 UID，随后可在登录页使用企业微信扫码登录。

## 企业微信应用配置

使用企业内部自建应用。在企业微信管理后台启用企业微信 Web 登录，配置授权回调域名、应用可见范围和服务器可信 IP。回调 URL 使用本站 HTTPS 域名，路径为 `/auth/wecom/callback`，例如：

```
https://skin.example.com/auth/wecom/callback
```

OpenResty 使用网站现有 Laravel 路由规则，并转发回调查询参数。企业微信域名校验文件 `WW_verify_*.txt` 放置在网站 `public/` 根目录。

## 配置来源

后台配置优先于环境变量。应用 Secret 使用网站 `APP_KEY` 加密保存；输入框留空时沿用当前值，勾选环境变量选项时切换为 `WECOM_SECRET`。更换 `APP_KEY` 后重新保存 Secret。

环境变量配置在网站项目根目录 `.env`：

```dotenv
WECOM_CORP_ID=企业ID
WECOM_AGENT_ID=应用AgentID
WECOM_SECRET=应用Secret
WECOM_REDIRECT_URI=https://skin.example.com/auth/wecom/callback
```

修改 `.env` 后，在网站 PHP 运行环境执行：

```sh
php artisan config:clear
```

使用 Laravel 配置缓存时，将 `WECOM_*` 注入 PHP-FPM 进程环境，或通过插件配置页面保存应用凭据。

## 登录通知

管理员开启“允许发送网页登录通知”，并选择消息类型和正文样式。用户在绑定页面保存个人推送时机：

| 模式 | 发送条件 |
| --- | --- |
| 严格 | 每次主动网页登录成功 |
| 宽松 | 中国境内城市名称变化；跨国；境外距离变化达到 300 公里；首次定位或定位待确认 |
| 关闭 | 停止登录通知 |

通知通过当前自建应用的消息发送 API 发送给绑定成员。主动网页登录成功后在请求终止阶段处理通知。自动恢复会话与 Yggdrasil 认证属于独立事件范围。访问令牌过期时刷新并重试一次。

宽松模式使用本地 DB-IP 城市库与 ipwho.is 在线复核，在线请求携带本次登录 IP，成功结果缓存 24 小时。定位比较以已确认的登录地基准为依据；定位缺失、冲突或城市库超过 62 天时发送确认提醒。

### 消息格式

类型支持 `textcard`、`text` 和 `markdown`。正文支持详细、简洁和自定义模板。卡片标题上限为 128 字节、正文 512 字节、按钮 4 个字符；文本及 Markdown 正文上限为 2048 字节，超长内容按 UTF-8 边界截断。卡片链接指向本站绑定页面。

| 变量 | 内容 |
| --- | --- |
| `{reason}` | 通知原因 |
| `{site_name}` | 网站名称 |
| `{username}` | 皮肤站昵称；空昵称显示“用户” |
| `{uid}` | 皮肤站用户编号 |
| `{login_time}` | 登录时间 |
| `{login_method}` | 登录方式 |
| `{ip}` | 登录 IP |
| `{city}` | 登录城市；定位缺失时显示“未知城市” |
| `{user_agent}` | 客户端 User-Agent |

默认 IP 格式为 `{ip}({city})`，例如 `203.0.113.10(上海)`。城市从本地库读取，宽松模式复用已校验的定位结果。

默认用户格式为 `{username}({uid})`，例如 `张三(123)`。自定义模板可使用同一格式。

自定义 Markdown 模板中的变量经过 Markdown 转义；卡片正文经过 HTML 转义。配置页面提供示例预览。

## IP 城市库更新

在“插件配置 → 企业微信登录与绑定 → IP 城市库管理”点击下载或更新。页面显示加载状态、下载、解压及校验进度，支持暂停和继续。最后更新时间采用本地城市库文件的修改时间，按网站时区显示。建议每月更新。

更新器读取 DB-IP 当月城市库，当月文件返回 404 时尝试前两个月。HTTPS Range 分段下载大小为 4 MiB，解压分段为 8 MiB；压缩文件上限 200 MiB，解压文件上限 512 MiB。完成 City 类型校验后原子替换目标文件。

默认状态目录为 `storage/app/wecom-login/`，包含更新状态、锁文件和城市库。PHP 进程需要该目录的写入权限。自定义城市库路径通过 `WECOM_GEOIP_DATABASE` 指定，更新状态保存于该路径所在目录。面板更新接口使用管理员权限及 Laravel CSRF 验证。

数据来源：[DB-IP](https://db-ip.com)，许可为 CC BY 4.0。插件绑定页包含来源署名。

## 数据库与路由

插件表采用 InnoDB，并沿用网站数据库表前缀：

- `wecom_bindings`：UID、企业与成员标识、成员名称、通知偏好和登录地基准。
- `wecom_oauth_states`：OAuth 状态、用途、关联 UID 及有效期。
- `wecom_geo_cache`：IP 哈希、定位结果与缓存有效期。

UID 关联在应用层处理，账号删除事件清理绑定数据。插件停用、卸载时保留表及配置，重新启用后沿用现有数据。

用户入口为 `/user/wecom`，后台入口为 `/admin/wecom/config`，城市库接口前缀为 `/admin/wecom/geoip`。登录页通过 Laravel 视图扩展点将企业微信与 OAuth 登录方式放在同一组，位于注册链接前。

## 日志与验证

Laravel 日志位于网站配置的日志通道，默认目录为 `storage/logs/`。排查时检查请求时间、企业微信接口名称、HTTP 状态和 `errcode`，并核对应用可见范围、可信 IP 与回调域名。

验证环境为 PHP 8.5.12、Laravel 10.48.25、SQLite 测试数据库及模拟企业微信响应。测试覆盖配置、绑定、状态校验、登录入口、消息格式、权限、通知策略和城市库更新；另包含官方 DB-IP 城市库下载及 MMDB 读取验证。测试日志和 JUnit 结果随验证包提供。
