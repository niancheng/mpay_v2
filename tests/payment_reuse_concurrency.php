<?php

declare(strict_types=1);
require __DIR__ . '/store_routing_helpers.php';

use app\exception\ConflictException;
use app\exception\ValidationException;
use app\model\merchant\Merchant;
use app\model\merchant\MerchantAccount;
use app\model\merchant\MerchantGroup;
use app\model\payment\BizOrder;
use app\model\payment\PayOrder;
use app\model\payment\PaymentChannel;
use app\model\payment\PaymentType;
use app\service\payment\identity\PaymentIdentityService;
use app\service\payment\order\PayOrderChannelDispatchService;
use app\service\payment\runtime\PaymentRouteService;
use support\Container;
use support\Db;

function reuseAwait(callable $condition, string $label, int $seconds = 45): void
{
    $deadline = microtime(true) + $seconds;
    while (!$condition()) {
        if (microtime(true) > $deadline) throw new RuntimeException('Timed out: ' . $label);
        usleep(10000);
    }
}
class ReuseBarrierRoute extends PaymentRouteService
{
    public function __construct(private array $snapshot, private string $directory, private string $index) {}
    public function resolveByMerchantGroup(int $merchantGroupId, int $payTypeId, int $payAmount, array $context = []): array
    {
        // Invoked AFTER the original-order precheck. Every child must reach this point.
        file_put_contents($this->directory . '/ready-' . $this->index, 'ready');
        reuseAwait(fn () => is_file($this->directory . '/go'), 'creation barrier');
        return $this->snapshot;
    }
}
class ReuseNoNetworkIdentity extends PaymentIdentityService
{
    public function __construct() {}
    public function inspect(array $input, Merchant $merchant, PaymentChannel $channel, array $route): ?array { return null; }
}
class ReuseSlowDispatch extends PayOrderChannelDispatchService
{
    public function __construct(private string $directory, private string $mode) {}
    public function dispatch(PayOrder $payOrder, BizOrder $bizOrder, PaymentChannel $channel, Merchant $merchant): array
    {
        if (Db::connection()->transactionLevel() !== 0) throw new RuntimeException('Dispatch inside transaction');
        file_put_contents($this->directory . '/dispatch-count', $payOrder->pay_no . "\n", FILE_APPEND | LOCK_EX);
        touch($this->directory . '/dispatch-entered');
        reuseAwait(fn () => is_file($this->directory . '/release-dispatch'), 'dispatch release');
        if ($this->mode === 'store') {
            return Container::get(PayOrderChannelDispatchService::class)->dispatch($payOrder, $bizOrder, $channel, $merchant);
        }
        // No external gateway in the ordinary-channel transaction test.
        $presentation = ['pay_page' => 'qrcode', 'pay_params' => ['qrcode' => 'TEST-ONLY-CONCURRENT-QR']];
        $ext = $payOrder->ext_json; $ext['presentation'] = $presentation;
        $payOrder->ext_json = $ext; $payOrder->save();
        return ['pay_order' => $payOrder->fresh(), 'payment_result' => ['status' => 'pending', 'presentation' => $presentation],
            'pay_params' => $presentation['pay_params']];
    }
}

if (($argv[1] ?? '') === 'child') {
    [, , $merchantId, $channelId, $orderNo, $directory, $index, $mode, $amount] = $argv;
    try {
        $merchant = Merchant::findOrFail((int) $merchantId);
        $f = ['merchant' => $merchant, 'group' => MerchantGroup::findOrFail($merchant->group_id),
            'type_id' => (int) PaymentType::where('code', 'alipay')->value('id')];
        $channel = PaymentChannel::findOrFail((int) $channelId);
        $snapshot = $mode === 'store' ? routingSnapshot($f, (int) $amount) : [
            'poll_group' => null, 'route_mode' => 0,
            'selected_channel' => ['channel' => $channel, 'channel_id' => (int) $channel->id], 'candidates' => [],
        ];
        $deps = [PaymentRouteService::class => new ReuseBarrierRoute($snapshot, $directory, $index),
            PayOrderChannelDispatchService::class => new ReuseSlowDispatch($directory, $mode)];
        if ($mode === 'plain') $deps[PaymentIdentityService::class] = new ReuseNoNetworkIdentity();
        $result = routingAttempts($deps)->preparePayAttempt(routingInput($f, $orderNo, (int) $amount));
        $outcome = ['status' => 'accepted', 'pay_no' => (string) $result['pay_order']->pay_no,
            'created' => $result['created'], 'reused' => $result['reused'],
            'channel_id' => (int) $result['pay_order']->channel_id, 'amount' => (int) $result['pay_order']->pay_amount,
            'pay_page' => $result['payment_result']['presentation']['pay_page'] ?? ''];
    } catch (ConflictException|ValidationException $e) {
        $outcome = ['status' => 'conflict', 'message' => $e->getMessage()];
    } catch (Throwable $e) {
        $outcome = ['status' => 'unexpected', 'class' => $e::class, 'message' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
    }
    file_put_contents($directory . '/result-' . $index . '.tmp', json_encode($outcome, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    rename($directory . '/result-' . $index . '.tmp', $directory . '/result-' . $index . '.json');
    exit($outcome['status'] === 'unexpected' ? 1 : 0);
}

function reuseRace(string $mode, int $count, bool $conflictingAmount = false): void
{
    $f = routingFixture(2, 0, self: true);
    if ($mode === 'plain') {
        $f['channels'][0]->plugin_code = 'test_no_network'; $f['channels'][0]->save();
    }
    $directory = sys_get_temp_dir() . '/mpay-reuse-' . bin2hex(random_bytes(8)); mkdir($directory, 0700);
    $processes = []; $before = routingCounts(); $orderNo = 'REUSE-' . bin2hex(random_bytes(8));
    try {
        for ($i = 0; $i < $count; ++$i) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __FILE__, 'child', (string) $f['merchant']->id,
                (string) $f['channels'][0]->id, $orderNo, $directory, (string) $i, $mode,
                (string) (10000 + ($conflictingAmount ? $i : 0))],
                [0 => ['pipe', 'r'], 1 => ['file', $directory . '/stdout-' . $i, 'w'],
                    2 => ['file', $directory . '/stderr-' . $i, 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start test child');
            fclose($pipes[0]); $processes[] = $process;
        }
        reuseAwait(function () use ($directory, $count) {
            foreach (glob($directory . '/result-*.json') as $file) throw new RuntimeException('Child failed before barrier: ' . file_get_contents($file));
            return count(glob($directory . '/ready-*')) === $count;
        }, 'all children pass reuse precheck');
        touch($directory . '/go');
        reuseAwait(fn () => is_file($directory . '/dispatch-entered'), 'one creator enters dispatch');
        reuseAwait(fn () => count(glob($directory . '/result-*.json')) === $count - 1, 'other requests finish while creator is still waiting');
        $calls = file($directory . '/dispatch-count', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        check(count($calls) === 1, $mode . ': 首次外呼未返回时其他并发请求已经完成，仅一名创建者调用插件');
        touch($directory . '/release-dispatch');
        reuseAwait(fn () => count(glob($directory . '/result-*.json')) === $count, 'all results');
        $results = [];
        foreach ($processes as $i => $process) {
            $code = proc_close($process); unset($processes[$i]);
            $result = json_decode(file_get_contents($directory . '/result-' . $i . '.json'), true, flags: JSON_THROW_ON_ERROR);
            if ($code !== 0 || $result['status'] === 'unexpected') throw new RuntimeException(json_encode($result, JSON_UNESCAPED_UNICODE)
                . file_get_contents($directory . '/stderr-' . $i));
            $results[] = $result;
        }
        $accepted = array_values(array_filter($results, fn ($r) => $r['status'] === 'accepted'));
        if ($conflictingAmount) {
            check(count($accepted) === 1 && count($results) === 2, $mode . ': 同号不同金额只接受一笔，另一笔明确冲突');
        } else {
            check(count($accepted) === $count && count(array_unique(array_column($accepted, 'pay_no'))) === 1,
                $mode . ': 多进程同号请求全部返回同一支付单');
            check(count(array_filter($accepted, fn ($r) => $r['created'])) === 1
                && count(array_filter($accepted, fn ($r) => $r['reused'])) === $count - 1,
                $mode . ': 只有一个创建者，其余均为复用');
            check(count(array_filter($accepted, fn ($r) => $r['reused'] && $r['pay_page'] === 'jump')) === $count - 1,
                $mode . ': 并发重入在付款资料未就绪时获得原单等待页');
        }
        $after = routingCounts();
        check(array_map(fn ($a, $b) => $a - $b, $after, $before) === [1, 1, 1, 1],
            $mode . ': 数据库只增加一张业务单、一张支付单、一条费用流水和一次冻结');
        $biz = BizOrder::where('merchant_id', $f['merchant']->id)->where('merchant_order_no', $orderNo)->firstOrFail();
        $account = MerchantAccount::where('merchant_id', $f['merchant']->id)->firstOrFail();
        check((int) $biz->attempt_count === 1 && (int) $account->frozen_balance >= 500
            && (int) $account->frozen_balance <= 501, $mode . ': 尝试次数为一，费用没有重复冻结');
        $again = routingAttempts()->preparePayAttempt(routingInput($f, $orderNo, (int) $biz->order_amount));
        check($again['reused'] && routingCounts() === $after
            && count(file($directory . '/dispatch-count', FILE_IGNORE_NEW_LINES)) === 1,
            $mode . ': 创建者返回后再次重入仍只读复用');
    } finally {
        foreach ($processes as $process) if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        foreach (glob($directory . '/*') as $file) unlink($file);
        rmdir($directory);
    }
}

reuseRace('store', 6);
reuseRace('plain', 6);
reuseRace('plain', 2, true);
echo "PASS: $checks full-entry cross-process reuse concurrency checks\n";
