<?php
namespace app\common\service\open_platform;
use think\facade\Db;
final class OpenContracts
{
    public static function validate(array $input): array
    {
        $kind=(string)($input['kind']??'market');
        $path=(string)($input['path']??'');$method=strtoupper((string)($input['method']??''));$code=(string)($input['code']??'');$config=(array)($input['config']??[]);
        if($kind==='tool'){$registered=OpenToolRegistry::match($method,$path);$config['executor']='tool';$config['billable']=$registered['billable'];$config['app_code']=$registered['app_code'];$config['route']=$registered['route'];}elseif($kind!=='market')throw new OpenException('invalid_contract','接口种类无效');
        if(($kind==='market'&&!preg_match('#^/api/v1/(?:models(?:/detail)?|chat/completions|completions|responses|messages|tasks(?:/\{task_id\}(?:/cancel)?)?|apps(?:/[a-z0-9_-]+(?:/[a-z0-9_-]+)?)?|files(?:/\{file_id\})?|upload|user/(?:balance|usage))$#D',$path))||!in_array($method,['GET','POST','DELETE','PUT'],true)||!preg_match('/^[a-z0-9_.-]{1,160}$/D',$code))throw new OpenException('invalid_contract','接口方法、路径或编码不在可注册范围');
        if($kind==='market'&&($config['executor']??'')==='tool')throw new OpenException('invalid_contract','商城接口不可使用工具执行器');
        if(isset($config['native_pricing'])&&($kind!=='market'||!in_array($config['native_pricing'],[OpenNativeMarketPricing::ADAPTER,OpenCharacterInputPricing::ADAPTER],true)||($config['executor']??'')!=='json'||empty($config['billable'])||($config['role']??'')!=='submit'))throw new OpenException('invalid_contract','原生价格适配未注册或生命周期不匹配');
        if(($config['native_pricing']??'')===OpenCharacterInputPricing::ADAPTER){if($method!=='POST'||!preg_match('#^/api/v1/apps/voice_tts/(tts|tts_async|tts_live)$#D',$path)||count($config['billing']??[])!==1||!is_array($config['character_defaults']??null)||($config['character_defaults_digest']??'')!==OpenPolicy::fingerprint($config['character_defaults'])||($config['response_adapter']??'')!==OpenMarketResponse::ADAPTER||!OpenMarketResponse::schemaSupported($config['responses']??[]))throw new OpenException('invalid_contract','字符适配必须绑定已审核 JSON TTS、单一 SKU 与冻结默认参数');OpenCharacterInputPricing::transportParameters($config,(array)($config['example']??[]));}
        if(isset($config['routing_channel'])&&($kind!=='market'||empty($config['model'])||!is_string($config['routing_channel'])||!preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$config['routing_channel'])))throw new OpenException('invalid_contract','服务端渠道路由无效');
        if(isset($config['response_adapter'])&&($kind!=='market'||$config['response_adapter']!==OpenMarketResponse::ADAPTER||($config['executor']??'')!=='json'))throw new OpenException('invalid_contract','响应适配器未注册或不支持该协议');
        if(!in_array($config['executor']??'',['json','sse','multipart','binary','tool','directory'],true))throw new OpenException('invalid_contract','执行器未注册');
        if(($config['executor']??'')==='directory'&&($kind!=='market'||!OpenDirectory::supports($method,$path)||!empty($config['billable'])||($config['role']??'')!=='resource'||(int)($input['product_id']??0)!==0))throw new OpenException('invalid_contract','目录执行器只支持固定的免费读取接口');
        if(!in_array($config['role']??'',['submit','query','cancel','resource'],true))throw new OpenException('invalid_contract','必须声明操作生命周期');
        if(!isset($config['input'],$config['example'],$config['responses'],$config['billable']))throw new OpenException('invalid_contract','必须声明参数、可执行示例、响应和计费规则');
        if(($config['input']['type']??'')!=='object')throw new OpenException('invalid_contract','输入必须声明对象结构');
        OpenPolicy::validateInput((array)$config['example']);OpenSchema::validate((array)$config['example'],$config['input']);
        $product=(int)($input['product_id']??0);if($kind==='market'&&!empty($config['billable'])){if($product<=0||empty($config['billing']))throw new OpenException('invalid_contract','计费接口必须绑定商城产品和 SKU');foreach($config['billing'] as $rule){if((int)($rule['sku_id']??0)<=0||!is_numeric($rule['max_units']??null)||(float)$rule['max_units']<=0||!in_array($rule['basis']??'',['input','usage'],true))throw new OpenException('invalid_contract','计费边界不完整');if($rule['basis']==='usage'&&empty($rule['usage_path']))throw new OpenException('invalid_contract','实际用量计费必须指定可信用量字段');}}
        if($kind==='market'&&in_array($config['role'],['query','cancel'],true)&&!str_contains($path,'{task_id}'))throw new OpenException('invalid_contract','任务操作必须通过本地归属映射解析');
        if(isset($config['query_path'])&&!preg_match('#^/api/v1/tasks/\{task_id\}$#D',$config['query_path']))throw new OpenException('invalid_contract','查询地址未注册');
        if($kind==='market'&&str_contains($path,'{file_id}'))throw new OpenException('adapter_required','上游文件归属适配器尚未发布');
        if($kind==='market'&&$config['executor']!=='directory'&&$method==='GET'&&in_array($path,['/api/v1/tasks','/api/v1/models','/api/v1/apps'],true))throw new OpenException('adapter_required','列表接口必须使用本地权限目录适配器');
        foreach(['upstream_url','api_key','executor_class','credentials'] as $unsafe)if(isset($config[$unsafe]))throw new OpenException('invalid_contract','契约不能包含凭据或任意执行器');
        return ['code'=>$code,'path'=>$path,'method'=>$method,'kind'=>$kind,'product_id'=>$product,'config'=>self::encodeConfig($config)];
    }
    public static function contentType(array $config): string
    {
        if(($config['executor']??'')==='multipart')return 'multipart/form-data';
        foreach($config['input']['properties']??[] as $field)if(($field['format']??'')==='binary'||($field['items']['format']??'')==='binary')return 'multipart/form-data';
        return 'application/json';
    }
    public static function save(array $input): array
    {
        $values=self::validate($input);return Db::transaction(function()use($input,$values){$id=(int)($input['id']??0);$old=$id?OpenStore::table('contract')->where('id',$id)->lock(true)->find():null;if($old&&$old['status']!=='draft')throw new OpenException('immutable_version','已发布契约不可修改，请新建版本',409);$version=$old?(int)$old['version']:((int)OpenStore::table('contract')->where('code',$values['code'])->max('version')+1);if($old)OpenStore::table('contract')->where('id',$id)->update($values+['update_time'=>time()]);else $id=OpenStore::table('contract')->insertGetId($values+['version'=>$version,'status'=>'draft']+OpenStore::timestamp());return self::detail($id);});
    }
    public static function decodeConfig(array|string $value): array
    {
        if(is_array($value))return $value;
        $typed=json_decode($value);$config=self::legacyConfig($value);
        return $typed instanceof \stdClass?self::restoreInstances($config,$typed):$config;
    }
    /** Keep published receipt fingerprints on their historical representation. */
    public static function legacyConfig(array|string $value): array
    {
        if(is_array($value)){
            $config=$value;
            foreach(['responses','query_responses'] as $field)foreach((array)($config[$field]??[]) as $status=>$response)foreach((array)($response['content']??[]) as $type=>$media){
                if(!is_array($media))continue;
                if(array_key_exists('example',$media))$config[$field][$status]['content'][$type]['example']=json_decode(json_encode($media['example'],JSON_THROW_ON_ERROR),true);
                foreach((array)($media['examples']??[]) as $name=>$example)if(is_array($example)&&array_key_exists('value',$example))$config[$field][$status]['content'][$type]['examples'][$name]['value']=json_decode(json_encode($example['value'],JSON_THROW_ON_ERROR),true);
            }
            return $config;
        }
        $typed=json_decode($value);$config=OpenStore::json($value);
        if(isset($typed->example)&&$typed->example instanceof \stdClass)$config['example']=(array)$typed->example;
        return $config;
    }
    /** Preserve whole JSON instances, including null/false/zero, without changing schema maps. */
    private static function restoreInstances(array $config, \stdClass $typed): array
    {
        if(isset($typed->example)&&$typed->example instanceof \stdClass)$config['example']=(array)$typed->example;
        foreach(['responses','query_responses'] as $field){
            $responses=$typed->{$field}??null;if(!$responses instanceof \stdClass)continue;
            foreach(get_object_vars($responses) as $status=>$response){
                $content=$response instanceof \stdClass?($response->content??null):null;if(!$content instanceof \stdClass)continue;
                foreach(get_object_vars($content) as $type=>$media){
                    if(!$media instanceof \stdClass)continue;
                    if(property_exists($media,'example'))$config[$field][$status]['content'][$type]['example']=$media->example;
                    $examples=$media->examples??null;if(!$examples instanceof \stdClass)continue;
                    foreach(get_object_vars($examples) as $name=>$example)if($example instanceof \stdClass&&property_exists($example,'value'))$config[$field][$status]['content'][$type]['examples'][$name]['value']=$example->value;
                }
            }
        }
        return $config;
    }
    public static function encodeConfig(array $config): string
    {
        return json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);
    }
    /** Preserve reviewed JSON examples when the framework decodes the management body as arrays. */
    public static function requestInput($request): array
    {
        $input=$request->post();$raw=json_decode($request->getInput());
        if(($raw->config??null) instanceof \stdClass)$input['config']=self::restoreInstances((array)($input['config']??[]),$raw->config);
        return $input;
    }
    public static function detail(int $id): array {$row=OpenStore::table('contract')->where('id',$id)->find();if(!$row)throw new OpenException('not_found','接口契约不存在',404);$row['config']=self::decodeConfig($row['config']);return $row;}
    public static function publish(int $id): array
    {
        return Db::transaction(function()use($id){$row=self::detail($id);self::validate($row);if($row['status']!=='draft')throw new OpenException('invalid_contract','只可发布草稿',409);
            if($row['kind']==='market'&&$row['config']['executor']==='json'&&(str_starts_with($row['path'],'/api/v1/apps/')||str_starts_with($row['path'],'/api/v1/tasks'))&&(($row['config']['response_adapter']??'')!==OpenMarketResponse::ADAPTER||!OpenMarketResponse::schemaSupported($row['config']['responses'])))throw new OpenException('adapter_required','商城业务协议必须使用已验证的用户费用响应适配');
            self::assertPublishableRoute($row);
            $doc=OpenStore::table('document')->where(['tenant_id'=>0,'contract_code'=>$row['code'],'status'=>'published'])->find();if(!$doc)throw new OpenException('missing_document','请先发布对应接口文档');
            $row['config']['verification']=OpenVerification::assertReceipt($row);
            OpenStore::table('contract')->where('id',$id)->update(['config'=>self::encodeConfig($row['config'])]);
            OpenStore::table('contract')->where(['code'=>$row['code'],'status'=>'published'])->update(['status'=>'archived','update_time'=>time()]);OpenStore::table('contract')->where('id',$id)->update(['status'=>'published','update_time'=>time()]);return self::detail($id);});
    }
    private static function assertPublishableRoute(array $row): void
    {
        // Use a locking read for the whole route, including competing drafts.
        $peers = OpenStore::table('contract')->where([
            'kind'=>$row['kind'],'method'=>$row['method'],'path'=>$row['path'],
        ])->order('id', 'asc')->lock(true)->select()->toArray();
        $draftLocked = false;
        foreach ($peers as $peer) {
            if ($peer['id'] === $row['id']) {
                $draftLocked = $peer['status'] === 'draft'
                    && $peer['code'] === $row['code']
                    && (int)$peer['product_id'] === (int)$row['product_id']
                    && self::encodeConfig(self::decodeConfig($peer['config'])) === self::encodeConfig($row['config']);
            }
            if ($peer['status'] !== 'published' || $peer['code'] === $row['code']) continue;
            if ($row['kind'] === 'tool' || self::selectorsOverlap($row['config'], OpenStore::json($peer['config']))) {
                throw self::ambiguous();
            }
        }
        if (!$draftLocked) throw new OpenException('invalid_contract', '草稿已变化，请刷新后重新发布', 409);
    }

    private static function selectorConditions(array $config): array
    {
        $conditions = [];
        if (isset($config['model'])) $conditions['model'][] = [$config['model'], $config['model'] === ''];
        if (isset($config['routing_channel'])) $conditions['channel'][] = [$config['routing_channel'], true];
        foreach ($config['when'] ?? [] as $name => $value) {
            $missing = $config['when_defaults'][$name] ?? ($name === 'stream' ? false : null);
            $conditions[$name][] = [$value, $missing === $value];
        }
        return $conditions;
    }

    /** Reject ambiguous published selectors, including collisions on omitted fields. */
    private static function selectorsOverlap(array $left, array $right): bool
    {
        $a = self::selectorConditions($left);
        $b = self::selectorConditions($right);
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $field) {
            $conditions = [...($a[$field] ?? []), ...($b[$field] ?? [])];
            $first = $conditions[0][0];
            $omittable = true;
            $sameValue = $first !== null;
            foreach ($conditions as [$value, $acceptsMissing]) {
                $omittable = $omittable && $acceptsMissing;
                $sameValue = $sameValue && $value === $first;
            }
            if (!$omittable && !$sameValue) return false;
        }
        return true;
    }

    private static function ambiguous(): OpenException
    {
        return new OpenException('ambiguous_contract', '当前请求匹配到多个接口契约，请明确模型渠道或规格', 409);
    }

    public static function tool(string $method, string $path): array
    {
        $registered = OpenToolRegistry::match($method, $path);
        $matched = null;
        foreach (self::publishedRows('tool', $method, $path) as $row) {
            $bindings = OpenPolicy::matches($row['path'], $path);
            if ($bindings === null) continue;
            if ($matched !== null) throw self::ambiguous();
            $matched = self::operation($row, OpenStore::json($row['config']), $bindings, $registered);
        }
        if ($matched === null) throw new OpenException('not_found','工具操作尚未发布',404);
        return $matched;
    }

    public static function selected(array $config,array $params): bool
    {
        if(isset($config['model'])&&($params['model']??'')!==$config['model'])return false;if(isset($config['routing_channel'],$params['channel'])&&$params['channel']!==$config['routing_channel'])return false;
        foreach($config['when']??[] as $name=>$value){$actual=OpenSchema::get($params,$name)??($config['when_defaults'][$name]??($name==='stream'?false:null));if($actual!==$value)return false;}
        return true;
    }

    private static function publishedRows(string $kind, string $method, string $path): array
    {
        return OpenStore::table('contract')->where(['status'=>'published','method'=>$method,'kind'=>$kind])
            ->where(fn($query) => $query->where('path', $path)->whereOr('path', 'like', '%{%'))
            ->select()->toArray();
    }

    private static function operation(array $row, array $config, array $bindings, ?array $registered=null): array
    {
        $identity=['kind'=>$row['kind'],'contract_id'=>$row['id'],'product_id'=>$row['product_id'],'code'=>$row['code'],
            'path'=>$row['path'],'method'=>$row['method'],'version'=>$row['version'],'params'=>$bindings];
        return $registered !== null ? array_replace($registered, $identity + ['input'=>$config['input']]) : $identity + $config;
    }

    public static function parameters(array $params, array $contract, bool $normalize): array
    {
        return $normalize ? OpenSchema::query($params, $contract['input']) : $params;
    }

    /** Match a concrete path against a published version or a verified draft. */
    public static function matchVersion(array $row, string $method, string $path, array $params, bool $normalize): ?array
    {
        if ($row['method'] !== $method) return null;
        $bindings = OpenPolicy::matches($row['path'], $path);
        if ($bindings === null) return null;
        $config = OpenStore::json($row['config']);
        if (!self::selected($config, self::parameters($params, $config, $normalize))) return null;
        $registered = $row['kind'] === 'tool' ? OpenToolRegistry::match($method, $path) : null;
        return self::operation($row, $config, $bindings, $registered);
    }

    public static function match(string $method, string $path, array $params, bool $normalize=false): array
    {
        $matched = null;
        foreach (self::publishedRows('market', $method, $path) as $row) {
            $candidate = self::matchVersion($row, $method, $path, $params, $normalize);
            if ($candidate === null) continue;
            if ($matched !== null) throw self::ambiguous();
            $matched = $candidate;
        }
        if ($matched === null) throw new OpenException('not_found','接口尚未发布或模型未开放',404);
        return $matched;
    }

    public static function expectCode(array $operation, string $code): void
    {
        if ($code !== '' && ($operation['code'] ?? '') !== $code) {
            throw new OpenException('contract_mismatch', '请求参数命中的接口与所选契约不一致', 409);
        }
    }

    public static function forQuote(string $code, array $params): array
    {
        $row = OpenStore::table('contract')->where(['code'=>$code,'kind'=>'market','status'=>'published'])
            ->order('version', 'desc')->find();
        if (!$row) throw new OpenException('not_found', '请选择已发布的商城接口', 404);
        $config = OpenStore::json($row['config']);
        $normalize = $row['method'] === 'GET' || self::contentType($config) === 'multipart/form-data';
        // The console sends URL/form values as text, even when its editor uses JSON.
        if ($normalize) {
            $params = array_map(static fn($value) => is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR), $params);
        }
        try {
            // Historical operations quote zero and use the owned task receipt at execution time.
            $operation = in_array($config['role'], ['query','cancel'], true)
                ? self::matchVersion($row, $row['method'], $row['path'], $params, $normalize)
                : self::match($row['method'], $row['path'], $params, $normalize);
        } catch (OpenException $error) {
            if ($error->errorType !== 'not_found') throw $error;
            $operation = null;
        }
        if ($operation === null) throw new OpenException('invalid_request','参数不符合当前选择的接口');
        self::expectCode($operation, $code);
        return [$operation, self::parameters($params, $operation, $normalize)];
    }
}
