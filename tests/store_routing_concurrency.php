<?php

declare(strict_types=1);
require __DIR__ . '/store_routing_helpers.php';

use app\common\constant\RouteConstant;
use app\exception\ConflictException;
use app\exception\ReceiptAmountOccupiedException;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantAccount;
use app\model\merchant\MerchantGroup;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\PaymentType;
use app\service\payment\runtime\PaymentRouteService;

// Each independent process finishes its read-only probe before the parent opens the barrier.
// This deterministically exercises the check/reserve race, not just sequential prechecks.
if (($argv[1] ?? '') === 'child') {
    [, , $merchantId, $orderNo, $directory, $index] = $argv;
    try {
        $merchant = Merchant::findOrFail((int) $merchantId);
        $fixture = ['merchant' => $merchant, 'group' => MerchantGroup::findOrFail($merchant->group_id),
            'type_id' => (int) PaymentType::where('code', 'alipay')->value('id')];
        $snapshot = routingSnapshot($fixture);
        file_put_contents($directory . '/ready-' . $index, 'ready');
        $deadline = microtime(true) + 45;
        while (!is_file($directory . '/go')) {
            if (microtime(true) > $deadline) throw new RuntimeException('Timed out waiting for parent barrier');
            usleep(10000);
        }
        $result = routingAttempts([PaymentRouteService::class => new FrozenReceiptRoute($snapshot)])
            ->preparePayAttempt(routingInput($fixture, $orderNo));
        $outcome = ['status' => 'accepted', 'pay_no' => (string) $result['pay_order']->pay_no,
            'channel_id' => (int) $result['pay_order']->channel_id, 'amount' => (int) $result['pay_order']->pay_amount];
    } catch (ReceiptAmountOccupiedException $e) {
        $outcome = ['status' => 'occupied', 'message' => $e->getMessage()];
    } catch (ConflictException $e) {
        $outcome = ['status' => 'conflict', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        $outcome = ['status' => 'unexpected', 'class' => $e::class, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
    }
    $json = json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    file_put_contents($directory . '/result-' . $index . '.tmp', $json);
    rename($directory . '/result-' . $index . '.tmp', $directory . '/result-' . $index . '.json');
    exit($outcome['status'] === 'unexpected' ? 1 : 0);
}

function concurrentRoutes(array $fixture, int $count, bool $sameOrder = false): array
{
    $directory = sys_get_temp_dir() . '/mpay-route-' . bin2hex(random_bytes(8));
    if (!mkdir($directory, 0700)) throw new RuntimeException('Cannot create test barrier directory');
    $processes = [];
    try {
        $prefix = 'CONCURRENT-ROUTE-' . bin2hex(random_bytes(5));
        for ($i = 0; $i < $count; ++$i) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, 'child', (string) $fixture['merchant']->id,
                $prefix . ($sameOrder ? '' : '-' . $i), $directory, (string) $i],
                [0 => ['pipe', 'r'], 1 => ['file', $directory . '/stdout-' . $i, 'w'],
                    2 => ['file', $directory . '/stderr-' . $i, 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('proc_open failed');
            fclose($pipes[0]);
            $processes[] = $process;
        }
        $deadline = microtime(true) + 30;
        while (count(glob($directory . '/ready-*')) !== $count) {
            foreach (glob($directory . '/result-*.json') as $file) throw new RuntimeException('Child failed before barrier: ' . file_get_contents($file));
            if (microtime(true) > $deadline) throw new RuntimeException('Children failed to finish prechecks');
            usleep(10000);
        }
        touch($directory . '/go');
        $deadline = microtime(true) + 60;
        while (count(glob($directory . '/result-*.json')) !== $count) {
            if (microtime(true) > $deadline) throw new RuntimeException('Concurrent route requests exceeded 60-second test deadline');
            usleep(10000);
        }
        $outcomes = [];
        foreach ($processes as $i => $process) {
            $code = proc_close($process);
            unset($processes[$i]);
            $outcome = json_decode(file_get_contents($directory . '/result-' . $i . '.json'), true, flags: JSON_THROW_ON_ERROR);
            if ($code !== 0 || $outcome['status'] === 'unexpected') {
                throw new RuntimeException('Unexpected child result: ' . json_encode($outcome, JSON_UNESCAPED_UNICODE)
                    . file_get_contents($directory . '/stderr-' . $i));
            }
            $outcomes[] = $outcome;
        }
        return $outcomes;
    } finally {
        foreach ($processes as $process) {
            if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        }
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
}

$f = routingFixture(3, 1, RouteConstant::ROUTE_MODE_AMOUNT_PRIORITY, self: true);
$before = routingCounts();
$outcomes = concurrentRoutes($f, 6);
check(array_count_values(array_column($outcomes, 'status')) === ['accepted' => 6], '六个进程先完成预检，再并发向三家门店申请，均在原金额或一分偏移内成功');
$pairs = array_map(fn ($r) => $r['channel_id'] . ':' . $r['amount'], $outcomes);
check(count(array_unique($pairs)) === 6, '同一门店同一有效识别金额只分配一次');
foreach ($f['channels'] as $channel) {
    $amounts = PayOrder::where('channel_id', $channel->id)->orderBy('pay_amount')->pluck('pay_amount')->map(fn ($v) => (int) $v)->all();
    check($amounts === [10000, 10001], '每家门店只占用原金额和配置允许的一分偏移');
}
$orders = BizOrder::where('merchant_id', $f['merchant']->id)->get();
check($orders->count() === 6 && $orders->every(fn ($o) => (int) $o->attempt_count === 1 && $o->active_pay_no !== ''), '并发换店不增加业务订单尝试次数');
$after = routingCounts(); $account = MerchantAccount::where('merchant_id', $f['merchant']->id)->firstOrFail();
check($after[1] - $before[1] === 6 && $after[2] - $before[2] === 6 && $after[3] - $before[3] === 6
    && (int) $account->available_balance === 997000 && (int) $account->frozen_balance === 3000, '并发冲突回滚后只有六份支付单及服务费冻结');

$f = routingFixture(2, 0, self: true); $before = routingCounts();
$outcomes = concurrentRoutes($f, 6); $statuses = array_count_values(array_column($outcomes, 'status'));
check(($statuses['accepted'] ?? 0) === 2 && ($statuses['occupied'] ?? 0) === 4, '零偏移两家门店面对六个并发请求，恰好两笔成功、四笔明确占用');
$after = routingCounts(); $account = MerchantAccount::where('merchant_id', $f['merchant']->id)->firstOrFail();
check($after[0] - $before[0] === 2 && $after[1] - $before[1] === 2 && $after[2] - $before[2] === 2 && $after[3] - $before[3] === 2
    && (int) $account->available_balance === 999000 && (int) $account->frozen_balance === 1000, '最终占用失败的四个请求没有业务单、支付单或资金残留');

$f = routingFixture(2, 0, self: true); $before = routingCounts();
$outcomes = concurrentRoutes($f, 2, sameOrder: true); $statuses = array_count_values(array_column($outcomes, 'status'));
check(($statuses['accepted'] ?? 0) === 2 && count(array_unique(array_column($outcomes, 'pay_no'))) === 1, '相同商户订单号并发恢复同一支付单，只创建一笔支付尝试');
$after = routingCounts();
check($after[0] - $before[0] === 1 && $after[1] - $before[1] === 1 && $after[2] - $before[2] === 1 && $after[3] - $before[3] === 1, '重复请求不会在第二家门店创建第二份占用或冻结');
echo "PASS: $checks full-route cross-process MySQL concurrency checks\n";
