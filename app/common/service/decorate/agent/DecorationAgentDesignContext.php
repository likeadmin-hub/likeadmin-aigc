<?php
declare(strict_types=1);
namespace app\common\service\decorate\agent;

use app\common\service\FileService;

/** Model-facing writable contracts and first-party visual guidance; no model calls. */
final class DecorationAgentDesignContext
{
    private const SOURCE_FIELDS=['data_mode','source_key','source_params','field_map'];

    public static function catalog(): array
    {
        return array_map(fn($definition)=>self::compact($definition,false),DecorationAgentCatalog::summary()['components']);
    }

    public static function digest(): array
    {
        $components=self::catalog();$rows=[];$shared=$components[0]['style_keys']??[];
        foreach($components as $component)$shared=array_values(array_intersect($shared,$component['style_keys']));
        foreach($components as &$component)$component['style_keys']=array_values(array_diff($component['style_keys'],$shared));
        unset($component);
        foreach($components as &$component)foreach($component['lists'] as &$list){
            $id='rows:'.substr(hash('sha256',json_encode($list['fields'],JSON_UNESCAPED_UNICODE)),0,10);
            $rows[$id]??=$list['fields'];unset($list['fields']);$list['field_contract']=$id;
        }
        unset($component,$list);
        return ['components'=>$components,'shared_style_keys'=>$shared,'shared_row_contracts'=>$rows,'detail_query'=>'catalog {name}或{names:[...]}提供原始完整字段/默认值/条件/说明；每个list.field_contract对应shared_row_contracts；所有组件style_keys应加上shared_style_keys。'];
    }

    private static function compact(array $definition,bool $detailed=true): array
    {
        $fields=[];
        foreach($definition['content_fields']??[] as $key=>$schema)if(!in_array($key,self::SOURCE_FIELDS,true)&&!isset($definition['lists'][$key]))$fields[$key]=self::schema($schema,$detailed);
        $lists=[];
        foreach($definition['lists']??[] as $key=>$list){
            $row=[];foreach($list['fields']??[] as $field=>$schema)$row[$field]=self::schema($schema,$detailed);
            $lists[$key]=['fields'=>$row,'server_owned_id'=>true]+array_intersect_key($list,array_flip(['min_items','max_items']));
        }
        return array_intersect_key($definition,array_flip(['name','title','category','repeatable']))+[
            'fields'=>$fields,'lists'=>$lists,'component_id_readonly'=>true,'style_keys'=>array_keys($definition['style_fields']??[]),
            'container'=>(bool)($definition['children']['supported']??false),
            'source'=>array_intersect_key($definition['source']??[],array_flip($detailed?['default_key','allowed_keys','modes']:['default_key','modes'])),
            'semantics'=>self::semantics((string)$definition['name']),'runtime_capabilities'=>self::runtimeNotes($definition['name']),'image_slots'=>self::imageSlots($definition),
        ];
    }

    public static function contract(string $name): array
    {
        $definition=DecorationAgentCatalog::definition($name);
        return $definition+['semantics'=>self::semantics($name),'runtime_capabilities'=>self::runtimeNotes($name),'image_slots'=>self::imageSlots($definition),'write_constraints'=>self::identityConstraints($definition),'write_policy'=>'组件及所有列表行id由服务端管理；新增/整表替换不传id，已有行用edit_list按index修改，不复制default/source行id。source_key/source_params/data_mode用bind_source；field_map仅展示默认契约，禁止任意写入；所有图片只来自本轮授权素材或受控request_image。'];
    }

    private static function identityConstraints(array $definition): array
    {
        $lists=[];
        foreach ($definition['lists']??[] as $field=>$list) $lists[$field]=[
            'owner'=>'server','readonly_fields'=>['id'],'creation'=>'omit id',
            'replacement'=>'new rows without id','existing_update'=>'edit_list:index',
        ];
        return ['component_id'=>['owner'=>'server','readonly'=>true,'creation'=>'omit id; alias only on add_component operation root',
                'alias_support'=>['operation'=>'add_component','target'=>'operation root component','unsupported'=>['replace_page.components','children']]],
            'list_row_ids'=>$lists,'note'=>'默认值、已有列表和数据源返回的id只读，不得复制到新增、整表替换或字段更新参数。'];
    }

    public static function discover(array $query): array
    {
        if(isset($query['names'])){
            if(!is_array($query['names'])||!array_is_list($query['names'])||count($query['names'])>count(DecorationAgentCatalog::summary()['components']))throw new \InvalidArgumentException('组件名称批量查询无效');
            foreach($query['names'] as $name)if(!is_string($name)||$name==='')throw new \InvalidArgumentException('组件名称无效');
            return ['components'=>array_map(fn($name)=>self::contract($name),array_values(array_unique($query['names']))),'detail'=>true];
        }
        if(!empty($query['name']))return self::contract((string)$query['name']);
        $all=self::catalog();$limit=max(1,min(count($all),(int)($query['limit']??count($all))));$page=max(1,(int)($query['page']??1));
        return ['components'=>array_slice($all,($page-1)*$limit,$limit),'total'=>count($all),'page'=>$page,'limit'=>$limit,'detail'=>false,'detail_query'=>'catalog {name}或{names:[多个名称]}返回原始完整默认值、所有字段/条件/说明/列表/来源/容器限制。'];
    }

    private static function runtimeNotes(string $name): array
    {
        $notes=[
            'search'=>['supported'=>'placeholder、height；公共端整体导航','layout'=>'无图片槽；height逻辑px','limitations'=>'show_hotword/show_button未消费；共享预览仅占位文字、未模拟输入/导航','unsupported_fields'=>['content.show_hotword','content.show_button','content.field_map']],
            'fixed-search'=>['supported'=>'与search同一控制路径','layout'=>'无图片槽','limitations'=>'fixed_position未消费，当前并不固定定位；通用source字段不驱动搜索','unsupported_fields'=>['content.fixed_position','content.field_map']],
            'banner'=>['supported'=>'autoplay/interval/show_indicator/show_caption/image_height；row image/name；无图title/description','layout'=>'image_height rpx，轮播封面；纯图显式show_caption=0；无图文字背景继承','limitations'=>'show_caption缺省保留共享有说明/公共无说明的旧差异，显式1两端有图说明，0均无图上说明；不影响无图文字。style/bg_style旧字段未消费；公共端mixed images/text保留图片swiper、无图仅首行文字，不能宣称多文本轮播','unsupported_fields'=>['content.style','content.bg_style','content.field_map']],
            'carousel-search'=>['supported'=>'autoplay/interval/placeholder/show_search；source rows','layout'=>'固定轮播高度默认340rpx，cover图片','limitations'=>'公共端indicator固定开启、未支持indicator参数；搜索是展示文案，未实现搜索动作','unsupported_fields'=>['content.field_map']],
            'nav'=>['supported'=>'per_line/show_line/style；row image/name/link（公共端）','layout'=>'公共82rpx图，预览40px；宫格/分页','limitations'=>'共享端style不切换分页、row link未模拟点击；description/sub_title/tag不展示','unsupported_fields'=>['content.field_map']],
            'news'=>['supported'=>'title/limit/show_image/show_desc/show_time；真实cover字段；公共合法来源导航','layout'=>'公共180×120rpx封面aspectFill；预览固定缩略图','limitations'=>'show_click及styles.title_color/text_color未消费；共享点击未模拟；非article无显式link不能按文章ID导航','unsupported_fields'=>['content.show_click','styles.title_color','styles.text_color','content.field_map']],
            'middle-banner'=>['supported'=>'rows image/link（公共端）','layout'=>'公共200rpx swiper，widthFix；预览单图','limitations'=>'预览仅首图、未模拟公共自动轮播；name/description等无文字展示','unsupported_fields'=>['content.field_map']],
            'title-bar'=>['supported'=>'title/sub_title/align；font_size/sub_font_size逻辑px；font_weight显式字重；styles.color两端真实消费','layout'=>'无图片槽；未配置字号保留17px/34rpx标题及12px/24rpx副标题；显式字号两端统一逻辑px','limitations'=>'content.link两端未消费；font_weight=0保留各端历史字重；不能依靠rich-text h1默认获得固定字号','unsupported_fields'=>['content.link','content.field_map']],
            'notice'=>['supported'=>'text；配置字号模式；共享font_weight；公共link','layout'=>'公共固定bell glyph；无自定义图片槽','limitations'=>'icon/icon_mode/data_source未消费；public font_weight未消费；不要承诺自定义公告图标/读取公告源','unsupported_fields'=>['content.icon','content.icon_mode','content.data_source','content.field_map']],
            'list-nav'=>['supported'=>'row image/name/link（公共端），公共desc','layout'=>'公共56rpx图、列表','limitations'=>'共享不展示desc、不模拟跳转；通用sub_title/description/tag未消费','unsupported_fields'=>['content.field_map']],
            'image-hotspot'=>['supported'=>'image/height/areas.left/top/width/height/link','layout'=>'主图cover，热区百分比','limitations'=>'content.mode/fit未消费；声明fit不能改变主图裁剪','unsupported_fields'=>['content.mode','content.fit','content.field_map']],
            'image-showcase'=>['supported'=>'layout/columns/show_title/show_sub_title；row image/title/sub_title/description/link','layout'=>'single/grid/double/scroll；共享4:3，公共高210rpx','limitations'=>'两端图片比例不同；image_fit非该组件写入字段，仍固定cover/aspectFill；不应借media-card新增字段扩散','unsupported_fields'=>['content.field_map']],
            'divider'=>['supported'=>'content.style（solid等），公共styles.color','layout'=>'无图片；1px线','limitations'=>'content.height未消费；共享线色使用固定theme边框token','unsupported_fields'=>['content.height','content.field_map']],
            'user-info'=>['supported'=>'公共真实user/isLogin，跳转个人页；共享静态昵称示意','layout'=>'公共avatar；不是手工用户数据源','limitations'=>'共享未模拟真实账号；不能通过通用row修改用户信息','unsupported_fields'=>['content.field_map']],
            'my-service'=>['supported'=>'title/style/per_line、row image/name/link（公共端）','layout'=>'公共grid60rpx、list48rpx；共享不同缩略尺寸','limitations'=>'共享不模拟link；通用副标题/tag无消费者','unsupported_fields'=>['content.field_map']],
            'user-banner'=>['supported'=>'row image/link（公共端）','layout'=>'公共200rpx swiper widthFix；共享首图+可选name','limitations'=>'公共无name/description文字；预览不模拟轮播','unsupported_fields'=>['content.field_map']],
            'customer-service'=>['supported'=>'公共title/time/mobile/qrcode/remark，联系/电话/二维码动作','layout'=>'qrcode aspectFit','limitations'=>'共享仅标题文字；source/data等不驱动客服详情','unsupported_fields'=>['content.field_map']],
            'rich-text'=>['supported'=>'html优先、text转义换行；两端内容相同','layout'=>'HTML自带图像另受富文本组件支持','limitations'=>'data/source/fallback_data不生成富文本；公共富文本与浏览器HTML功能集合不同','unsupported_fields'=>['content.field_map']],
            'spacer'=>['supported'=>'height rpx','layout'=>'无图片；正常流空白','limitations'=>'列表/source字段不参与渲染','unsupported_fields'=>['content.field_map']],
            'audio-player'=>['supported'=>'title/audio_url/loop；公共点击播放/暂停；共享native controls','layout'=>'cover字段虽有编辑器但不展示','limitations'=>'cover两端未消费；公共autoplay主动设false，不能承诺自动播放','unsupported_fields'=>['content.cover','content.autoplay','content.field_map']],
            'button-group'=>['supported'=>'button_style/button_shape/button_type/display_style/font_size/font_weight/icon_size/columns；row show_text/tag配置','layout'=>'图片固定square aspectFill；图文/纯图/纯文；fixed/scroll/page','limitations'=>'icon现是旧字面符号文本，不是新增入口glyph系统；未知图标名字不得推荐；page为CSS单行滑动而非独立分页状态','unsupported_fields'=>['content.field_map']],
            'tabs'=>['supported'=>'active；row title/name/link','layout'=>'横排标签','limitations'=>'点击是导航，未修改本页内容选中状态；图片/描述/tag字段不展示','unsupported_fields'=>['content.field_map']],
            'video-player'=>['supported'=>'video_url/cover/show_controls/autoplay','layout'=>'原生video poster cover','limitations'=>'title/aspect_ratio未消费；容器高度/比例固定CSS','unsupported_fields'=>['content.title','content.aspect_ratio','content.field_map']],
            'floating-action'=>['supported'=>'position/offset_right/offset_bottom/button_size/button_gap/button_shape；row type/link/icon_mode/icon_image/show_text/phone','layout'=>'图片square；type支持链接/回顶/客服/电话；固定层','limitations'=>'采用独立auto/type符号系统，不等同14类入口glyph；contact依赖端能力；不是普通文档流','unsupported_fields'=>['content.field_map']],
            'popup-promo'=>['supported'=>'image/title/desc/link','layout'=>'当前是文档流内推广卡背景cover','limitations'=>'trigger/delay/closeable未消费；**没有弹窗生命周期**，不可承诺首次/延迟/关闭行为','unsupported_fields'=>['content.trigger','content.delay','content.closeable','content.field_map']],
            'section'=>['supported'=>'children、外壳样式','layout'=>'正常流容器','limitations'=>'列表/source不自动创建children','unsupported_fields'=>['content.field_map']],
            'grid'=>['supported'=>'columns/gap、children；直接子组件可选styles.grid_column','layout'=>'1–12列；子组件grid_column为0–12整数，正值按最近父栅格列数限制占列，显式0整行；缺省先兼容旧span，无旧值才整行。columns=3本身不会自动把三个子项排为一行，三列等宽应逐直接子项写styles.grid_column=1','limitations'=>'占列只影响最近父栅格直接子项，不传播到后代；只能写声明的styles.grid_column，不能凭空写layout对象；source不会生成children','unsupported_fields'=>['content.field_map']],
            'stack'=>['supported'=>'min_height、children','layout'=>'百分比/叠层布局，共享/公共child布局辅助','limitations'=>'默认布局需显式合法child定位配置；data/source非children；位置字段写入边界需单独导出','unsupported_fields'=>['content.field_map']],
            'horizontal-scroll'=>['supported'=>'gap、children、可选item_width','layout'=>'单行横滑组件容器；item_width为0–1500rpx，正值只指定最近横滑容器直接子组件卡宽，不传播到嵌套后代','limitations'=>'item_width=0或未配置保留78%旧卡宽与样式级联；source rows不会转换为子组件；image_width只改变媒体卡内图宽，不能代替本字段','unsupported_fields'=>['content.field_map']],
            'masonry'=>['supported'=>'columns/gap、children','layout'=>'2–4 CSS columns的容器','limitations'=>'这是子组件瀑布流；不是case-feed的图片列表列数开关','unsupported_fields'=>['content.field_map']],
            'card'=>['supported'=>'children、外壳样式','layout'=>'正常流卡片容器','limitations'=>'source/data不会创建内部children','unsupported_fields'=>['content.field_map']],
            'image-nav'=>['supported'=>'per_line/show_line/show_title/show_sub_title；row image/title/sub_title/link','layout'=>'方形84rpx/aspectFill，宫格','limitations'=>'description未用于副标题回退；无命名glyph选择；内文字仍有固定色','unsupported_fields'=>['content.field_map']],
            'magic-grid'=>['supported'=>'grid.columns/rows、gap、row row/col/spans/image_fit/title/sub_title/link；历史自由polygon','layout'=>'行列区域百分比，image_fit cover/contain/fill/auto/repeat实际消费','limitations'=>'通用row description/tag不展示；icon未声明且当前会被当图片值，不能推荐glyph','unsupported_fields'=>['content.field_map']],
            'tutorial-carousel'=>['supported'=>'card_width/show_tag/show_sub_title，row image/title/sub_title/tag/link','layout'=>'卡片宽180–360rpx，公共图高158rpx；共享图片比例不同','limitations'=>'row.description不回退说明；内部固定文字色，不能承诺全内卡主题覆盖','unsupported_fields'=>['content.field_map']],
            'case-feed'=>['supported'=>'content.title实际区块标题；source image_cases等；row image/cover/cover_url/title/link/path','layout'=>'固定2列、封面高宽比1.15、cover','limitations'=>'无columns字段；row description/tag不展示；title空不添加默认区块标题；视频无封面留空','unsupported_fields'=>['content.field_map']],
            'app-collection'=>['supported'=>'title、columns/font_size/icon_size/show_title/show_sub_title/image_fit；row image/icon/title/description/link；styles.item_background/description_color/icon_color及item_padding/item_radius/item_min_height','layout'=>'1–5列入口，图标32–160rpx；内卡padding/radius/min_height按rpx；0最小高度为自然高度；图片cover/contain；真实应用源','limitations'=>'未配置内卡参数保留原端默认；icon_color只改变单色named glyph，渐变/复杂图标应使用授权独立图片；来源品牌图片优先，不能强行替换','unsupported_fields'=>['content.field_map']],
            'creation-entry-grid'=>['supported'=>'同上；ai_tools或手工入口','layout'=>'同上；14类finite glyph带label/tags','limitations'=>'show_title指row标题，不是区块title；show_sub_title指row说明；未知token不作图像URL','unsupported_fields'=>['content.field_map']],
            'recent-tasks'=>['supported'=>'title、source recent_tasks、row image/title/description/link','layout'=>'固定紧凑列表，图84rpx方形','limitations'=>'不显示任务专属进度/成本组件；通用row只显示可用文字，没有任务状态机专用呈现','unsupported_fields'=>['content.field_map']],
            'media-card'=>['supported'=>'layout/columns/show_title/show_sub_title/image_fit/image_height/image_width/list_scroll；font_size/sub_font_size逻辑px、font_weight行字重；row image/title/description/link','layout'=>'single/grid/double/scroll保留旧语义；list为单卡内左图右文，list_scroll=1外部横滑且卡宽72%；image_width仅list消费，rpx，0为160rpx且不超过卡宽45%；image_height显式正值按rpx，两端使用同一高度；cover或contain；font_size为0或10–32px，sub_font_size为0或10–24px，显式正值统一两端','limitations'=>'行字号/字重=0或未配置保留共享13/11px及公共26/22rpx旧值与各端旧字重；不控制区块标题。image_height=0或未配置时保留共享4:3/公共210rpx高的历史效果；image_width不控制整个卡宽且其它布局忽略；list不等于scroll，外滑内横排用list+list_scroll；非自定义aspect_ratio；通用tag不展示','unsupported_fields'=>['content.field_map']],
            'user-hero'=>['supported'=>'user_profile source；通用row title/image/description','layout'=>'当前通用紧凑列表，不是特别的hero布局','limitations'=>'专属用户字段（昵称/avatar等）必须由源提供可用标准row，否则不自动映射；无真实profile编辑能力','unsupported_fields'=>['content.field_map']],
            'membership-card'=>['supported'=>'source title/expire_time/action_text、content.link','layout'=>'专门会员权益卡；公共状态文案/回跳','limitations'=>'不能通过通用data任意改会员权益事实；只展示/导航；字段超出默认row契约需要源适配说明','unsupported_fields'=>['content.field_map']],
            'commerce-entries'=>['supported'=>'row key/title/sub_title/image/icon/link；白字购买卡','layout'=>'图片aspectFit；默认icon为literal符号','limitations'=>'命名glyph不消费，不能把14入口glyph直接推荐给该icon槽','unsupported_fields'=>['content.field_map']],
            'user-stats'=>['supported'=>'source rows，固定4列通用row','layout'=>'方形缩略图/标题/description','limitations'=>'不会自动渲染profile对象的任意数值；需实际source标准rows；无columns/font参数','unsupported_fields'=>['content.field_map']],
            'account-quota'=>['supported'=>'source rows，紧凑通用row','layout'=>'方形缩略图/标题/description','limitations'=>'不会自动把任意account_summary键转指标；不改变余额/隐私事实','unsupported_fields'=>['content.field_map']],
            'asset-entries'=>['supported'=>'user_assets rows，固定4列；整行空态','layout'=>'方形缩略图/标题/description','limitations'=>'无入口columns/font设置；无素材库操作按钮/状态机；游客空源保留合法空态','unsupported_fields'=>['content.field_map']],
            'service-grid'=>['supported'=>'columns/font_size/icon_size/show_title/show_sub_title/image_fit；row image/icon/title/description/link','layout'=>'与creation-entry相同；合法服务链接','limitations'=>'新内卡/文字样式被两端消费；content无title默认，公共仍保留历史常用服务标题','unsupported_fields'=>['content.field_map']],
        ];
        if($name==='media-card'){
            $notes[$name]['supported'].='；非list可选image_max_width；list可选image_text_gap；可选text_align及styles.image_background';
            $notes[$name]['image_composition']='image_max_width按rpx限制非list图片槽宽并居中，正值不超过内容可用宽，0/未配置保留100%；list忽略它，仍用image_width。image_text_gap只控制list内部图与文之间的间距，正值0–240rpx，0/缺省沿用共享12px与公开24rpx；不改变外部卡宽、styles.item_gap、来源或整卡点击，非list忽略。image_height是槽高；可见主体尺寸还取决于真实画布留白及fit，最大宽不能代替bbox。styles.image_background仅图片槽底，空/缺省保留旧surface，支持transparent、3/4/6/8位hex、整数rgb0–255及rgba alpha0–1（含.5），不支持named/hsl/var/gradient/url。text_align空/缺省保留旧样式，left/center/right只对卡内标题/说明，点击link保留。contain保留完整图片但可能露出槽底；cover可能裁掉主体，必须对照真实bbox确认，不能为去白底盲目cover。不要编造crop字段。';
            $notes[$name]['fit_math']='image_max_width限制盒宽，不保证实际绘制画布宽。源画布W×H、盒宽w高h：contain缩放min(w/W,h/H)，cover缩放max(w/W,h/H)，默认居中；源主体bbox乘缩放后再与盒子相交，才是可见主体。方图用contain时画布绘制边长min(w,h)，增大盒宽但盒高较小不会放大图形；cover绘制边长max(w,h)，只在已验证裁掉同色留白且完整主体仍在盒内时使用。修改图槽高/上padding需联算标签基线，不能以尺寸字段相同声称图标已匹配。';
        }
        if($name==='banner')$notes[$name]['image_composition']='image_height是图片内容高度，不等于含padding/圆角外壳/阴影的整卡高度；对照设计需分别量图片和外壳。cover不会自动去掉源文件内嵌黑条，若原裁切无黑条应保留该可见图高，用真实深色背景/padding补外壳，不把整卡高度直接写成图片高度。';
        return ['audit'=>'2026-10-15 source control paths; browser coverage is separate','support'=>'partial']+($notes[$name]??[]);
    }

    private static function imageSlots(array $definition): array
    {
        $result=[];$name=$definition['name'];
        // Only foreground fields actually consumed by mobile rendering are preferred.
        foreach($definition['content_fields']??[] as $key=>$field)if(($field['type']??'')==='image'&&!($name==='audio-player'&&$key==='cover'))$result[]=['field'=>'content.'.$key,'role'=>$key==='qrcode'?'qrcode':'cover','preferred'=>$key!=='qrcode'];
        $imageRows=['banner','carousel-search','nav','news','middle-banner','list-nav','image-showcase','my-service','user-banner','button-group','image-nav','magic-grid','tutorial-carousel','case-feed','app-collection','creation-entry-grid','recent-tasks','media-card','user-hero','commerce-entries','user-stats','account-quota','asset-entries','service-grid'];
        if(in_array($name,$imageRows,true)&&isset($definition['lists']['data']['fields']['image']))$result[]=['field'=>'content.data.*.image','role'=>in_array($name,['banner','middle-banner','user-banner'],true)?'hero':'row_image','preferred'=>true,'note'=>'必须先有对应列表行，request_image用实际数字索引，不用*。'];
        if($name==='floating-action')$result[]=['field'=>'content.data.*.icon_image','role'=>'icon','preferred'=>true,'requires'=>['icon_mode'=>'image']];
        if(isset($definition['style_fields']['background_image']))$result[]=['field'=>'styles.background_image','role'=>'background','preferred'=>false];
        return $result;
    }

    private static function schema(array $schema,bool $detailed=true): array
    {
        $result=array_intersect_key($schema,array_flip(['label','value_type','type','unit','min','max','max_length','min_items','max_items','item_type','help','show_if']));
        if(isset($schema['options']))$result['options']=$schema['options'];
        if(array_key_exists('default',$schema)&&!is_array($schema['default'])&&!is_object($schema['default']))$result['default']=$schema['default'];
        if(!$detailed){$result=array_intersect_key($result,array_flip(['type','unit','min','max','min_items','max_items','show_if','options']));if(isset($result['options']))$result['options']=array_map(fn($option)=>$option['value'],$result['options']);}
        return array_filter($result,fn($value)=>$value!==''&&$value!==null);
    }

    public static function sharedStyles(): array
    {
        $styles=[];
        foreach(DecorationAgentCatalog::summary()['components'] as $definition)foreach($definition['style_fields']??[] as $key=>$schema)$styles[$key]??=self::schema($schema);
        return $styles;
    }

    public static function related(array $page,string $scope,string $selectedId,string $message): array
    {
        $index=DecorationAgentOperations::index($page['data']??[]);$names=[];
        if($scope==='selected'){
            $selected=$index[$selectedId]??null;
            if(!$selected)return [];
            foreach($index as $node)if(array_slice($node['path'],0,count($selected['path']))===$selected['path'])$names[]=$node['widget']['name'];
        }else{
            $names=array_column(array_column($index,'widget'),'name');
            $names=array_merge($names,['rich-text','title-bar','banner','creation-entry-grid','section']);
            foreach([
                '教程|学习|入门'=>['tutorial-carousel','tabs'],
                '案例|作品|灵感'=>['case-feed','image-showcase'],
                '会员|我的|服务|个人'=>['membership-card','account-quota','service-grid','user-info','asset-entries'],
            ] as $pattern=>$components)if(preg_match('/'.$pattern.'/u',$message))$names=array_merge($names,$components);
        }
        return array_values(array_map(fn($definition)=>self::compact($definition),array_filter(DecorationAgentCatalog::summary()['components'],fn($definition)=>in_array($definition['name'],array_unique($names),true))));
    }

    public static function guide(): array
    {
        return [
            'units'=>'移动逻辑宽375px；字段按契约px/rpx写，rpx按750设计宽。样式只用style_keys及shared_style_contract允许的字段。',
            'composition'=>'整页先安排主视觉、文字介绍、核心入口、真实案例/内容、服务。主视觉配真实装饰素材，标题用文字组件，少量大块留白和一致间距，不用大量空卡片填满。',
            'entries'=>'根据真实入口数量及视觉层级选择columns，遵循各组件的font_size(px)、icon_size(rpx)、image_fit及显隐参数；内卡按真实契约设置item_background/description_color/icon_color及item_padding/item_radius/item_min_height(rpx)。named glyph仅单色，不画未经支持的渐变；复杂图形需要授权的独立图片素材。',
            'design_fidelity'=>'整页设计图必须按当前可写参数和runtime_notes规划实际尺寸、字号与图形。大标题显式使用title-bar.font_size(px)，副标题用sub_font_size；不能假设rich-text h1有跨端固定字号。入口卡片尺寸用已声明内卡参数；media-card需要跨端同高度时显式image_height(rpx)。原素材保留存储身份并选择正确裁切比例；设计图内应画出拟生成的独立素材构图，等待确认只禁止提交独立素材任务，不是把设计图中的素材区域留空。',
            'visual_measurement'=>'按已确认设计图的实际宽度换算到375px逻辑画布，分别估算外边距、区块顶部间距、主视觉宽高、内卡宽高、字号和图片宽高；这些是实现规划估算，不是相似度测量。区分styles.margin_horizontal与padding_horizontal，二者会叠加收窄可用区域，不要在每层容器重复设置同样外边距。横滑容器仅表示多卡横向滚动，不表示单张卡片左图右文。若设计不含系统导航，显式page-meta.content.show_nav=0，不能保留默认导航再另加大标题。',
            'visual_repair'=>'复审先核对实际截图覆盖整页、图片完整、背景与字可见；逐区指出导航重复、默认占位标题、裁切/比例、布局方向、内卡大小和图标样式差异。本轮修改过的视觉必须重新截图，不能根据改写参数假设修复成功。需要复现批准图中的复杂图标或插图时，按批准设计参考生成独立素材，不用单色glyph冒称复杂图形已经一致。',
            'component_combinations'=>'入口font_size控制每项标签，不改变组件内置区块标题的固定字号；需要较大的独立区块标题时，将creation-entry-grid.title置空并在前面组合title-bar与显式font_size。banner純图主视觉设show_caption=0。图左文右灵感卡用media-card.layout=list并明确image_width/image_height；需要横向滑动露出下一卡时追加list_scroll=1，合法素材可以复用但不虚构案例或业务事实。',
            'theme_defaults'=>'组件factory中的styles.color是显式默认文字色，不会自动被页面或导航文字色覆盖。深浅主题逐块核对外壳背景及实际styles.color的对比；支持item_background/description_color的内卡分别配置。不支持内卡配色的组件按runtime_notes/runtime_capabilities真实边界设计，不能声称全局继承或整块文字都已换色。',
            'media'=>'decorative是系统装饰素材，不是用户作品、客户案例或业务证明；user_case才能按真实案例展示。banner无图片不能承担欢迎文字；无真实AI教程不得把普通生活文章包装成教程。',
            'source'=>'ai_tools只含推荐配置，空时使用真实uniapp app_entries或合法links；用户资料/会员/额度/用户资产依赖登录用户，管理员预览空不代表接口失效。manual也必须通过bind_source，field_map不支持写入。',
            'assets'=>'只能用本轮material_summary/search_materials/icons明确给出的URL或用户明确上传素材；named icon值只来自当前组件lists.fields.icon.options，不写桌面图标名。',
            'identities'=>'组件id及所有列表行id均由服务端管理。add_component操作最外层可用ref别名，不传id；新增/整表替换的rows不含id，不复制默认值或数据源行id。修改已有列表用edit_list按index与fields，不更新id。',
            'references'=>'ref仅支持add_component操作最外层直接创建的组件，不支持replace_page.components或children里的ref。replace_page后在下一轮读取component_index真实ID再bind_source/update/request_image；需要同批引用可改用多个add_component最外层ref。',
            'image_tasks'=>'request_image返回slot_id、widget_id、field、status；queued/running/submitting表示该位置已有任务，不要重复请求。编排结束可final operations=[]，服务端会等待本轮图片完成；排队不是生成完成。',
            'page_theme'=>'页面背景与导航背景各自按page_meta_contract配置，区块背景不能替代页面背景；完整主题需同时核对页面、导航及各组件实际前景/背景。',
        ];
    }

    private static function semantics(string $name): string
    {
        return match($name){
            'search'=>'搜索入口；placeholder/height真实消费，公共端可导航搜索，共享端仅示意；热点/按钮显隐未消费，不能承诺。',
            'fixed-search'=>'搜索展示；fixed_position当前未消费，不宣称固定定位；具体端交互见runtime_notes。',
            'banner'=>'图片主视觉轮播；data.image实际展示，image_height/autoplay/interval/indicator控制呈现，文字另用标题/富文本。',
            'carousel-search'=>'轮播主视觉叠加搜索；图片用data.image，搜索及轮播参数按条件显示，注意遮挡与文字对比。',
            'nav'=>'图文导航；行image与title/link形成真实入口，列数及图标尺寸按契约。',
            'news'=>'文章资讯列表及文章详情跳转；limit/show_image等控制布局，不用于图片视频案例。',
            'middle-banner'=>'中部图片广告，行image展示，点击合法link；不得把装饰图描述成业务案例。',
            'title-bar'=>'标题/副标题/对齐；用文字层级划分区域，content.link当前未消费，不承诺标题点击。',
            'notice'=>'公告文字滚动或静态，显式font_size触发字体模式；公告内容真实，链接可选。',
            'list-nav'=>'纵向列表导航，行标题/说明/图片/合法链接；适合少量重点服务，不当瀑布流。',
            'image-hotspot'=>'单图热区；content.image必须存在，areas按图内坐标/尺寸/合法链接校验，不臆造热点。',
            'image-showcase'=>'图片展播；行image、文字/标签显隐，展示真实图库/案例或清楚标识装饰图片。',
            'divider'=>'辅助分隔线；style划分区块，height未消费；线色端差异见runtime_notes，无图片或交互。',
            'user-info'=>'登录用户头像/资料区域；user_profile依赖当前登录者，后台预览不编造用户。',
            'my-service'=>'个人服务入口列表；行标题/图片/链接来自实际服务，布局按契约，不能虚构账户权益。',
            'user-banner'=>'个人中心图片广告位；data.image及link是真实素材/合法入口，不假造会员权益。',
            'customer-service'=>'客服信息、二维码和联系行为；qrcode只能用真实客服二维码，不能生成随机码。',
            'rich-text'=>'文字内容块；text纯文本，html仅无属性格式标签；颜色/间距用styles，不把标签写进text。',
            'spacer'=>'辅助留白高度；用于节奏与间距，无内容图片或交互，不用空卡片代替。',
            'audio-player'=>'音频播放；audio_url真实可播放，loop按契约；cover未被两端渲染，不作为生成位置。',
            'button-group'=>'快捷按钮组；button_type决定image或文字glyph，columns/字号/形状影响布局，点击合法link。',
            'tabs'=>'分类标签切换；data.title/link与active按真实分类或入口，不拿图文案例直接充分类。',
            'video-player'=>'视频播放；video_url真实视频，cover是播放器封面，autoplay/控制按契约；title/aspect_ratio未消费，图片不代表视频已生成。',
            'floating-action'=>'浮动动作按钮；type与icon_mode/system/image决定图形，按钮位置/大小/间距不能挡内容，电话/客服真实。',
            'popup-promo'=>'文档流推广卡；content.image为背景，点击合法link；当前没有弹窗生命周期，trigger/delay/closeable未消费。',
            'section'=>'通用区域容器；children承载独立组件，background/padding统一分组，遵循最大深度与selected范围。',
            'grid'=>'栅格容器；children按列数/间距排列，直接子项styles.grid_column控制占列（显式0整行；缺省兼容旧span），保持移动端宽度和嵌套范围。',
            'stack'=>'堆叠容器；children层叠排列，注意层级/偏移/尺寸不遮住操作，遵循容器限制。',
            'horizontal-scroll'=>'横滑容器；children水平滚动，可选item_width正值按rpx指定直接子卡宽，0或缺省保留78%；间距决定可见节奏，内容可真实点击。',
            'masonry'=>'瀑布流容器；children按列错位排列，适合不同图片高度，保留真实封面和链接。',
            'card'=>'卡片容器；children在卡片内组合，统一背景/圆角/边距，避免多层重复空壳。',
            'image-nav'=>'图片导航入口；data.image用真实图片/图标，row.link必须合法，文字标签不烧入图片。',
            'magic-grid'=>'魔方宫格；按areas位置/比例形成图片布局，data.image展示，图片与点击link逐项对应。',
            'tutorial-carousel'=>'真实教程横滑；card_width等单位按契约，image为封面，只有确认的AI教程才按教程展示，否则诚实空态。',
            'case-feed'=>'真实媒体案例瀑布流；image是案例封面，跳案例页面，不能伪造用户作品或把文章当媒体案例。',
            'app-collection'=>'已开通应用集合；来源uniapp app_entries，真实名字/图标/合法路径，不能使用PC路径导航移动端。',
            'creation-entry-grid'=>'创作工具入口宫格；named icon或row.image，columns/字体/图标大小/显隐控制布局，使用真实应用链接。',
            'recent-tasks'=>'当前登录用户最近任务；recent_tasks依赖用户，状态/时间真实，空时不伪造任务。',
            'media-card'=>'媒体内容卡片；真实image封面、标题/说明/link，与来源类型一致，装饰素材要明确身份。',
            'user-hero'=>'用户个人头图；profile/会员展示依赖登录用户，装饰背景与真实头像/用户数据区分。',
            'membership-card'=>'当前登录用户会员摘要；套餐/期限来自membership_summary，卡片/按钮按契约，不造权益或付款成功。',
            'commerce-entries'=>'购买/充值/分销等真实商业入口；功能开关与合法链接决定可见，不虚构交易权益。',
            'user-stats'=>'登录用户指标布局；数值/标签从真实来源，列数和对比度按契约，后台预览空不补假数字。',
            'account-quota'=>'当前登录用户额度摘要；account_summary真实点数，登录后解析，不写虚构余额。',
            'asset-entries'=>'当前用户资产入口；user_assets与登录身份绑定，图标/图片只作入口图形，不伪造作品数量。',
            'service-grid'=>'真实服务宫格；named icon或row.image与合法link对应，按入口数量选择布局，不能虚构客服/个人数据。',
            default=>throw new \InvalidArgumentException('组件缺少布局语义：'.$name),
        };
    }

    public static function namedIcons(?array $definitions=null,string $query=''): array
    {
        $result=[];
        foreach($definitions??DecorationAgentCatalog::summary()['components'] as $definition)foreach($definition['lists']??[] as $key=>$list){
            $options=$list['fields']['icon']['options']??[];
            $options=self::matchIcons($options,$query);
            if(!$options||in_array($definition['name'],['button-group','floating-action'],true))continue;
            $result[]=['component'=>$definition['name'],'field'=>'content.'.$key.'.*.icon','kind'=>'named_icon','options'=>$options,'description'=>'仅使用当前组件契约注册的系统图标名称；不是图片URL。'];
        }
        return $result;
    }

    private static function matchIcons(array $options,string $query): array
    {
        if(trim($query)==='')return $options;
        $terms=preg_split('/[\s,，、|]+/u',mb_strtolower(trim($query)))?:[];
        foreach(['文字|写作|文案|编辑'=>'文字 编辑 写作 文案 edit pen','图片|绘图|图像'=>'图片 绘图 图像 photo image','视频|播放'=>'视频 播放 play video','入口|工具|应用|宫格'=>'入口 工具 应用 宫格 grid','用户|个人|账户'=>'用户 个人 账户 account user','客服|帮助|支持'=>'客服 帮助 支持 kefu service'] as $pattern=>$synonyms)if(preg_match('/'.$pattern.'/u',$query))$terms=array_merge($terms,explode(' ',$synonyms));
        return array_values(array_filter($options,function($option)use($terms){$text=mb_strtolower((string)($option['value']??'').' '.(string)($option['label']??'').' '.implode(' ',(array)($option['tags']??[])));foreach($terms as $term)if($term!==''&&str_contains($text,$term))return true;return false;}));
    }

    public static function icons(string $query=''): array
    {
        $legacy=[
            ['component'=>'button-group','field'=>'content.data.*.icon','mode_fields'=>['content.button_type'=>'icon'],'options'=>[['value'=>'⌕','label'=>'搜索'],['value'=>'▤','label'=>'资讯'],['value'=>'▧','label'=>'图片'],['value'=>'★','label'=>'收藏'],['value'=>'◉','label'=>'客服']]],
            ['component'=>'floating-action','field'=>'content.data.*.icon','mode_fields'=>['content.data.*.icon_mode'=>'system'],'options'=>[['value'=>'auto','label'=>'跟随操作'],['value'=>'link','label'=>'跳转'],['value'=>'back_top','label'=>'回到顶部'],['value'=>'service','label'=>'客服'],['value'=>'phone','label'=>'电话'],['value'=>'home','label'=>'首页'],['value'=>'star','label'=>'收藏'],['value'=>'message','label'=>'消息'],['value'=>'none','label'=>'无图标']]],
            ['component'=>'nav','field'=>'content.data.*.image','description'=>'使用授权素材图片；不支持桌面Element图标名称'],
        ];
        $options=[];
        foreach([
            ['nav01','文字文档',['文字','文档']],['nav02','收藏',['收藏','灵感']],['nav03','个人',['个人','账户']],
            ['nav04','客服',['客服','帮助']],['nav05','说明',['说明','教程']],['wallet','钱包',['钱包','额度']],
        ] as [$file,$label,$tags]){
            $uri='resource/image/tenantapi/default/'.$file.'.png';$info=@getimagesize(public_path().$uri);
            if(!$info)continue;
            if($query!==''&&!str_contains($label.implode(' ',$tags),$query))continue;
            $options[]=['value'=>'/'.$uri,'url'=>'/'.$uri,'uri'=>$uri,'label'=>$label,'type'=>'image','role'=>'system_icon','tags'=>$tags,'width'=>$info[0],'height'=>$info[1]];
        }
        $image=[];
        foreach(['nav','creation-entry-grid','service-grid','app-collection','asset-entries'] as $name){
            try{$definition=DecorationAgentCatalog::definition($name);}catch(\InvalidArgumentException $e){continue;}
            if(!isset($definition['lists']['data']['fields']['image']))continue;
            $image[]=['component'=>$name,'field'=>'content.data.*.image','kind'=>'image_icon','options'=>$options,'description'=>'真实系统PNG图标，URL须来自本轮明确批准列表；不是案例素材。'];
        }
        return array_merge($legacy,self::namedIcons(null,$query),$image);
    }

    public static function iconSummary(array $groups): array
    {
        $palettes=[];$bindings=[];$legacy=[];
        foreach($groups as $group){
            if(!isset($group['kind'])){$legacy[]=$group;continue;}
            $options=$group['options']??[];$id=$group['kind'].':'.substr(hash('sha256',json_encode($options,JSON_UNESCAPED_UNICODE)),0,12);
            $palettes[$id]??=['kind'=>$group['kind'],'options'=>$options];
            $bindings[]=['component'=>$group['component'],'field'=>$group['field'],'palette'=>$id];
        }
        return ['palettes'=>$palettes,'bindings'=>$bindings,'legacy'=>$legacy];
    }

    public static function iconUrls(array $icons): array
    {
        $urls=[];
        foreach($icons as $group)if(($group['kind']??'')==='image_icon')foreach($group['options']??[] as $option)if(isset($option['url']))$urls[]=$option['url'];
        return array_values(array_unique($urls));
    }
}
