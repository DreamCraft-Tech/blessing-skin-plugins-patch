# Blessing Skin 插件：PHP 8.5 安装包

来源：https://github.com/bs-community/blessing-skin-plugins  
提交：`de016f4cbb43df7bd6d46193d5625693f8c79b3f`，master 分支快照。构建日期：2026-10-23（Asia/Shanghai）。

本集合包含 **44 个独立插件 ZIP**，用于此前交付的 Blessing Skin Server 6.0.2 开发快照 / Laravel 10.48.25 / PHP 8.5 环境。这是本地适配构建，非官方发行；所有插件添加 `+php85.1` 版本标记及 `php: ~8.5.0` 要求。

PHP 代码以可部署源码形式交付；需要的前端资源已编译，需要的生产 Composer 依赖已打入对应插件，无需在服务器上运行前端构建。

## 安装到你现在的 1Panel 网站

1. 将总压缩包解压到本地，在 `packages/` 中选择需要的插件 ZIP。
2. 在 Blessing Skin 后台的插件管理页面上传该**单个插件 ZIP**，然后按需启用。不要把包含 44 个 ZIP 的外层集合包当作单个插件上传。
3. 也可以解压单个 ZIP，将其中同名插件目录放到程序的 `plugins/` 目录（如果设置了 `PLUGINS_DIR`，则使用该实际目录）。已有同名插件时先备份旧目录；更新需要替换整个旧目录，避免残留旧 `vendor/` 文件。
4. 配置仍按各插件内的 README 填写到网站 `.env`；修改后使用网站实际 PHP 8.5 运行环境执行 `php artisan config:clear`。
5. 如果插件提示缺少依赖，先安装并启用下表列出的前置插件。

**不要一次启用全部插件。** `yggdrasil-api` 与 `yggdrasil-connect` 互斥；阿里云 OSS、腾讯云 COS、七牛云三个材质存储插件都接管 `textures` 磁盘，也应按实际需求选择一个。`insider` 会改变服务端更新源，只有需要预览版更新时再启用。

FastLogin / AuthMe 对接请继续按插件 README 配置游戏端数据库，MariaDB 对应的驱动名仍是 `mysql`，PHP 环境需要 `pdo_mysql`。本构建未连接你的真实数据库。

## 适配内容

- 所有插件要求 PHP 8.5，并保留原有服务端版本要求。
- OAuth 系列的 `psr/log` 约束允许 PSR Log 3，与当前服务端兼容；锁定依赖并按此前服务端锁文件提供共享依赖，避免捆绑另一份 Laravel / Flysystem / Symfony。
- 代理信任改用 Laravel 10 自带的中间件与当前转发头常量，移除旧 Fideloper 依赖。保留上游“信任所有代理”的行为，适用于现有可信代理部署。
- 阿里云 OSS 改用 `alphasnow/aliyun-oss-laravel 4.9.0` 及 Flysystem 3 适配器；保留 `OSS_ACCESS_ID`、`OSS_ACCESS_KEY`、`OSS_BUCKET`、`OSS_ENDPOINT`、`OSS_SSL`、`OSS_CDN_DOMAIN`、`OSS_IS_CNAME` 配置项。
- 腾讯云 COS 改用 `overtrue/laravel-filesystem-cos 3.6.0` / `overtrue/flysystem-cos 5.2.1`，兼容当前 Flysystem 3；保留账号、区域、Bucket、CDN、协议与超时配置。`COS_BUCKET` 填写不带 `-APP_ID` 后缀的桶名。`COS_CDN` 用于生成访问 URL；旧 `COS_READ_FROM_CDN` 不再参与 SDK 读取，SDK 读取使用 COS API。
- 七牛云适配器更新为 `zgldh/qiniu-laravel-storage 9.0.4`；对 `qiniu/php-sdk 7.14.0` 的 5 个参数添加显式可空类型，消除 PHP 8.5 弃用提示，补丁位于源码包 `patches/php85/`。
- Predis 更新至 Laravel 10 支持的 2.4.1。
- FastLogin 依赖对齐正版验证 2.x，并监听 `user.mojang-ms.verified`，兼容新版 profile 数据结构。
- 材质描述编辑器修正 Svelte 生命周期清理函数注册，避免运行时错误。

## 验证与边界

- PHP 8.5.12 下对插件及附带 vendor 的 1608 个 PHP 文件执行语法检查，无语法错误或解析期弃用提示。
- 44/44 插件加载检查通过；包含所需插件的依赖加载、插件自身类加载及相关初始化。
- 所有插件清单中的 PHP、服务端和插件依赖版本约束均匹配本次集合。
- 9 个有 Composer 依赖的插件均通过生产平台要求、锁文件验证及新增依赖审计。本次插件依赖未发现安全公告；共享服务端原有的安全限制未在本次解决，见之前的服务端构建说明。
- 前端生产构建成功，TypeScript 与 Svelte 检查 0 错误 / 0 警告；2 个测试套件、8 项测试全部通过。
- OAuth 系列及正版验证 / FastLogin 组合加载通过；FastLogin 验证事件已在 SQLite 模拟表中成功写入记录。
- 代理信任验证了转发后的客户端 IP、HTTPS 与主机名。
- 三个云存储适配器完成离线初始化、Flysystem 3 接口检查及 URL 生成验证。

**这些检查不等于全部业务端到端测试。** 未使用实际云存储凭据进行上传下载，未连接 Redis、游戏服务器或 MariaDB，也未执行真实 OAuth / 微软正版验证流程。AuthMe 的数据库初始化在 SQLite 中验证；实际 MariaDB 的表结构和权限仍需部署时确认。

构建时使用 Node.js 24.22.1、pnpm 8.15.9、Composer 2.10.3。验证记录见 `blessing-skin-plugins-php85-verification.zip`。

## 源码与复现

源码包包含修改后的插件源码、前端锁文件、9 套生产依赖构建清单与锁文件、服务端依赖锁文件快照和七牛 SDK 补丁。

准备 PHP 8.5、Composer、Node.js、pnpm 8.15.9、Python 3 及 `patch` 后，在源码根目录执行：

```sh
python3 tools/build-php85.py --php /path/to/php --composer /path/to/composer --output /path/to/new-build-directory
```

输出路径须不存在。共享依赖由 `build-locks/server-composer.lock` 记录的服务端提供；不要把这些插件独立用于另一套不匹配的框架依赖。重建脚本复现依赖安装、前端构建和语法检查；本次宿主应用集成验证脚本另保存在验证压缩包中。

## 插件索引

| 插件 | 构建版本 | 前置插件 |
| --- | --- | --- |
| 204-for-unexisted-players | 0.1.4+php85.1 | — |
| authme-integration | 2.4.3+php85.1 | single-player-limit ^3.0.0 |
| batch-import | 4.1.2+php85.1 | — |
| config-generator | 3.2.2+php85.1 | — |
| disable-registration | 1.0.3+php85.1 | — |
| embedded-comment | 1.0.4+php85.1 | — |
| eruda | 1.0.1+php85.1 | — |
| fast-login | 0.1.3+php85.1 | mojang-verification ^2.0 |
| forum-integration | 4.1.1+php85.1 | single-player-limit >=2.0.0 |
| google-adsense | 0.0.1+php85.1 | — |
| gravatar | 0.1.3+php85.1 | — |
| gtag-js | 0.1.2+php85.1 | — |
| hello-dolly | 1.3.0+php85.1 | — |
| hide-advanced-menu | 1.0.1+php85.1 | — |
| hitokoto | 1.3.0+php85.1 | — |
| insane-profile-cache | 4.0.3+php85.1 | — |
| insider | 1.0.2+php85.1 | — |
| invitation-codes | 3.1.2+php85.1 | — |
| legacy-api | 1.1.3+php85.1 | — |
| mojang-verification | 2.0.0+php85.1 | oauth ^1.0.0 |
| oauth | 1.0.6+php85.1 | — |
| oauth-github | 0.2.5+php85.1 | oauth ^1.0.0 |
| oauth-google | 0.1.5+php85.1 | oauth ^1.0.0 |
| oauth-littleskin | 0.2.6+php85.1 | oauth ^1.0.0 |
| oauth-microsoft-live | 0.2.6+php85.1 | oauth ^1.0.0 |
| password-algorithm-transition | 2.2.1+php85.1 | — |
| pray-for-kyoto-animation | 1.0.3+php85.1 | — |
| predis | 1.0.1+php85.1 | — |
| purge-azure-global-cdn | 1.1.2+php85.1 | — |
| refresh-ali-cdn | 1.0.1+php85.1 | — |
| refresh-qcloud-cdn | 2.1.3+php85.1 | — |
| remote-addr | 0.1.1+php85.1 | — |
| restricted-email-domains | 0.3.0+php85.1 | — |
| share-registration-link | 2.0.2+php85.1 | — |
| single-player-limit | 3.0.4+php85.1 | — |
| texture-description | 1.3.2+php85.1 | — |
| texture-type-cast | 0.4.0+php85.1 | — |
| textures-aliyun-oss | 2.0.6+php85.1 | — |
| textures-qcloud-cos | 2.0.5+php85.1 | — |
| textures-qiniu-storage | 2.0.5+php85.1 | — |
| trust-proxies | 0.1.3+php85.1 | — |
| usm-api | 1.2.3+php85.1 | — |
| yggdrasil-api | 5.2.1+php85.1 | — |
| yggdrasil-connect | 6.2.0+php85.1 | — |
