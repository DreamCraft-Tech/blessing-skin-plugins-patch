# 每日人品 1.0.0

在 Blessing Skin 用户仪表板首页插入“每日人品”卡片。每位用户每天获得 0–100 分的结果，以及一句 Minecraft 风格短评；0 分和 100 分有独立彩蛋文案。

## 安装与配置

适用当前 Blessing Skin 6.x PHP 8.5 分支及 Laravel 10。

1. 上传 `daily-luck_1.0.0_php85.zip` 并启用，或将包内 `daily-luck/` 解压至网站 `plugins/`。
2. 登录用户仪表板，卡片默认位于右栏公告下方，点击“查看今日人品”展开结果。
3. 在“插件配置 → 每日人品”调整卡片标题、左栏或右栏位置，以及“点击查看 / 直接展示”。管理员可查看配置，超级管理员可保存。
4. 更新文件后，按站点 OPcache 配置刷新 PHP 运行环境。

日期采用站点 `app.timezone`，每日 00:00 切换；跨日后重新打开或刷新仪表板展示新日期。相同账号在同一天刷新、退出重登或更换设备，结果一致。展示文案以 Minecraft 冒险、建筑、种田等活动为主题。

## 技术实现

通过 `grid:user.index` 添加 Twig 卡片，沿用 AdminLTE / Bootstrap 的卡片、按钮和折叠组件。结果由站点 APP_KEY、用户 UID、站点日期进行 HMAC-SHA256 运算，映射至 0–100。修改 APP_KEY 会改变结果序列。页面以服务端日期生成，客户端时钟不参与计算。

插件配置写入主站 options；无额外数据库表、前端构建步骤或 Composer 依赖。用户仪表板响应设置为 `Cache-Control: no-store, private`。所有分数和短评均由本插件算法生成。

## 验证

源码包包含 PHPUnit 测试，使用主站开发依赖运行：

```sh
BLESSING_SERVER_PATH=/absolute/path/to/blessing-skin-server php /absolute/path/to/blessing-skin-server/vendor/bin/phpunit -c /absolute/path/to/daily-luck/phpunit.xml
```

覆盖同日稳定性、分值范围、多用户分布、站点时区跨日、彩蛋文案、仪表板集成、已有组件保留、位置与展示设置、权限、标题转义和 CSRF。

## 更新日志

### 1.0.0

- 仪表板每日人品卡片：分数、日期、短评与隐藏彩蛋。
- 支持左右位置、卡片标题和结果展示方式配置。

## 功能参考

PCL 今日人品：https://github.com/Meloong-Git/PCL/issues/8983
