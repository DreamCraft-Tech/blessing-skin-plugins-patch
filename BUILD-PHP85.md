# DreamCraft Blessing Skin Plugins Patch

Blessing Skin PHP 8.5 插件集合，基于上游 bs-community/blessing-skin-plugins 的 `de016f4`，适用于配套 Laravel 10 / PHP 8.5 主站分支。

## 自定义插件

| 目录 | 当前版本 | 功能 |
| --- | --- | --- |
| plugins/wecom-login | 1.5.2 | 企业微信登录、绑定及登录通知 |
| plugins/login-history | 1.2.0 | 用户与管理员登录、认证和入服记录 |
| plugins/two-factor | 1.0.0 | TOTP、通行密钥及两步验证策略 |
| plugins/cdn-real-ip | 1.0.0 | 多厂商 CDN 客户端 IP 与协议识别 |
| plugins/daily-luck | 1.2.2 | 今日人品与千万别点 |

plugins/ 同时保留适配 PHP 8.5 的上游插件。每个插件只保留一个源码目录，历史版本按原始交付顺序形成独立签名 commit；逐文件 History 和 commit diff 展示每次改动。版本标签留待后续 Actions 发布流程生成。

## 构建与测试

环境：PHP 8.5、Composer、Node.js 24、pnpm 8.15.9、Python 3 和 patch。

```sh
python3 tools/build-php85.py --php /path/to/php --composer /path/to/composer --output /absolute/path/to/new-build
```

构建输出位于 packages/，每个插件为独立可安装 ZIP。生产 Composer 依赖由锁文件与构建配方安装，前端从源码构建。服务端共享依赖由 build-locks/server-composer.lock 记录；各插件的配置和安装说明见对应目录 README.md。

单个自定义插件含 composer.json 时，先在其目录运行 composer install。测试使用主站开发依赖，命令见对应插件 README.md。daily-luck 的交互测试：node --test plugins/daily-luck/tests/prank.test.cjs。

## 历史来源

上游完整 Git 历史保留。本地适配及自定义插件的发布版本由已有源码 ZIP 重建，提交日期记录 2026-10-24 的重建时间，各版本内容取自对应交付快照。版本提交与源码包校验值见 docs/reconstructed-history.json。最后的维护提交补齐本仓库构建与说明。

上游仓库：https://github.com/bs-community/blessing-skin-plugins 。保留原插件作者和各自许可证。

