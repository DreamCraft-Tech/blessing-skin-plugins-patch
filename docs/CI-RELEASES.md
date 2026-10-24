# 自动检查与发布

推送 main、向 main 提交 Pull Request，以及手动运行 Actions 时，检查 PHP / Python / JavaScript 语法、发布工具测试、前端类型和测试、五个自定义插件的主站集成测试，然后验证全部插件的独立安装 ZIP。

每次运行均遍历 plugins/*/package.json 并构建全部插件，清单与源码插件集合必须完全一致。main 检查通过后，插件名称与版本清单发生变化时自动发布；首次运行自动发布当前全部插件。更新某个插件时修改其 package.json 版本号。Release 标题与标签由 Actions 按发布执行时的 GMT+8 日期生成，格式 YYYYMMDD-patch；同日多次发布依次使用 YYYYMMDD-patch-2、YYYYMMDD-patch-3。相同提交重试时继续上传已有草稿。

每次 Release 分别附上所有插件的独立 ZIP，以及 manifest.json、SHA256SUMS。每个 ZIP 对应一个插件，按站点需要单独选择下载。发布说明列出插件版本和对应下载链接。

版本清单保持不变时跳过重复发布；需要按同一版本清单重新构建发布时，在 Actions 手动运行工作流并勾选 force_release。发布先创建草稿，全部附件上传并核验完成后再正式公开。

检查使用固定提交的配套主站作为测试宿主，版本在工作流的第二个 checkout 步骤维护。生产依赖安装在构建目录内，自定义插件测试通过临时链接读取对应 vendor。Actions 内部构建产物供发布任务读取，Release 附件按插件分别提供。

GITHUB_TOKEN 由 GitHub 自动提供；检查任务仅有 contents:read，发布任务有 contents:write。PR 代码仅运行检查，main 才可发布。第三方 Actions 固定到提交 SHA。
