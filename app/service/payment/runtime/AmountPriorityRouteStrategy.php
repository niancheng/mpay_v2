<?php

declare(strict_types=1);

namespace app\service\payment\runtime;

use app\common\constant\RouteConstant;
use app\exception\BusinessStateException;
use app\exception\ReceiptAmountOccupiedException;
use app\service\payment\receipt\AlipayStoreReceiptAllocationService;
use app\service\payment\receipt\AlipayStoreReceiptRules;

/**
 * 显式金额优先策略。只编排候选，不创建订单、不占用金额、不操作轮询游标。
 * 公共路由层已完成资格筛选和 sort_no / id 排序；此处不重新解释权限与限额。
 */
final class AmountPriorityRouteStrategy
{
    public function __construct(private AlipayStoreReceiptAllocationService $allocation) {}

    public function resolve(array $ordered, int $amount, bool $preview, array $context): array
    {
        $supported = [];
        $rejected = $context['rejected_candidates'] ?? [];
        foreach ($ordered as $candidate) {
            if ($candidate['channel']->plugin_code === AlipayStoreReceiptRules::PLUGIN) {
                $supported[] = $candidate;
                continue;
            }
            // 未适配插件不能冒充“原金额可用”，也不能在兜底阶段偷偷承接订单。
            $candidate['available'] = false;
            $candidate['reject_reasons'][] = '金额优先轮询暂仅支持支付宝门店账单收款';
            $candidate['reject_reason_text'] = implode('；', $candidate['reject_reasons']);
            $rejected[] = $candidate;
        }
        $base = array_replace($context, [
            'route_mode' => RouteConstant::ROUTE_MODE_AMOUNT_PRIORITY,
            'rejected_candidates' => $rejected,
        ]);
        if ($supported === []) {
            $message = '金额优先轮询暂仅支持支付宝门店账单收款，请配置门店通道或选择其他策略';
            if (!$preview) {
                throw new BusinessStateException($message, ['reason_code' => 'amount_priority_plugin_unsupported']);
            }
            return array_replace($base, [
                'available' => false, 'reason' => $message, 'reason_code' => 'amount_priority_plugin_unsupported',
                'candidates' => [], 'selected_channel' => null,
            ]);
        }

        // 预检仅用于筛出第一轮原金额候选和预览；最终分配仍在订单事务内复查。
        $plan = $this->allocation->preview($supported, $amount);
        $selected = $plan['original'][0] ?? $plan['offset'][0] ?? null;
        if ($selected !== null) $selected['receipt_allow_offset'] = $plan['original'] === [];
        $candidates = [];
        foreach (array_merge($plan['original'], $plan['offset']) as $candidate) {
            $candidates[(int) $candidate['channel_id']] ??= $candidate;
        }
        $result = array_replace($base, [
            'available' => $selected !== null,
            'candidates' => array_values($candidates),
            'rejected_candidates' => array_merge($rejected, $plan['rejected']),
            'selected_channel' => $selected,
            'receipt_deferred_candidates' => $plan['deferred'],
            'receipt_attempt_phases' => [
                ['allow_offset' => false, 'candidates' => $plan['original']],
                // 从原合格列表头部重试，而非沿用预检中有偏移余量的子集。
                // 包括零偏移和预检已满的门店：原金额可能已释放；禁止强行复用。
                ['allow_offset' => true, 'candidates' => $supported],
            ],
        ]);
        if ($selected === null) {
            if ($preview) {
                $result['reason'] = (new ReceiptAmountOccupiedException())->getMessage();
                $result['reason_code'] = 'receipt_amount_occupied';
            } else {
                // 正式下单不把瞬时预检当作最终拒绝；有界重查后由分配器决定。
                $result['available'] = true;
                $result['selected_channel'] = $supported[0];
                $result['selected_channel']['receipt_allow_offset'] = true;
            }
        }
        return $result;
    }
}
