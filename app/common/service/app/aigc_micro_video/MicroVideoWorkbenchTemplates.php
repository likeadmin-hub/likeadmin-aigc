<?php
namespace app\common\service\app\aigc_micro_video;

/** Original starter scripts. Bundled covers illustrate genre, never claim generated sample quality. */
final class MicroVideoWorkbenchTemplates
{
    public static function seed(int $tenant,array $directions): void
    {
        $lock=fopen(runtime_path().'micro_workbench_templates_'.$tenant.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return;}
        try{
            $by=array_column($directions,null,'code');$free=(int)($by['free']['id']??0);
            $stories=[
                'free'=>['自由故事：遗落的纸条','story','campus','主角整理桌面发现一张未署名纸条，镜头交代纸条与手部，不展示未经授权的信息。','主角循着纸条上的线索在同一空间找到旧物，配角出现解释缘由。','配角揭示这是一份给主角的感谢，主角用一个具体动作回应，保持事件因果。','两人把纸条放回原处，画面回到最初构图，以微笑自然结束。',false],
                'property'=>['房产探盘：从门口到窗边','property','estate','讲解者打开入户门，镜头保持真实户型方向，指出用户已确认的一处入户亮点。','讲解者沿连续动线进入客厅，以自然光和空间关系展示实际可见细节。','镜头跟随讲解者走向窗边，展示真实视野与用户提供的房源事实，不推算面积或学区。','回到空间全景，讲解者概括已展示的两个细节，留出简洁咨询引导。',false],
                'palace'=>['宫廷反转：案上的小礼','palace','palace','主角走进同一宫廷书房，发现案上礼盒被挪动，克制地询问配角原因。','配角先解释摆放失误，主角检查礼盒与案面位置，冲突来自误会而非危险情节。','配角打开礼盒展示商品，按真实实物图与已确认卖点说明用途，主角神情转变。','主角把商品放在案上原位，二人化解误会，以商品近景收尾，不添加功效承诺。',true],
                'kungfu'=>['功夫日常：收招见真章','kungfu','kungfu','主角在固定练功场景完成一组轻巧收势，配角递来日常用品，人物不接触危险器械。','主角接住用品后顺势放稳，镜头连续跟随手部动作，保持商品结构与衣着方向。','配角用一句简短提问带出使用场景，主角按已确认事实演示实际用途。','主角收拾用品并与配角走出画面，商品留在固定位置，动作状态完整闭合。',true],
                'campus'=>['校园暖心：雨停之前','campus','campus','放学时主角站在教学楼门口看雨，配角从走廊赶来，双方衣着与走向一致。','主角发现书包中的商品正适合此刻的真实需求，先征求配角意见再拿出。','两人共同使用或展示商品，按商品已确认事实描述细节，避免把道具文字当作事实。','雨势渐小，两人相视一笑，把商品整理归位，镜头以同一门口的温暖中景收束。',true],
                'office'=>['职场轻喜：会议前一分钟','office','office','主角在会议室门口发现临时准备少了一样东西，配角带着商品走近。','主角先误以为配角送错物品，两人用短对白交代任务与误会，不贬损任何人。','配角演示商品适用的真实功能，主角解决眼前小问题，镜头清楚展示商品实物。','会议室门打开，两人带齐物品从容入场，商品特写与自然笑容交替结束。',true],
                'twist'=>['生活反转：原来是给你的','twist','campus','主角在餐桌看见一件被精心摆放的商品，以为是配角为自己准备的礼物。','主角试着询问，配角故作神秘地指出便签，镜头只保留已授权内容。','两人揭示礼物其实是共同完成一件小事的工具，按真实用途展示商品。','主角与配角开始这件小事，商品和手部共同入镜，以行动代替夸大宣传。',true],
                'product'=>['商品展示：一个日常问题','commerce','product','真实桌面场景交代用户的一项日常需求，商品保持与上传实物图相同的外观。','主角拿起商品展示一个真实结构细节，镜头不添加额外按钮、包装或品牌文字。','主角在真实使用情境中展示已确认卖点，禁止编造价格、认证和功效。','镜头回到商品全景，展示用户明确要求的核心信息，画面留白自然结束。',true],
            ];
            foreach($stories as $code=>$s){$d=$by[$code]??$by['free']??['id'=>$free,'status'=>1,'prompt'=>'遵循用户意图','continuity_prompt'=>MicroVideoSettings::defaults()['continuity_template']];$seed='starter_v1_'.$code;
                $exists=MicroVideoWorkbenchRecords::table()->where(['tenant_id'=>$tenant,'user_id'=>0,'kind'=>'template'])->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(body_json, '$.seed_key')) = ?",[$seed])->find();if($exists)continue;
                $roles=[['role_id'=>'lead','name'=>$code==='property'?'讲解者':'主角','description'=>'使用用户确认的外观与服饰；未绑定时不得声称身份已固定','group'=>'lead','fixed_identity'=>true,'images'=>[]]];
                if(!in_array($code,['property','product'],true))$roles[]=['role_id'=>'support','name'=>'配角','description'=>'与主角形成叙事关系，服饰外观由用户确认','group'=>'support','fixed_identity'=>true,'images'=>[]];
                $beatNames=['钩子','矛盾或需求','解决与展示','收束'];$times=[[0,5],[5,15],[15,25],[25,30]];$beats=[];$lines=[];foreach($times as $i=>$range){$beats[]=['name'=>$beatNames[$i],'start'=>$range[0],'end'=>$range[1],'description'=>$s[$i+3]];$lines[]=$range[0].'-'.$range[1].'秒：'.$s[$i+3];}
                $body=['seed_key'=>$seed,'direction_id'=>(int)$d['id'],'category'=>$s[1],'workflow_type'=>$code==='property'?'property':($s[7]?'commerce':'story'),'description'=>'30秒完整叙事骨架，可按真实商品、角色与模型时长改写；示例成片尚待制作。','cover_image'=>'/resource/image/apps/aigc_micro_video/templates/'.$s[2].'.png','example_video'=>'','style'=>'真人实拍','durations'=>[15,30,60,90,120],'ratio'=>'9:16','script'=>implode("\n",$lines),'beats'=>$beats,'roles'=>$roles,'variables'=>['创作主题','已确认商品事实','必须表达','禁止表述'],'reference_rules'=>(string)$d['continuity_prompt'],'direction_rules'=>(string)$d['prompt'],'images'=>[]];
                MicroVideoWorkbenchRecords::save($tenant,0,['kind'=>'template','title'=>$s[0],'visibility'=>'tenant','status'=>(int)$d['status'],'body'=>$body],true);
            }
            // Preserve every additional existing operating direction as a usable linked template.
            foreach($directions as $d){if(isset($stories[$d['code']]))continue;$key='direction_'.$d['id'];$exists=MicroVideoWorkbenchRecords::table()->where(['tenant_id'=>$tenant,'user_id'=>0,'kind'=>'template'])->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(body_json, '$.seed_key')) = ?",[$key])->find();if($exists)continue;MicroVideoWorkbenchRecords::save($tenant,0,['kind'=>'template','title'=>$d['name'],'visibility'=>'tenant','status'=>$d['status'],'body'=>['seed_key'=>$key,'direction_id'=>(int)$d['id'],'category'=>'story','workflow_type'=>'story','description'=>$d['description'],'script'=>$d['prompt'],'beats'=>[],'roles'=>[],'variables'=>[],'reference_rules'=>$d['continuity_prompt'],'direction_rules'=>$d['prompt'],'images'=>[]]],true);}
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
