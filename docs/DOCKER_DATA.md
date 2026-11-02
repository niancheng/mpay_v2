# store-bill-1.0.1：兼容作者 v2.1.2 的 /data 持久化布局

本补丁只修复镜像入口和部署兼容，不修改 JWT 算法、登录密钥要求、账单匹配或数据库结构。无需升级 receipt-watcher，也不需要新增 JWT 环境变量。

## 原部署如何升级

保留原来的 Compose、MySQL、Redis、environment、端口、网络与 extra_hosts，只更新 `MPAY_IMAGE`：

```dotenv
MPAY_IMAGE=ghcr.io/niancheng/mpay_v2:store-bill-1.0.1
```

原来的单卷挂载继续使用：

```yaml
services:
  mpay-backend:
    image: ${MPAY_IMAGE:-ghcr.io/niancheng/mpay_v2:store-bill-1.0.1}
    volumes:
      - /home/admin/data/mpay/mpay_data:/data
```

这只是需要核对的片段，不是完整 Compose，不要用它覆盖其他服务。保留 `EPAY_PLATFORM_KEY_DIR: /data/config`。未设置时新入口也会在 /data 模式下提供相同默认值；显式自定义值不会被替换。

暂停新单，备份 MySQL 和整个原数据目录，并记录旧镜像。若已在 1.0.0 的容器层产生新的上传、日志或配置，重建前单独导出留存，不要覆盖原目录。然后在原 Compose 目录执行：

```sh
docker compose config --quiet
docker compose pull mpay-backend
docker compose up -d --no-deps --force-recreate mpay-backend
docker compose logs --tail=100 mpay-backend
```

此补丁相对 store-bill-1.0.0 无新数据库迁移。如果直接从作者 2.1.2 首次升级门店插件，仍须先按 `ALIPAY_STORE_RECEIPT.md` 停止旧进程、备份并执行既有门店迁移（服务名改用 mpay-backend）。不要重复安装或删除 install.lock。

## 自动接入规则

| 持久化位置 | 应用位置 |
|---|---|
| /data/config/.env | /app/.env |
| /data/runtime | /app/runtime |
| /data/public-storage（或实际使用的 /data/public/storage） | /app/public/storage |
| /data/config/epay-platform-*.pem | 通过 EPAY_PLATFORM_KEY_DIR 读取 |

入口只在应用目录创建软链接，不执行 .env、不改写原文件、不生成或轮换 JWT/RSA、不自动安装或迁移数据库、不递归修改权限。重启、重建使用同一份持久化配置。首次安装空卷只创建权限 0600 的空 .env，由原安装器负责生成和写入密钥。

/data/public/storage 是指向 public-storage 的绝对或相对软链接时会正确识别；两处为独立目录时优先唯一非空目录；两处都非空则停止并提示人工确认，不自动合并。只接入 storage，不接入整个 /data/public，避免旧后台和收银台覆盖新镜像前端。

已安装数据缺失或包含空 .env、损坏软链接、应用位置已有不同配置或非空未挂载目录时，启动会明确失败，而不是丢弃数据或用新密钥掩盖问题。日志不打印配置内容、JWT 或私钥。

没有 /data 时继续兼容原先的 /app/.env、/app/runtime、/app/public/storage 分别挂载。两种方式混用时，显式 /app 挂载优先（包括只读 .env），入口不移动或删除挂载点。请确保显式挂载本身指向正确原文件；不能用错误或空的 /app/.env 覆盖正确 /data 配置。

## 回归检查

- `php tests/docker_data_layout.php`：纯文件系统场景，包括幂等、冲突保护、软链接与显式挂载。
- `bash tests/docker_data_smoke.sh mpay:ci`：真实容器只挂 /data，验证配置与管理员/商户 JWT 长度和签名，保留 RSA/安装锁/上传/日志哈希，容器重建及只读显式挂载。
- 发布后通过镜像 digest 对 linux/amd64 和 linux/arm64 重跑数据升级检查。

测试使用专门生成的目录和合成凭证，不连接生产数据库、不使用真实支付宝资料。实际部署仍需检查后台登录、旧文件可见性与正常收款回调。
