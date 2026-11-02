# 提交与镜像发布流程（方案 A）

源码和 `.github/workflows/` 由开发侧通过已授权的仓库连接直接提交。CI 只检出已提交源码，执行测试、构建和发布，不应用传输补丁、不创建提交、不执行 `git push`，也不修改或删除工作流。

- 开发分支及 pull request 执行 PHP 8.3/8.4、真实 MySQL 集成与并发测试、容器安装页冒烟测试及 Compose 配置检查。
- `master` 的测试全部成功后，同一工作流内通过 `needs: test` 进入 GHCR 发布；不依赖机器人推送触发第二个工作流。
- 默认及测试任务仅使用 `contents: read`；发布任务额外使用 `packages: write`。所有 checkout 均设置 `persist-credentials: false`。
- 不需要新增 PAT、自建 GitHub App 私钥或 `Workflows` 授权。镜像发布使用内置 `GITHUB_TOKEN`。
- 发布 `VERSION`、`latest` 和 `sha-<提交前12位>` 三种镜像标签，包含 amd64/arm64、provenance 和 SBOM；发布后检查 manifest 并记录 digest。
- `.dev/` 和临时 `dev-apply`、源码导出、依赖导出工作流已从交付源码树移除。

自动化测试使用合成付款证据，不代表真实支付宝付款联调已经完成。
