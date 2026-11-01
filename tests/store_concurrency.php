<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use app\service\payment\receipt\AlipayStoreReceiptRules as R;
use app\model\payment\PaymentPluginConf as Conf;
use app\model\payment\PaymentChannel as Channel;
use app\model\payment\PayOrder;
use support\Container;
use support\Db;
$db = (string) env('DB_DATABASE','');
if (!preg_match('/^mpay_test_[a-z0-9_]+$/D',$db) || env('MPAY_TEST_DATABASE') !== $db) throw new RuntimeException('Dedicated test DB required');
if (($argv[1] ?? '') === 'child') {
    [$unused,$action,$mode,$payNo,$channelId,$barrier] = $argv;
    while (microtime(true) < (float)$barrier) usleep(1000);
    try {
        $channel=Channel::findOrFail((int)$channelId);
        $plugin=Container::get(\app\service\payment\runtime\PaymentPluginManager::class)->createByChannel($channel);
        if ($mode==='allocate') $plugin->pay(['pay_no'=>$payNo,'amount'=>20,'pay_type_code'=>'alipay']);
        else {
            $config=Conf::findOrFail($channel->api_config_id)->config;
            $record=testFlow('CONCURRENT-SAME-TRADE',['store_no'=>$config['store_no'],'candidate_pay_no'=>$payNo,'candidate_channel_id'=>(int)$channelId]);
            Container::get(\app\service\payment\order\PayOrderCallbackService::class)->handleChannelNotifyPayload((int)$channelId,['record'=>$record]);
        }
        echo "accepted\n";exit(0);
    } catch (\app\exception\PaymentException $e) {echo "rejected: ".$e->getMessage()."\n";exit(2);}
}
function concurrentChannel(string $store): Channel {
    $conf=Conf::create(['plugin_code'=>R::PLUGIN,'config'=>storeConfig($store)]);
    return Channel::create(['name'=>$store,'plugin_code'=>R::PLUGIN,'api_config_id'=>$conf->id,'pay_type_id'=>\app\model\payment\PaymentType::where('code','alipay')->value('id'),'status'=>1]);
}
function concurrentOrder(Channel $channel, string $payNo): PayOrder {
    return PayOrder::create(['pay_no'=>$payNo,'biz_no'=>'B-'.$payNo,'channel_request_no'=>$payNo,'channel_id'=>$channel->id,'plugin_code'=>R::PLUGIN,'pay_type_id'=>$channel->pay_type_id,'pay_amount'=>20,'status'=>1,'request_at'=>R::date(time()-60),'expire_at'=>R::date(time()+300)]);
}
function parallel(array $cases,string $mode): array {
    $barrier=(string)(microtime(true)+0.4);$processes=[];
    foreach ($cases as [$payNo,$channelId]) {
        $pipes=[];$proc=proc_open([PHP_BINARY,__FILE__,'child',$mode,$payNo,(string)$channelId,$barrier],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($proc)) throw new RuntimeException('proc_open failed');
        fclose($pipes[0]);$processes[]=[$proc,$pipes];
    }
    $codes=[];
    foreach($processes as [$proc,$pipes]) {$stdout=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);echo $stdout.$stderr;$codes[]=$code;}
    sort($codes);return $codes;
}
$channel=concurrentChannel('店铺并发分配');$a=concurrentOrder($channel,'CONCURRENT-A');$b=concurrentOrder($channel,'CONCURRENT-B');
check(parallel([[$a->pay_no,$channel->id],[$b->pay_no,$channel->id]],'allocate')===[0,2],'两个进程同时申请零偏移金额，只有一笔分配成功');
check(PayOrder::whereIn('pay_no',[$a->pay_no,$b->pay_no])->whereNotNull('ext_json->personal_receipt->receipt_amount')->count()===1,'数据库只保留一份有效金额占用');
$x=concurrentChannel('店铺并发回调1');$y=concurrentChannel('店铺并发回调2');$p=concurrentOrder($x,'CONCURRENT-P');$q=concurrentOrder($y,'CONCURRENT-Q');
foreach([[$x,$p],[$y,$q]] as [$c,$o]) Container::get(\app\service\payment\runtime\PaymentPluginManager::class)->createByChannel($c)->pay(['pay_no'=>$o->pay_no,'amount'=>20,'pay_type_code'=>'alipay']);
check(parallel([[$p->pay_no,$x->id],[$q->pay_no,$y->id]],'notify')===[0,2],'同 UID 同交易号同时投向不同门店，只有一笔确认成功');
check(Db::table('ma_alipay_receipt_flow')->where('trade_no','CONCURRENT-SAME-TRADE')->count()===1,'同一交易只有一条永久归属');
check(PayOrder::whereIn('pay_no',[$p->pay_no,$q->pay_no])->where('status',2)->count()===1,'并发回调没有重复确认支付单');
echo "PASS: $checks cross-process MySQL concurrency checks\n";
