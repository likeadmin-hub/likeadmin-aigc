<?php
namespace app\common\service\point_alert;

/** Reuses installed SMS engines with explicit sender config; no ambient request tenant. */
final class AlertDelivery
{
    public static function send(array $d,array $r,array $s,array $account): array
    {
        if($d['channel']==='in_app')return ['status'=>'success','result'=>'站内信已送达'];
        if($d['channel']!=='sms')return ['status'=>'skipped','result'=>'该渠道尚未配置'];
        $cfg=AlertStore::smsConfig((int)$r['owner_id']);
        if(!$cfg||!$s['rules']['sms_template_id'])return ['status'=>'failed','result'=>'短信通道或模板未配置'];
        $params=['name'=>mb_substr($account['name'],0,20),'points'=>$r['balance'],'threshold'=>$r['threshold'],'unit'=>$s['unit']];
        $ordered=[];foreach($s['rules']['sms_variables'] as $key)$ordered[$key]=$params[$key];$params=$ordered;
        $class='app\\common\\service\\sms\\engine\\'.($cfg['engine']==='ALI'?'AliSms':'TencentSms');
        $engine=new $class($cfg['config']);
        $result=$engine->setMobile($d['recipient'])->setTemplateId($s['rules']['sms_template_id'])->setTemplateParams($cfg['engine']==='TENCENT'?array_values($params):$params)->send();
        // Engines collapse network failures and refusals into false. We cannot prove a non-delivery.
        $status=$result===false?'unknown':'success';
        $logTable=$r['owner_id']?AlertStore::scoped('tenant_sms_log',(int)$r['owner_id']):\think\facade\Db::name('sms_log');
        $log=['tenant_id'=>(int)$r['owner_id'],'scene_id'=>205,'mobile'=>$d['recipient'],'content'=>mb_substr($r['content'],0,255),'code'=>'','send_status'=>$result===false?2:1,'send_time'=>time()];
        if($r['owner_id'])$log['tenant_id']=$r['owner_id'];
        $logTable->insert($log);
        return ['status'=>$status,'result'=>$result===false?'短信结果待确认，请查看短信服务商记录':'短信服务商已受理'];
    }
}
