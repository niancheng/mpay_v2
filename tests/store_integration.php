<?php

/** Real MySQL transactions; run only against a dedicated empty test database. */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use app\service\payment\receipt\AlipayStoreReceiptRules as R;
use app\service\payment\receipt\AlipayReceiptGuard as G;
use app\model\payment\PaymentPluginConf as Conf;
use app\model\payment\PaymentChannel as Channel;
use app\model\payment\PaymentType;
use app\model\payment\PayOrder;
use app\model\payment\BizOrder;
use app\service\payment\runtime\PaymentPluginManager;
use app\service\payment\order\PayOrderCallbackService;
use support\Container;
use support\Db;

$db = (string) env('DB_DATABASE', '');
if (!preg_match('/^mpay_test_[a-z0-9_]+$/D', $db) || env('MPAY_TEST_DATABASE') !== $db) throw new RuntimeException('Dedicated MPAY_TEST_DATABASE=DB_DATABASE=mpay_test_* required');
$pdo = Db::connection()->getPdo();
$migrations = new \app\service\database\MigrationRunner();
$result = $migrations->run($pdo);
check(count($result['executed']) > 0, '全新测试库完成迁移');
check(count($migrations->run($pdo)['executed']) === 0, '重复迁移幂等');
check(Db::table('ma_alipay_receipt_guard')->count() === 1, '事务互斥行持久存在');
check(Db::table('ma_payment_plugin')->where('code', R::PLUGIN)->value('class_name') === 'AlipayStoreBillReceiptPayment', '新插件已注册');
$type = PaymentType::create(['code'=>'alipay','name'=>'支付宝','status'=>1]);
function conf(string $store, array $changes=[]): Conf { return Conf::create(['plugin_code'=>R::PLUGIN,'config'=>storeConfig($store,$changes)]); }
function chan(Conf $conf, int $type): Channel { static $i=0; return Channel::create(['name'=>'TEST-CHANNEL-'.++$i, 'plugin_code'=>$conf->plugin_code,'api_config_id'=>$conf->id,'pay_type_id'=>$type,'status'=>1]); }
function payment(Channel $ch, int $amount=20): PayOrder {
 static $i=0; $no='STORE-TEST-'.++$i;
 BizOrder::create(['biz_no'=>'B-'.$no, 'merchant_order_no'=>$no, 'order_amount'=>$amount,'status'=>1]);
 return PayOrder::create(['pay_no'=>$no,'biz_no'=>'B-'.$no,'trace_no'=>'B-'.$no,'channel_request_no'=>$no,'channel_id'=>$ch->id,'plugin_code'=>$ch->plugin_code,'pay_type_id'=>$ch->pay_type_id,'pay_amount'=>$amount,'status'=>1,'request_at'=>R::date(time()-60),'expire_at'=>R::date(time()+300),'service_fee_amount'=>0]);
}
function plugin(Channel $ch) {return Container::get(PaymentPluginManager::class)->createByChannel($ch);}
function prepare(Channel $ch, PayOrder $p): array {return plugin($ch)->pay(['pay_no'=>$p->pay_no, 'pay_type_code'=>'alipay', 'amount'=>$p->pay_amount]);}
function record(Channel $ch, PayOrder $p, string $trade): array {return testFlow($trade,['candidate_pay_no'=>$p->pay_no,'candidate_channel_id'=>(int)$ch->id,'store_no'=>Conf::find($ch->api_config_id)->config['store_no'],'price'=>\app\common\util\FormatHelper::amount((int)$p->fresh()->pay_amount)]);}
$ca=conf('店铺1793551172076');$a=chan($ca,(int)$type->id);
$cb=conf('店铺1793550688915');$b=chan($cb,(int)$type->id);
$pa=payment($a);$pb=payment($b);$ar=prepare($a,$pa);$br=prepare($b,$pb);
check($ar['presentation']['pay_params']['amount']==='0.20'&&$br['presentation']['pay_params']['amount']==='0.20','同 UID 不同门店可用相同金额');
check(!isset($ar['presentation']['pay_params']['remark_code'])&&!isset($ar['presentation']['pay_params']['transfer_url']),'收银台没有备注和转账入口');
$meta=$pa->fresh()->ext_json['personal_receipt'];prepare($a,$pa);check($pa->fresh()->ext_json['personal_receipt']===$meta,'重复发起不改变识别金额和有效期');
$a2=chan($ca,(int)$type->id);$occupied=payment($a2);rejects(fn()=>prepare($a2,$occupied),'同门店跨通道共用金额占用');$occupied->status=4;$occupied->save();
$before=Conf::find($ca->id)->config;
rejects(function()use($ca){$x=Conf::find($ca->id);$x->config=array_replace($x->config,['store_no'=>'店铺另一门店']);$x->save();},'待支付时禁止修改门店');
check(Conf::find($ca->id)->config===$before,'失败配置修改已回滚');
rejects(fn()=>(new \app\repository\payment\config\PaymentPluginConfRepository())->updateById($ca->id,['config'=>array_replace($before,['receipt_qrcode_content'=>'other-qr'])]),'仓库更新也不能绕过二维码冻结');
rejects(fn()=>(new \app\repository\payment\config\PaymentChannelRepository())->updateById($a->id,['api_config_id'=>$cb->id]),'待支付通道禁止换配置');
$dup=conf('店铺1793551172076');rejects(fn()=>chan($dup,(int)$type->id),'禁止启用重复门店配置');
check(Channel::where('api_config_id',$dup->id)->count()===0,'冲突通道写入回滚');
rejects(fn()=>Conf::create(['plugin_code'=>R::PLUGIN,'config'=>storeConfig(changes:['receipt_match_mode'=>'remark'])]),'模型保存拒绝备注模式');
$legacy=Conf::create(['plugin_code'=>R::LEGACY,'config'=>storeConfig()]);rejects(fn()=>chan($legacy,(int)$type->id),'同 UID 新旧插件不能启用重叠范围');
$otherLegacy=Conf::create(['plugin_code'=>R::LEGACY,'config'=>storeConfig(changes:['bill_user_id'=>'2088000000000002'])]);$lc=chan($otherLegacy,(int)$type->id);check($lc->exists,'不同 UID 的旧插件通道可以共存');
$callbacks=Container::get(PayOrderCallbackService::class);
$ra=record($a,$pa,'ALIPAY-STORE-A-1');$rb=record($b,$pb,'ALIPAY-STORE-B-1');
foreach ([['store_no'=>'店铺17935511720760'],['trade_status'=>'待付款'],['refund_amount'=>'0.01'],['candidate_channel_id'=>(int)$b->id],['candidate_pay_no'=>$pb->pay_no]] as $bad) rejects(fn()=>$callbacks->handleChannelNotifyPayload((int)$a->id,['record'=>array_replace($ra,$bad)]),'最终匹配拒绝不一致证据');
check(Db::table('ma_alipay_receipt_flow')->count()===0 && $pa->fresh()->status===1,'异常通知没有写入资金归属和支付成功');
// Verify ledger and amount restoration roll back with any later failure in the enclosing payment transaction.
rejects(function()use($a,$ra){G::atomic(function()use($a,$ra){plugin($a)->notifyPayload(['record'=>$ra]);throw new RuntimeException('synthetic downstream transaction failure');});},'模拟下游事务失败');
check(Db::table('ma_alipay_receipt_flow')->count()===0 && $pa->fresh()->status===1,'后续失败完整回滚流水占用');
$events=0;\Webman\Event\Event::on(\app\common\constant\EventConstant::PAYMENT_PAY_ORDER_SUCCEEDED,function($data)use(&$events){check(Db::connection()->transactionLevel()===0,'商户成功事件发生在数据库提交后');$events++;});
$ok=$callbacks->handleChannelNotifyPayload((int)$a->id,['record'=>$ra]);check(!empty($ok['success'])&&$pa->fresh()->status===2,'门店 A 通过正式回调服务完成支付');
$callbacks->handleChannelNotifyPayload((int)$b->id,['record'=>$rb]);check($pb->fresh()->status===2,'门店 B 同金额独立完成支付');
check(BizOrder::where('biz_no',$pa->biz_no)->value('paid_amount')===20,'业务订单金额正确');
$callbacks->handleChannelNotifyPayload((int)$a->id,['record'=>$ra]);check($events===2 && Db::table('ma_alipay_receipt_flow')->count()===2,'重复消息不重复触发成功事件或流水归属');
$new=payment($a);prepare($a,$new);$replay=record($a,$new,'ALIPAY-STORE-A-1');rejects(fn()=>$callbacks->handleChannelNotifyPayload((int)$a->id,['record'=>$replay]),'已成功流水不能重新匹配新订单');check($new->fresh()->status===1,'被拒绝重放的订单保持待支付');$new->status=4;$new->save();
// Amount offset and actual selected channel must survive the full callback chain.
$cc=conf('店铺偏移测试',['amount_offset_max'=>2]);$c=chan($cc,(int)$type->id);$c2=chan($cc,(int)$type->id);
$p1=payment($c);prepare($c,$p1);$p2=payment($c2);$p2result=prepare($c2,$p2);check($p2result['presentation']['pay_params']['amount']==='0.21','同门店偏移分配');
$r2=record($c2,$p2,'ALIPAY-OFFSET-2');
$watcher=Container::get(\app\service\payment\receipt\ReceiptWatcherService::class);
$resolved=$watcher->resolveChannelForFlow(R::PLUGIN,(int)$cc->id,'alipay',$r2);check((int)$resolved->id===(int)$c2->id,'通知选择候选订单真实通道而不是配置第一条通道');
Container::get(\app\queue\job\ReceiptFlowNotifyJob::class)->handle(['plugin_code'=>R::PLUGIN,'api_config_id'=>(int)$cc->id,'record'=>$r2]);
check($watcher->isFlowSeen(R::PLUGIN,(int)$cc->id,$r2),'正式流水消费任务成功后才写入确认标记');
check($p2->fresh()->pay_amount===20 && $p2->fresh()->ext_json['personal_receipt']['notified_amount']===21,'付款后恢复原金额并保存实际识别金额');
// Even after a disabled channel, its offered order may complete; deleting financial evidence is prohibited.
$a->status=0;$a->save();rejects(fn()=>$a->delete(),'不能删除有历史订单的通道');
check(Db::table('ma_alipay_receipt_flow')->where('trade_no','ALIPAY-OFFSET-2')->value('pay_no')===$p2->pay_no,'数据库持久归属正确');
// Assert UID-wide replay protection remains effective through legacy-mode shared ledger entry point.
rejects(fn()=>G::atomic(fn()=>G::claim('2088000000000001','ALIPAY-STORE-A-1','LEGACY-OTHER','',20,$ra['paid_at'])),'不能从旧插件重放门店流水');
// No test fixture should be silently accepted as a production end-to-end payment.
echo "PASS: $checks real MySQL integration checks (synthetic payment evidence, no live Alipay)\n";
