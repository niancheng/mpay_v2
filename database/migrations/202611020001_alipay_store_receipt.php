<?php

/** Additive migration. No existing orders, balances or receipt caches are rewritten. */
return new class {
    public string $version = '202611020001';
    public string $name = 'alipay_store_receipt';

    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ma_alipay_receipt_guard (id TINYINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB");
        $pdo->exec("INSERT IGNORE INTO ma_alipay_receipt_guard (id) VALUES (1)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS ma_alipay_receipt_flow (
            bill_user_id VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            trade_no VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            pay_no VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            store_no VARCHAR(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
            receipt_amount BIGINT UNSIGNED NOT NULL,
            paid_at BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (bill_user_id, trade_no), UNIQUE KEY uk_pay_no (pay_no)
        ) ENGINE=InnoDB");
        $plugin = new \app\common\payment\AlipayStoreBillReceiptPayment(
            new \app\repository\payment\trade\PayOrderRepository(),
            new \app\repository\payment\config\PaymentChannelRepository(),
            new \app\repository\payment\config\PaymentTypeRepository()
        );
        $statement = $pdo->prepare('INSERT INTO ma_payment_plugin (code, name, class_name, plugin_type, config_schema, pay_types, transfer_types, version, author, link, status, allow_merchant, remark, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name), class_name=VALUES(class_name), config_schema=VALUES(config_schema), version=VALUES(version), updated_at=NOW()');
        $statement->execute([$plugin->getCode(), $plugin->getName(), 'AlipayStoreBillReceiptPayment', $plugin->getPluginType(),
            json_encode($plugin->getConfigSchema(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), '["alipay"]', '[]', $plugin->getVersion(), $plugin->getAuthorName(), '', '仅金额匹配；须配套 receipt-watcher 0.2.0 或更高版本']);
    }

    public function down(\PDO $pdo): void
    {
        throw new \RuntimeException('财务流水归属表不能自动删除；回滚应用前停用新插件，保留此迁移及去重证据。');
    }
};
