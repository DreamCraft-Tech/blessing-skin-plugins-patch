# 自动检查与发布

推送 main、向 main 提交 Pull Request，以及手动运行 Actions 时，检查 PHP / Python / JavaScript 语法、发布工具测试、前端类型和测试、五个自定义插件的主站集成测试，然后验证全部插件的独立安装 ZIP。

每次运行均遍历 plugins/*/package.json 并构建全部插件，清单与源码插件集合必须完全一致。main 检查通过后，插件名称与版本清单发生变化时自动发布；首次运行自动发布当前全部插件。更新某个插件时修改其 package.json 版本号。Release 标题与标签由 Actions 按发布执行时的 GMT+8 日期生成，格式 YYYYMMDD-patch；同日多次发布依次使用 YYYYMMDD-patch-2、YYYYMMDD-patch-3。相同提交重试时继续上传已有草稿。

每次 Release 分别附上所有插件的独立 ZIP，以及 manifest.json、SHA256SUMS。每个 ZIP 对应一个插件，按站点需要单独选择下载。发布说明列出插件版本和对应下载链接。

版本清单保持不变时跳过重复发布；需要按同一版本清单重新构建发布时，在 Actions 手动运行工作流并勾选 force_release。发布先创建草稿，全部附件上传并核验完成后再正式公开。

检查使用固定提交的配套主站作为测试宿主，版本在工作流的第二个 checkout 步骤维护。生产依赖安装在构建目录内，自定义插件测试通过临时链接读取对应 vendor。Actions 内部构建产物供发布任务读取，Release 附件按插件分别提供。

GITHUB_TOKEN 由 GitHub 自动提供；检查任务仅有 contents:read，发布任务有 contents:write。PR 代码仅运行检查，main 才可发布。第三方 Actions 固定到提交 SHA。

## 客户端更新源

主站 GitHub Release 备用地址：`https://github.com/DreamCraft-Tech/blessing-skin-server-patch/releases/latest/download/update.json`。使用原版 spec 2 协议，包含最低 PHP 版本、最新主站版本、完整运行包 URL 和 SHA-256。客户端校验后进入确认安装。

插件 GitHub Release 备用地址：`https://github.com/DreamCraft-Tech/blessing-skin-plugins-patch/releases/latest/download/registry_{lang}.json`。使用原版 version 1 / packages 协议，提供 en 与 zh_CN 的插件名称、描述、依赖与各自 ZIP 下载地址。索引和安装包都是公开 Release 附件，可直接通过 HTTPS 获取。

在“站点配置”的“更新与插件仓库”卡片保存地址后生效；留空时使用 config/app.php 与 config/plugins.php 的默认地址，UPDATE_SOURCE 和 PLUGINS_REGISTRY 环境变量可覆盖默认值。发布任务在全部 ZIP 校验后生成 JSON，并与所有安装包一起上传后公开。

## web-publish 与 GitHub Pages

Release 完成后下载已正式发布的附件并校验，再写入同仓库的 web-publish 分支。根目录提供最新 JSON、manifest.json、SHA256SUMS 与下载索引；releases/<标签>/ 保留各版本 ZIP 和对应 JSON。现有版本 ZIP 保持原字节及 SHA-256，历史下载路径继续保留。相同版本重试不创建重复分支提交。

主站 Pages：`https://skin.blessing.dreamcraft.eu.org/update.json`。
插件 Pages：`https://plugin.blessing.dreamcraft.eu.org/registry_{lang}.json`。
Pages JSON 中所有 ZIP 链接均指向对应 Pages 域名。GitHub Release 仍保留原有附件与 GitHub 下载索引。

仓库 Pages 设置使用 GitHub Actions：发布任务将 web-publish 的实际文件树打成 Pages artifact，再由独立 pages 任务部署。这样不依赖 GITHUB_TOKEN 推送触发分支构建。仅 main 正式发布路径可部署；PR 仅检查。发布任务使用同仓库 GITHUB_TOKEN 的 contents:write；部署任务仅有 pages:write、id-token:write，无需新增个人令牌。PAGES_BASE_URL 仓库变量可覆盖默认域名。

GitHub Pages 站点总大小上限为 1 GB，版本目录应按站点容量定期归档；完整历史安装包始终保留于 Releases。
