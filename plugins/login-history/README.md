# 网页登录与 Yggdrasil 登录记录

适用于此前的 Blessing Skin Server 6.0.2 开发快照 / Laravel 10 / PHP 8.5。独立于企业微信插件，可同时安装。

## 安装与入口

1. 在后台上传 `login-history_1.0.1_php85.zip` 并启用。
2. 用户在 **高级功能 → 登录记录** 查询本人记录。原有 OAuth 应用和企业微信绑定菜单不会被替换。
3. 后台 **插件配置 → 网页登录与 Yggdrasil 登录记录** 显示采集范围和旧日志检测情况；本版为说明页，不提供查看他人记录的接口。

启用时创建 `login_history_entries` 与 `login_history_profiles` 两张 InnoDB 表，沿用网站数据库表前缀。数据库账号需要建表及插件内部外键权限，不对宿主 `users` 表建立外键，兼容其现有字段类型与表引擎。无需安装 Composer 依赖、编译前端或修改现有 Yggdrasil 插件。

PHP 运行环境应使用之前的 PHP 8.5 配置；OpenResty 沿用现有伪静态规则，运行目录仍为 `public/`。

## 记录内容

- **网页登录**：监听网站标准的 Laravel `Login` 事件；原生登录以及使用该事件的企业微信 / OAuth 登录均可记录。同一登录不会同时重复订阅网站的多种成功事件。
- **Yggdrasil 账号认证**：成功调用 `authenticate`。
- **Yggdrasil 角色选择 / 令牌刷新**：成功调用 `refresh`。刷新不是一次新的密码登录，页面单独标记。
- **加入服务器请求**：成功调用 `join`；不等同于最终进入游戏服务器或持续在线状态。

记录时间、IP、User-Agent、来源、操作，以及当时的角色 ID / 名称 / UUID。只采集成功结果；本版不记录失败尝试，不采集 `validate`、`hasJoined` 等高频查询，不保存密码、accessToken、clientToken、Cookie、serverId 或原始请求 / 响应。

新记录从启用插件时开始，不会凭空恢复此前的网页登录历史。网页登录仅代表账号登录，不关联某个游戏角色。

## 多角色处理

记录永久以当时的皮肤站 **UID** 归属账号，角色信息作为附属快照，不以角色名代替账号归属。

- 账号有多个角色、`authenticate` 尚未返回 `selectedProfile` 时，记作**未选择角色（账号级认证）**，并保存当时返回的可用角色列表。不会擅自选择第一个角色。
- 只有一个角色自动被选中，或刷新令牌选择了角色时，记录真正返回的 `selectedProfile`。
- `join` 使用已验证的角色 UUID 映射到该账号的角色 ID，并保存角色名称快照。
- 角色改名、转移或删除后，已有记录仍属于原 UID。新角色所有者不能查看原账号的历史。
- 按具体角色筛选时，**只匹配实际被选中的角色**；“可用角色”不是这些角色都登录过的证明。账号级认证可通过“未选择角色”筛选单独查看。
- 为控制单次记录体积，最多保存 1000 项角色快照，实际选定角色优先保存。

## 查询

支持按网页 / Yggdrasil 来源、认证 / 刷新 / 入服操作、角色和日期筛选，每页固定 20 条。日期及页面时间采用网站时区，新记录底层统一保存 UTC。服务器已按当前登录用户 UID 限制查询，不接受前端指定另一用户 UID。

页面不缓存，User-Agent、角色名等内容经过 HTML 转义。IP 使用 Laravel 当前请求 IP，只有在网站正确配置可信反向代理后才能反映真实客户端地址；插件不会自行相信任意 X-Forwarded-For 头。

## 已有 Yggdrasil 历史日志

检测到 `ygg_log` 表及所需字段时，来源选择框增加“既有 Yggdrasil 历史日志”。该入口只读查询当前 UID 对应的 `authenticate`、`refresh`、`join` 记录。

旧日志仅读取 `id`、`action`、`user_id`、`player_id`、`ip`、`time`，**不读取原始 parameters**，避免显示其中可能存在的令牌。没有角色名 / UUID 快照的旧记录只显示历史角色编号，不拿当前角色归属或当前名称推测当时的信息。旧记录的时间按网站当前时区解释。

这个入口需要此前 Yggdrasil 自身确实开启过日志并产生数据；不会为了新记录采集而强制打开 `YGG_VERBOSE_LOG`。插件不修改、迁移或清理原有 `ygg_log`。

## 保留与故障行为

新记录和页面查询默认保留最近 **90 天**。产生新记录时最多每小时清理一次过期的插件数据；无新登录时物理清理不会主动触发，但查询始终隐藏超出 90 天的记录。旧日志入口也仅显示最近 90 天，其物理数据由原插件管理。

- 停用、卸载插件保留两张历史表，不执行清库。
- 删除皮肤站账号时，通过用户模型删除事件清理其新记录及角色快照（插件需启用）。
- 记录写入失败不会阻止网页登录或 Yggdrasil 认证；仅向网站日志写入不含凭据的通用告警。

## 兼容和验证边界

采集器针对官方 `yggdrasil-api 5.2.1` 和 `yggdrasil-connect 6.2.0` 的控制器与协议响应，两个插件本身互斥，本站只启用实际使用的一种。既有打包中的 `+php85.1` 版本也适用。更换控制器命名空间、路由实现的第三方修改版不保证自动识别。

测试包含真实网页登录、真实 Yggdrasil API 控制器的多角色认证 / 刷新 / 入服流程，以及隔离、快照归属、分页、过期清理、脱敏和数据库表前缀。Yggdrasil Connect 的采集以已认证用户与协议响应契约测试验证，未执行完整 OAuth / Passport 签名令牌流程。未连接你的 MariaDB、启动器或游戏服务器进行实机联调。

## 运行源码测试

使用测试源码目录及开发依赖，不要在生产数据库上执行：

```sh
BLESSING_SERVER_PATH=/path/to/blessing-skin-server \
BLESSING_PLUGINS_PATH=/path/to/blessing-skin-plugins \
/path/to/php-8.5 /path/to/blessing-skin-server/vendor/bin/phpunit \
  -c /path/to/login-history/phpunit.xml
```

Yggdrasil 仓库应包含 `plugins/yggdrasil-api` 和 `plugins/yggdrasil-connect` 源码。可部署 ZIP 不包含测试和 PHPUnit 配置。

## 缺表修复版本 1.0.1

启用回调和每次插件启动均检查两张插件表，缺哪张补哪张，不删除已有表或记录。已启用站点覆盖升级后刷新页面即可补建；无需反复点击启用。数据库账号仍须具备 CREATE、INDEX 权限（登录记录插件还需插件表之间的 REFERENCES 权限）。若补建失败，请查看网站日志中的首个建表异常（例如外键、表引擎或权限），不要只依据后续的“表不存在”错误判断。

此修复版不创建指向宿主 users.uid 的外键，避免 MariaDB errno 150。账号通过网站正常删除时，由用户模型删除事件清理关联插件数据；插件停用时或绕过模型直接执行 SQL 删除账号，不触发此清理。插件自己的两张登录记录表之间仍使用外键级联。

已核对当前主系统安装迁移：users.uid 使用 increments，即 MariaDB INT UNSIGNED 自增主键；原插件 user_id 同为 unsignedInteger，源码定义的位数与符号一致。主系统 database.php 的 engine 为 null，建 users 表时也没有强制指定引擎。因此 errno 150 的实际原因需通过 SHOW CREATE TABLE users 和 SHOW TABLE STATUS 确认，不能仅凭异常认定为字段类型不一致。

已根据现场 SHOW CREATE TABLE 确认 users 使用 MyISAM，插件表使用 InnoDB，导致跨引擎外键创建失败。新版本取消该外键，并补建缺失索引（包含企业微信绑定唯一约束和授权状态唯一约束）；不会清空已有数据。若已有重复绑定导致唯一索引无法建立，会明确报错，不自动删除或合并绑定。
