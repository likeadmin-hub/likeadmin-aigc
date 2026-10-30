<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Material fields are native contracts, not interchangeable URL aliases. */
final class MarketGenerationMaterials
{
    public static function contract(array $metadata): array
    {
        $reviewed=MarketGenerationReviewedContract::selected($metadata);
        if ($reviewed!==null) return $reviewed['material_capabilities'];
        return MarketGenerationSchema::array($metadata['material_capabilities'] ?? $metadata['upstream_metadata']['material_capabilities'] ?? []);
    }

    public static function modes(array $metadata): array
    {
        // A catalogue validates many specifications against the same declaration.
        // Include all metadata so a changed contract never reuses an older result.
        return MarketCatalogReadScope::derive('material_modes', $metadata, static fn(): array => self::buildModes($metadata));
    }

    private static function buildModes(array $metadata): array
    {
        $result = [];
        $reviewed=MarketGenerationReviewedContract::selected($metadata)!==null;
        $declared = (array)(self::contract($metadata)['modes'] ?? []);
        $expanded = [];
        foreach ($declared as $mode) {
            if (!is_array($mode)) continue;
            $fields = array_values(array_filter((array)($mode['fields'] ?? []), static fn($f)=>is_array($f) && ($reviewed || !str_contains((string)($f['field'] ?? ''), '.'))));
            $frames = array_values(array_filter($fields, static fn($f)=>in_array($f['role'] ?? '', ['first','last'],true)));
            if (empty($mode['view_key']) && count($frames) === 2 && !array_filter($frames, static fn($f)=>!empty($f['required']))) {
                $base = array_values(array_filter($fields, static fn($f)=>!in_array($f['role'] ?? '', ['first','last'],true)));
                $expanded[] = array_replace($mode, ['fields'=>$base,'first_last_frame'=>'不支持','view_key'=>($mode['code'] ?? 'default').':reference']);
                foreach (['first'=>'image_to_video','last'=>'last_frame','pair'=>'start_end'] as $view=>$method) {
                    if ($view === 'pair' && ($mode['first_last_frame'] ?? '') !== '支持') continue;
                    $selected = $base;
                    foreach ($frames as $frame) if ($view === 'pair' || $frame['role'] === $view) $selected[] = array_replace($frame,['required'=>true,'minimum'=>1]);
                    $expanded[] = array_replace($mode,['fields'=>$selected,'method'=>$method,'view_key'=>($mode['code'] ?? 'default').':'.$view]);
                }
            } else $expanded[] = $mode;
        }
        foreach ($expanded as $index => $mode) {
            if (!is_array($mode)) continue;
            $fields = array_values(array_filter((array)($mode['fields'] ?? []), static fn($f) => is_array($f) && ($reviewed || !str_contains((string)($f['field'] ?? ''), '.'))));
            $roles = array_column($fields, 'role');
            $kinds = array_unique(array_column($fields, 'kind'));
            $code = (string)($mode['code'] ?? '');
            if (!empty($mode['method'])) $method = $mode['method'];
            elseif (in_array($code, ['text_to_video','text_2_video','text_to_image'], true) || str_ends_with((string)($mode['view_key'] ?? ''), ':text')) $method = 'text_to_video';
            elseif ($code === 'reference_to_video') $method = count($kinds) > 1 ? 'omni_reference' : (($kinds[0] ?? '') === 'video' ? 'video_edit' : 'image_reference');
            elseif (($mode['first_last_frame'] ?? '') === '支持' && (in_array('first', $roles, true) || in_array('frames', $roles, true))) $method = 'start_end';
            elseif (in_array('last', $roles, true) && !in_array('first', $roles, true)) $method = 'last_frame';
            elseif (in_array('first', $roles, true) || in_array('frames', $roles, true)) $method = 'image_to_video';
            elseif ($fields === [] || (!array_filter($fields,static fn($f)=>!empty($f['required'])) && !array_diff($roles,['keyframe']))) $method = 'text_to_video';
            elseif (count($kinds) > 1 || array_intersect($kinds, ['subject','mixed','character','file'])) $method = 'omni_reference';
            else $method = match ($kinds[0] ?? '') { 'video'=>'video_edit', 'audio'=>'audio_reference', default=>'image_reference' };
            $fingerprint = json_encode([$code, $method, $fields]);
            if (isset($result[$fingerprint])) continue;
            $result[$fingerprint] = array_merge($mode, ['key'=>(string)($mode['view_key'] ?? ($code ?: 'default') . ':' . $index), 'native_mode'=>$code, 'method'=>$method, 'fields'=>$fields, 'parameter_schema'=>MarketGenerationNativeContract::schema($metadata, $code)]);
        }
        return array_values($result);
    }

    public static function limit(array $metadata, string $kind): int
    {
        $limit = 0;
        foreach (self::modes($metadata) as $mode) {
            $total = 0;
            foreach ($mode['fields'] as $field) if (($field['kind'] ?? '') === $kind) $total += max(0, (int)($field['maximum'] ?? $field['minimum'] ?? 0));
            $limit = max($limit, $total);
        }
        return $limit;
    }

    public static function select(array $metadata, array $request): ?array
    {
        $modes = self::modes($metadata);
        if ($modes === []) return null;
        $method = (string)($request['generation_method'] ?? '');
        $key = (string)($request['material_mode'] ?? $request['provider_params']['material_mode'] ?? '');
        $native = (string)($request['generation_type'] ?? $request['provider_params']['generation_type'] ?? '');
        $candidates = array_values(array_filter($modes, static fn($mode) => ($key === '' || $key === $mode['key']) && ($method === '' || $method === $mode['method']) && ($native === '' || $native === $mode['native_mode'])));
        if ($candidates === []) throw new InvalidArgumentException('当前模型不支持所选生成方式');
        $assets = self::assets($request);
        $values = MarketGenerationSchema::array($request['provider_params'] ?? []);
        $strictRoles=MarketGenerationReviewedContract::selected($metadata)!==null;
        $best = null; $score = -PHP_INT_MAX;
        foreach ($candidates as $mode) {
            $current = 0;
            foreach ($mode['fields'] as $field) {
                $matched = self::matching($assets, $field, $mode['method'],$strictRoles);
                if (MarketGenerationMaterialPath::count($values,$field)>0) $current += 8;
                elseif ($matched !== []) $current += 4;
                elseif (!empty($field['required'])) $current -= 20;
            }
            if ($assets === [] && $values === [] && $mode['fields'] === []) $current += 20;
            if ($current > $score) { $score = $current; $best = $mode; }
        }
        return $best;
    }

    public static function assets(array $request): array
    {
        $assets = [];
        foreach ((array)($request['reference_assets'] ?? []) as $asset) {
            if (!is_array($asset)) continue;
            $url = (string)($asset['url'] ?? $asset['uri'] ?? '');
            if ($url === '') continue;
            $assets[] = $asset + ['url'=>$url, 'type'=>'image', 'role'=>'reference_'.($asset['type']??'image')];
        }
        foreach ($assets === [] ? (array)($request['reference_images'] ?? []) : [] as $url) {
            if (is_string($url) && $url !== '') $assets[] = ['url'=>$url,'type'=>'image','role'=>'reference_image'];
        }
        return $assets;
    }

    /** Preparation must reject material a legacy adapter would silently discard. */
    public static function assertPreserved(array $request, array $native, ?callable $canonicalUrl = null): void
    {
        $expected = array_count_values(array_map(static fn($a)=>trim((string)($canonicalUrl !== null ? $canonicalUrl($a) : $a['url'])), self::assets($request)));
        if ($expected === []) return;
        $actual = [];
        $walk = static function (array $values) use (&$walk, &$actual): void {
            foreach ($values as $key=>$value) {
                if (in_array($key, ['prompt','text','negative_prompt','description','callback_url','webhook'], true)) continue;
                if (is_array($value)) $walk($value);
                elseif (is_string($value) && preg_match('~^(?:https?://|data:|asset://)~i', trim($value))) {
                    $url = trim($value); $actual[$url] = ($actual[$url] ?? 0) + 1;
                }
            }
        };
        $walk($native);
        foreach ($expected as $url=>$count) if (($actual[$url] ?? 0) < $count) {
            throw new InvalidArgumentException('所选模型或生成方式不支持部分参考素材，请调整素材或切换模型');
        }
    }

    private static function matching(array $assets, array $field, string $method,bool $strictRoles=false): array
    {
        $role = (string)($field['role'] ?? 'reference');
        $kind = (string)($field['kind'] ?? 'image');
        $name = (string)($field['field'] ?? '');
        $images = array_values(array_filter($assets, static fn($a) => ($a['type'] ?? 'image') === 'image'));
        return array_values(array_filter($assets, static function ($asset) use ($role,$kind,$name,$images,$method,$strictRoles): bool {
            if (($asset['type'] ?? 'image') !== $kind) return false;
            if (isset($asset['field']) && $asset['field'] !== '') {
                if($asset['field']!==$name)return false;
                if(!$strictRoles)return true;
            }
            $assetRole = (string)($asset['role'] ?? 'reference_' . $kind);
            if ($role === 'first') return $assetRole === 'first_frame_image' || ($method === 'image_to_video' && count($images) === 1 && $assetRole === 'reference_image');
            if ($role === 'last') return $assetRole === 'last_frame_image' || ($method === 'last_frame' && count($images) === 1 && $assetRole === 'reference_image');
            if ($role === 'frames') return in_array($assetRole, ['first_frame_image','last_frame_image','reference_image'], true);
            if ($role === 'reference') return $assetRole === 'reference_' . $kind;
            return $assetRole === $role || $assetRole === $role . '_' . $kind;
        }));
    }

    public static function serialize(array $metadata, array $request, array $nativeSchema = []): array
    {
        $mode = self::select($metadata, $request);
        if ($mode === null) return [];
        $schema = MarketGenerationSchema::properties($nativeSchema ?: MarketGenerationSchema::array($metadata['params_schema'] ?? []));
        $params = MarketGenerationSchema::array($request['provider_params'] ?? []);
        $assets = self::assets($request);
        $result = [];
        foreach ($mode['fields'] as $field) {
            $name = $field['field'];
            // Nested image bodies are built by their registered encoder and checked afterwards.
            if (str_contains($name,'.')) continue;
            if (array_key_exists($name, $params) && $params[$name] !== '' && $params[$name] !== []) { $result[$name] = $params[$name]; continue; }
            $matched = self::matching($assets, $field, $mode['method']);
            if ($matched === []) continue;
            if (empty($field['required']) && count($matched) > (int)($field['maximum'] ?? PHP_INT_MAX) && ($schema[$name]['type'] ?? '') !== 'array') continue;
            $definition = $schema[$name] ?? [];
            $urls = array_map(static fn($asset) => $asset['url'], $matched);
            if (($definition['type'] ?? '') === 'array') {
                $items = (array)($definition['items'] ?? []);
                if (($items['type'] ?? '') === 'object') {
                    $properties = MarketGenerationSchema::properties($items);
                    $urlKey = null;
                    foreach ($properties as $key => $_) if (preg_match('/(?:url|uri)$/i', $key)) { $urlKey = $key; break; }
                    if ($urlKey === null) continue; // Subjects/IDs need their own structured values.
                    $result[$name] = array_map(static function ($asset) use ($items,$urlKey): array {
                        $value = [$urlKey=>$asset['url']];
                        foreach ((array)($asset['parameters'] ?? []) as $key=>$item) if ($key !== $urlKey) $value[$key]=$item;
                        return MarketGenerationSchema::normalize($items, $value);
                    }, $matched);
                } else $result[$name] = $urls;
            } else {
                if (count($urls) > 1) throw new InvalidArgumentException('该素材输入只允许一个文件：' . $name);
                $result[$name] = $urls[0];
            }
        }
        if ($mode['native_mode'] !== '' && isset($schema['generation_type'])) $result['generation_type'] = $mode['native_mode'];
        return $result;
    }

    public static function billingInputs(array $metadata, array $request): array
    {
        $reviewed=MarketGenerationReviewedContract::selected($metadata);
        if($reviewed!==null&&$reviewed['generation_protocol']!=='native_v1'){
            self::assertSingleMaterialSource($metadata,$request);
            $mode=self::select($metadata,$request);$values=(array)($request['provider_params']??[]);$assets=self::assets($request);$video=false;$image=false;
            foreach((array)($mode['fields']??[])as$field){
                $present=MarketGenerationMaterialPath::count($values,$field)>0 || self::matching($assets,$field,$mode['method'],true)!==[];
                if($present&&$field['kind']==='video')$video=true;
                if($present&&$field['kind']==='image'&&in_array($field['role']??'',['first','frames'],true))$image=true;
            }
            return ['_billing_video_input'=>$video,'_billing_image_input'=>$image];
        }
        $values=self::serialize($metadata,$request);
        $video=false;
        foreach (['reference_video_urls','video_urls','video_url','video_list','first_clip_url'] as $key) if (!empty($values[$key])) $video=true;
        foreach ((array)($values['elements'] ?? []) as $subject) if (!empty($subject['video_url'])) $video=true;
        if(MarketGenerationMediaInputs::visualUrls($values)!==[]&&MarketGenerationMedia::inputs($values)['video']!==[])$video=true;
        return ['_billing_video_input'=>$video,'_billing_image_input'=>!empty($values['image_url'])];
    }

    public static function validate(array $metadata, array $request, array $payload): void
    {
        self::assertSingleMaterialSource($metadata,$request);
        $mode = self::select($metadata, $request);
        if ($mode === null) return;
        $overrides=(array)($metadata['_local_capability_overrides'] ?? []);
        if (($overrides['supports_first_last_frame'] ?? null)===false && $mode['method']==='start_end') throw new InvalidArgumentException('当前模型已停用首尾帧方式');
        $counts=['image'=>0,'video'=>0,'audio'=>0];
        foreach ($mode['fields'] as $field) if (isset($counts[$field['kind'] ?? ''])) $counts[$field['kind']]+=MarketGenerationMaterialPath::count($payload,$field);
        // Count encoded groups for local overrides, including requests using native parameters alone.
        if($overrides!==[])foreach($mode['fields']as$field){
            if(!in_array($field['kind']??'',['subject','character','mixed'],true))continue;
            foreach(MarketGenerationMaterialPath::values($payload,$field['field'],(array)($field['item_match']??[]))as$value){
                $media=MarketGenerationMedia::inputs([$field['field']=>$value]);
                foreach(array_keys($counts)as$kind)$counts[$kind]+=count($media[$kind]);
            }
        }
        if (($overrides['supports_reference_images'] ?? null)===false && $counts['image']>0) throw new InvalidArgumentException('当前模型已停用图片参考');
        foreach (['image'=>'max_reference_images','video'=>'max_reference_videos','audio'=>'max_reference_audios'] as $kind=>$key) if (array_key_exists($key,$overrides) && $counts[$kind]>(int)$overrides[$key]) throw new InvalidArgumentException('素材数量超过当前模型上限');
        foreach ($mode['fields'] as $field) {
            if (in_array($field['kind'] ?? '', ['file','link'], true)) {
                foreach (MarketGenerationMaterialPath::values($payload,(string)$field['field'],(array)($field['item_match'] ?? [])) as $value) {
                    foreach (is_array($value) ? $value : [$value] as $url) {
                        if (!is_string($url)) throw new InvalidArgumentException('参考文件或链接地址格式不正确');
                        MarketGenerationMaterialEncoding::assertDocumentUrl($url);
                    }
                }
            }
            $count = MarketGenerationMaterialPath::count($payload,$field);
            $provider=(array)($request['provider_params']??[]);
            if(MarketGenerationReviewedContract::selected($metadata)!==null&&str_contains($field['field'],'.')&&MarketGenerationMaterialPath::count($provider,$field)>0
                && MarketGenerationMaterialPath::values($provider,$field['field'],(array)($field['item_match']??[]))!==MarketGenerationMaterialPath::values($payload,$field['field'],(array)($field['item_match']??[])))
                throw new InvalidArgumentException('当前协议无法完整保留此原生素材参数，请使用统一素材输入');
            if(in_array($field['kind']??'',['file','link'],true)&&MarketGenerationReviewedContract::selected($metadata)!==null){
                foreach(MarketGenerationMaterialPath::values($payload,$field['field'],(array)($field['item_match']??[]))as$value)
                    foreach(is_array($value)?$value:[$value]as$url)MarketGenerationMaterialEncoding::assertDocumentUrl((string)$url);
            }
            $minimum = !empty($field['required']) ? max(1, (int)($field['minimum'] ?? 0)) : 0;
            $maximum = $field['maximum'] ?? null;
            if ($count < $minimum || ($maximum !== null && $count > (int)$maximum)) throw new InvalidArgumentException('素材数量不符合当前方式要求：' . $field['field']);
        }
        $declared = array_map([MarketGenerationMaterialPath::class,'signature'],$mode['fields']);
        foreach (self::modes($metadata) as $other) foreach ($other['fields'] as $field) {
            if (!in_array(MarketGenerationMaterialPath::signature($field), $declared, true) && MarketGenerationMaterialPath::count($payload,$field)>0) throw new InvalidArgumentException('当前生成方式不支持此素材：' . $field['field']);
        }
        $groups=[];foreach($mode['fields']as$field)if(!empty($field['item_match']))$groups[$field['field']]=($groups[$field['field']]??0)+MarketGenerationMaterialPath::count($payload,$field);
        foreach($groups as$path=>$count)if($count!==MarketGenerationMaterialPath::count($payload,['field'=>$path]))throw new InvalidArgumentException('当前生成方式包含未声明的素材分类');
        foreach (self::assets($request) as $asset) {
            $strictRoles=MarketGenerationReviewedContract::selected($metadata)!==null;
            if (!array_filter($mode['fields'], static fn($field) => self::matching([$asset], $field, $mode['method'],$strictRoles) !== [] || self::structuredAssetMatches($metadata,$mode,$field,$asset,$payload))) throw new InvalidArgumentException('当前生成方式不支持已添加的素材角色');
        }
    }

    /** Explicit grouping must bind the uploaded URL to a declared media slot in the actual native body. */
    private static function structuredAssetMatches(array $metadata,array $mode,array $field,array $asset,array $payload): bool
    {
        if(($asset['field']??'')!==$field['field'] || !in_array($field['kind']??'',['subject','character','mixed'],true))return false;
        $schema=MarketGenerationNativeContract::schema($metadata,(string)($mode['native_mode']??''));
        if($schema===[])$schema=MarketGenerationSchema::array($metadata['params_schema']??[]);
        $definitions=MarketGenerationMaterialPath::definitions($schema,$field['field'],(array)($field['item_match']??[]));
        $values=MarketGenerationMaterialPath::values($payload,$field['field'],(array)($field['item_match']??[]));
        foreach($definitions as$definition)foreach($values as$value)if(self::structuredUrlMatches($definition,$value,$asset))return true;
        return false;
    }

    private static function structuredUrlMatches(array $schema,$value,array $asset,string $kind=''): bool
    {
        if(($schema['type']??'')==='array'&&is_array($value)){
            foreach($value as$child)if(self::structuredUrlMatches((array)($schema['items']??[]),$child,$asset,$kind))return true;
        }elseif(($schema['type']??'')==='object'&&is_array($value)){
            foreach((array)($schema['properties']??[])as$key=>$child){
                if(!array_key_exists($key,$value))continue;
                $next=$key==='element_input_urls'?'visual':(preg_match('/video|clip/i',$key)?'video':(preg_match('/audio|voice/i',$key)?'audio':(preg_match('/image|frame/i',$key)?'image':$kind)));
                if(self::structuredUrlMatches((array)$child,$value[$key],$asset,$next))return true;
            }
        }elseif(($schema['type']??'')==='string'&&$kind!==''&&is_string($value)
            &&(($schema['format']??'')==='uri'||preg_match('/https?|oss|asset|data:/i',(string)($schema['pattern']??'')))){
            return $value===($asset['url']??$asset['uri']??'')&&($kind===($asset['type']??'image')||($kind==='visual'&&in_array($asset['type']??'image',['image','video'],true)));
        }
        return false;
    }

    private static function assertSingleMaterialSource(array $metadata,array $request): void
    {
        $reviewed=MarketGenerationReviewedContract::selected($metadata);
        if($reviewed===null||$reviewed['generation_protocol']==='native_v1'||self::assets($request)===[])return;
        $provider=(array)($request['provider_params']??[]);
        foreach(self::modes($metadata)as$mode)foreach($mode['fields']as$field)
            if(MarketGenerationMaterialPath::count($provider,$field)>0)throw new InvalidArgumentException('请勿同时使用业务素材和原生参数提供同一请求的素材');
    }
}
