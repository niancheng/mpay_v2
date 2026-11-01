# 支付宝门店账单收款 · store-bill-1.0.0

代码基线：niancheng/mpay_v2 master `884a06193d0059288437cba788f169d1ceb747fe`。配套 niancheng/receipt-watcher >=0.2.0（store_receipt_protocol=1）。本插件不是自动轮换收款码或自动补单工具。

## 配置

插件编码 `alipay_store_bill_receipt`；中文名“支付宝门店账单收款”。沿用 AppID、应用私钥、支付宝公钥、收款 UID、识别有效期、最大金额偏移、查询间隔和二维码内容/图片配置；增加必填 `store_no`，填写完整编号，例如 `店铺1793551172076`。默认有效期300秒、偏移99分、间隔3秒；偏移设0就是原金额。后台不显示模式选择，后端拒绝 remark/alipay_transfer。

查询 `alipay.data.bill.sell.query` 只传门店、创建时间窗口、分页，不传 bill_user_id；AppID/授权对应的实际账号必须由部署者确认。store_no 上游过滤是模糊匹配，两端必须精确比较返回门店。缺失 store_no 的“经营收款单”不支持自动认款。一个门店多张码不是多个独立匹配范围。

创建每个门店一份配置，通道引用它，放入现有轮询组即可。不同门店允许同金额待付；同门店跨通道共用金额。没有空闲金额时明确失败，不自动换门店或排队。多个通道可引用同一配置；同 UID 同店不可启用重复配置。同 UID 不能混用旧账号级插件与新门店插件，需先处理旧范围所有未结束订单（含已超时但尚未推进终态的记录）。

## 一致性与保护

- 分配前进行短数据库互斥事务（持久 singleton guard），防止配置切换、并发金额分配及最终认款互相穿透；事务内无支付宝网络请求。该保守实现会串行化支付宝相关短写事务，应按实际订单量评估吞吐。
- 只计算已提交的门店识别金额占用；未完成分配的订单不进入监听快照。重复发起不会换金额、换二维码或延长有效期。
- 订单保留 UID、门店、原金额、识别金额、请求/过期时间和二维码快照。有未结束订单时禁止修改身份、门店、模式和二维码。已有支付记录的相关通道只允许停用、不允许删除，避免失去历史引用。
- 仅成功、精确门店、完整时间、正金额、明确零退款和零商家优惠可匹配。按 total_amount 匹配，不扣服务费。付款后恢复业务原金额，实付识别金额单独记录。
- watcher 的 candidate_pay_no/channel_id 只是提示，PHP 必须独立匹配唯一订单。通过实际订单通道处理，不能把同配置所有流水投给第一条通道。新插件拒绝未签名公网 HTTP 认款。
- `ma_alipay_receipt_flow` 永久记录 UID+支付宝交易号到支付单的唯一归属，并限制一笔支付单一条流水。新插件在同一事务内完成归属、金额还原、状态/资金动作与回调日志，提交后才触发成功事件。旧插件增加共享流水归属保护，匹配模式和通知结构不变。
- 库中归属记录不等于付款成功（旧链路失败时可能保留待重试占位）；付款以支付单终态、业务单及后续回调状态为准。不删除归属记录来“重试”，人工处理必须核对资金事实。
- 原始账号级查询/备注/免输转账保留；不能用旧 watcher 监听新门店任务。

本地过期不能令静态二维码失效；旧码迟到付款存在歧义。首版不自动确认过期订单、不全量补历史账单、不监控成功后的退款。真实小额付款和下游商户回调必须由部署环境验收。

## Docker 部署及升级

镜像：`ghcr.io/niancheng/mpay_v2:store-bill-1.0.0`，版本与 SHA 标签由 CI 成功发布后生效。HTTP 8787，示例 Compose 仅绑定127.0.0.1，建议经过既有反向代理。复用原 MySQL、Redis、`.env`、runtime 和 public/storage；不要重新初始化业务数据库。镜像不包含密钥，启动不会自动迁移、覆盖环境文件或伪造 install.lock。

升级顺序：

1. 暂停新单并处理旧范围未结束订单；停原 watcher 和 MPAY 全部进程/容器。
2. 备份数据库、真实 `.env`、runtime（含 install.lock）和 storage。记录旧镜像 digest；验证备份可恢复。
3. 使用新镜像，在原配置和原网络下执行迁移。新增两张保护表并注册新插件，不改写既有订单或余额。必须迁移后才启动新版本，原支付宝插件也依赖新保护表。
4. 启动 MPAY，再创建门店配置及对应二维码。在监听插件列表添加 `alipay_store_bill_receipt`。
5. 使用 watcher0.2.0 先observe验证，再publish。只有门店插件时可留空 WATCHER_ALLOWED_BILL_TYPES；旧插件仍需配置。
6. 核对支付单、业务单、回调日志和商户回调任务，不将 watcher“入队”日志当支付完成。

在正确放好 Compose/.env 和原有数据目录之后，示例命令：

```sh
docker compose pull mpay
docker compose run --rm mpay php webman migrate
docker compose up -d mpay
docker compose logs --tail=100 mpay
```

新部署先复制 `.env.example` 为真实文件，填好配置；设置 MPAY_NETWORK 为现有 Docker 网络。原站升级必须映射原 runtime/install.lock，不能因为空目录而重新安装。配置含密码时不要把 `docker compose config` 展开的结果公开；可用 `config --quiet` 校验。镜像保留原安装器能力，容器默认root运行；生产可在确认目录和环境文件写权限后配置非root user。

回滚：先停 watcher/新单/新MPAY，处理已入队消息；新插件有未结束或待人工处理交易时不要直接启用同 UID 旧插件。保留新增数据库表及所有流水归属，不执行删除表的 down；代码回滚到不懂门店协议的版本前必须停用新插件并确认不会再处理新格式消息。备份恢复只能作为经过资金对账的运维操作，不能自动丢弃升级后支付事实。

## 测试

```sh
composer install --no-interaction --prefer-dist --no-scripts
php tests/store_rules.php
# 必须使用独立空测试库；禁止生产库。
DB_DATABASE=mpay_test_local MPAY_TEST_DATABASE=mpay_test_local php tests/store_integration.php
DB_DATABASE=mpay_test_local MPAY_TEST_DATABASE=mpay_test_local php tests/store_concurrency.php
```

CI使用 PHP8.3/8.4、MySQL8.0和Redis7，测试迁移幂等、配置冻结、金额隔离、通知事务/回滚、重复归属、正式流水消费任务及多进程竞争；随后构建镜像并检查安装页。所有交易是合成测试证据，不使用生产支付宝密钥或发起真实支付。
