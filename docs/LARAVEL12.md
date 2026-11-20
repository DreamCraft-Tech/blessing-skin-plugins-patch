# Laravel 12 / JWT 5 兼容更新 · 2026-11-20

- Yggdrasil API 5.2.2+php85.1 保留不可变 JWT 构建器返回值，令牌包含正确用户与选中角色声明。
- Yggdrasil Connect 6.2.1+php85.1 同步适配不可变构建器、LooseValidAt 时间校验、Carbon 3 整数时长，密钥来源遵循 Passport 配置和 keyPath。
- 登录记录集成测试覆盖 API 认证、刷新、角色选择、入服，以及 Connect 真实 Passport 签发、刷新、角色选择和撤销。
- 五个自定义插件在主站 Laravel 12.73.0 / PHP 8.5.12 完成 221 项 PHP 测试；今日人品另有 JavaScript 交互测试。
- CI 校验每个测试套件的 JUnit 报告完整性，避免旧插件 exit 提前终止测试被误判为成功。

两种 Yggdrasil 实现继续单独下载，使用所选的一种实现。
