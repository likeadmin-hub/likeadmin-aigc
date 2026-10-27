<?php
namespace app\common\service\app\aigc_short_drama;

use RuntimeException;
use think\facade\Db;

/** Opt-in execution boundary stored with the series snapshot, never in paid inputs. */
final class ShortDramaEpisodeRange
{
    // Keep unconfirmed rows out of existing scheduler scans as well as the new gate.
    public const AWAITING_CONFIRMATION = 2147483647;

    public static function initial(): array
    {
        return ['version'=>1,'revision'=>1,'confirmed_end'=>0,'paused'=>false,'confirmations'=>[]];
    }

    public static function control(array $snapshot): ?array
    {
        if (!array_key_exists('generation_control',$snapshot)) return null;
        $control=$snapshot['generation_control'];
        if (!is_array($control) || ($control['version']??0)!==1) throw new RuntimeException('阶段配置版本不兼容，请更新后继续');
        return $control;
    }

    public static function load(int $tenant,int $user,int $project): ?array
    {
        $snapshot=Db::name('aigc_short_drama_episode_task')->where(['tenant_id'=>$tenant,'user_id'=>$user,
            'project_id'=>$project,'episode_number'=>1,'delete_time'=>0])->value('series_json');
        return self::control(ShortDramaEpisodeService::decode($snapshot));
    }

    public static function allowed(?array $control,int $number): bool
    {
        return $control===null || (empty($control['paused']) && $number<=(int)$control['confirmed_end']);
    }

    public static function canRun(?array $control, array $row): bool
    {
        if (self::allowed($control, (int)$row['episode_number'])) return true;
        return $control !== null && !empty($control['paused']) && !empty($row['task_id'])
            && (int)$row['episode_number'] <= (int)$control['confirmed_end']
            && (int)$row['id'] === (int)($control['drain_episode_id'] ?? 0)
            && (int)$row['retry_count'] === (int)($control['drain_retry_count'] ?? -1);
    }

    public static function view(array $rows): ?array
    {
        if (!$rows) return null;
        $snapshot=ShortDramaEpisodeService::decode($rows[0]['series_json']??'');
        $control=self::control($snapshot);
        if ($control===null) return null;
        $end=(int)$control['confirmed_end'];$start=$end+1;$total=count($rows);$blocked='';$active=false;$draining=false;
        foreach($rows as$row) if((int)$row['episode_number']<=$end) {
            if(in_array($row['status'],['pending','running'],true)) {
                $active=true;
                if(!empty($control['paused']) && self::canRun($control,$row))$draining=true;
            }
            if($blocked==='' && ($row['status']!=='success' || !empty($row['needs_review']))) {
                $blocked=!empty($row['needs_review'])?'请先完成前面剧集的连续性复核':
                    (in_array($row['status'],['failed','canceled'],true)?'请先继续修复或恢复当前范围内的剧集':'当前范围尚未完成');
            }
        }
        $max=min($total,$start+9);$goal='';
        foreach(($snapshot['plan']['series_roadmap']??[])as$stage) {
            if((int)$stage['start']<=$start && (int)$stage['end']>=$start){$max=min($max,(int)$stage['end']);$goal=(string)($stage['goal']??'');break;}
        }
        $outline=[];
        foreach($rows as$row) if((int)$row['episode_number']>=$start && (int)$row['episode_number']<=$max) {
            $item=ShortDramaEpisodeService::decode($row['outline_json']);
            $outline[]=array_intersect_key($item,array_flip(['episode_number','title','story_outline','conflict_point','ending_hook']));
        }
        return ['mode'=>'staged','revision'=>(int)$control['revision'],'confirmed_end'=>$end,'paused'=>(bool)$control['paused'],
            'active'=>$active,'draining'=>$draining,'can_confirm'=>$start<=$total && $blocked==='','blocked_reason'=>$blocked,
            'next_start'=>$start,'max_end'=>$max,'goal'=>$goal,'outline'=>$outline,
            'confirmed_ranges'=>array_map(static fn($r)=>['start'=>$r['start'],'end'=>$r['end']],$control['confirmations'])];
    }

    /** Caller holds project and episode row locks; no provider call occurs here. */
    public static function change(int $tenant,int $user,int $project,array $rows,array $params): ?array
    {
        $view=self::view($rows);
        if($view===null)throw new RuntimeException('该项目沿用原生成流程，不能切换阶段模式');
        $snapshot=ShortDramaEpisodeService::decode($rows[0]['series_json']);$control=self::control($snapshot);
        $action=$params['action']??'';
        $revision=filter_var($params['control_revision']??null,FILTER_VALIDATE_INT);
        $key=(string)($params['confirmation_key']??'');
        $fingerprint=ShortDramaPlanningUnit::requestSignature(array_intersect_key($params,
            array_flip(['action','control_revision','range_start','range_end','outlines'])));
        if($action==='confirm_range') {
            if(!preg_match('/^[a-zA-Z0-9_-]{16,80}$/D',$key))throw new RuntimeException('缺少范围确认标识，请刷新后重试');
            foreach($control['confirmations'] as$confirmed) if($confirmed['key']===$key) {
                if(!hash_equals($confirmed['fingerprint'],$fingerprint))throw new RuntimeException('同一确认请求的内容已变化，请刷新核对');
                return null;
            }
        }
        if($revision===false || $revision!==(int)$control['revision'])throw new RuntimeException('阶段已在其他页面更新，请刷新后核对');
        $next=null;
        if($action==='resume_sequential') {
            // Explicit continuation of an old staged project. Keep its outline,
            // receipts, failed/canceled attempts and historical confirmation log.
            $snapshot['previous_generation_control']=$control;
            unset($snapshot['generation_control']);
            Db::name('aigc_short_drama_episode_task')->where(['id'=>$rows[0]['id'],'tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$project])
                ->update(['series_json'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'update_time'=>time()]);
            Db::name('aigc_short_drama_episode_task')->where(['tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$project,'delete_time'=>0,'status'=>'pending',
                'next_retry_at'=>self::AWAITING_CONFIRMATION])->update(['next_retry_at'=>0,'update_time'=>time()]);
            // The normal scheduler observes failures/cancellations before dispatch.
            return null;
        } elseif($action==='confirm_range') {
            $start=filter_var($params['range_start']??null,FILTER_VALIDATE_INT);$end=filter_var($params['range_end']??null,FILTER_VALIDATE_INT);
            if(!$view['can_confirm'])throw new RuntimeException($view['blocked_reason']?:'全部范围已确认');
            if($start!==$view['next_start'] || $end===false || $end<$start || $end>$view['max_end'])throw new RuntimeException('请选择紧接前一范围的连续集数，每次最多确认 10 集');
            $outlines=$params['outlines']??[];
            if(!is_array($outlines) || !array_is_list($outlines) || count($outlines)!==$end-$start+1)throw new RuntimeException('本次分集大纲不完整');
            foreach($outlines as$index=>$patch) {
                $number=$start+$index;$row=$rows[$number-1];
                if(!is_array($patch) || (int)($patch['episode_number']??0)!==$number)throw new RuntimeException('本次集号不连续');
                if($row['status']!=='pending' || !empty($row['task_id']) || !empty($row['production_project_id']) || !empty($row['completed_once']))throw new RuntimeException('本集已经开始创作，不能覆盖大纲');
                $original=ShortDramaEpisodeService::decode($row['outline_json']);
                foreach(['title','story_outline','conflict_point','ending_hook']as$field) {
                    if(!is_string($patch[$field]??null) || trim($patch[$field])==='' || mb_strlen($patch[$field])>($field==='title'?120:20000))throw new RuntimeException('请补全本次大纲，标题最多 120 字');
                    $original[$field]=$patch[$field];
                }
                // Retain timing allocations, source boundaries and material IDs.
                $snapshot['plan']['episodes'][$number-1]=$original;
                Db::name('aigc_short_drama_episode_task')->where(['id'=>$row['id'],'tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$project])
                    ->update(['outline_json'=>json_encode($original,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'title'=>$original['title'],'next_retry_at'=>0,'update_time'=>time()]);
                if($number===$start)$next=$row;
            }
            $control['confirmed_end']=$end;$control['paused']=false;
            $control['confirmations'][]=['key'=>$key,'fingerprint'=>$fingerprint,'start'=>$start,'end'=>$end,'confirmed_at'=>time()];
        } elseif(in_array($action,['pause_range','resume_range'],true)) {
            $control['paused']=$action==='pause_range';
            $control['drain_episode_id']=0;$control['drain_retry_count']=-1;
            if($control['paused']) foreach($rows as$row) {
                if((int)$row['episode_number']<=(int)$control['confirmed_end'] && !empty($row['task_id'])
                    && in_array($row['status'],['pending','running'],true)) {
                    $control['drain_episode_id']=(int)$row['id'];
                    $control['drain_retry_count']=(int)$row['retry_count'];
                    break;
                }
            }
        } else throw new RuntimeException('不支持的阶段操作');
        $control['revision']++;$snapshot['generation_control']=$control;
        Db::name('aigc_short_drama_episode_task')->where(['id'=>$rows[0]['id'],'tenant_id'=>$tenant,'user_id'=>$user,'project_id'=>$project])
            ->update(['series_json'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'update_time'=>time()]);
        return $next;
    }
}
