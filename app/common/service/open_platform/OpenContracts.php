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
        return ['code'=>$code,'path'=>$path,'method'=>$method,'kind'=>$kind,'product_id'=>$product,'config'=>OpenStore::encode($config)];
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
    public static function detail(int $id): array {$row=OpenStore::table('contract')->where('id',$id)->find();if(!$row)throw new OpenException('not_found','接口契约不存在',404);$typed=json_decode($row['config']);$row['config']=OpenStore::json($row['config']);if(isset($typed->example)&&$typed->example instanceof \stdClass)$row['config']['example']=(array)$typed->example;return $row;}
    public static function publish(int $id): array
    {
        return Db::transaction(function()use($id){$row=self::detail($id);self::validate($row);if($row['status']!=='draft')throw new OpenException('invalid_contract','只可发布草稿',409);
            $doc=OpenStore::table('document')->where(['tenant_id'=>0,'contract_code'=>$row['code'],'status'=>'published'])->find();if(!$doc)throw new OpenException('missing_document','请先发布对应接口文档');
            $row['config']['verification']=OpenVerification::assertReceipt($row);
            OpenStore::table('contract')->where('id',$id)->update(['config'=>OpenStore::encode($row['config'])]);
            OpenStore::table('contract')->where(['code'=>$row['code'],'status'=>'published'])->update(['status'=>'archived','update_time'=>time()]);OpenStore::table('contract')->where('id',$id)->update(['status'=>'published','update_time'=>time()]);return self::detail($id);});
    }
    public static function tool(string $method,string $path): array
    {
        $registered=OpenToolRegistry::match($method,$path);foreach(OpenStore::table('contract')->where(['kind'=>'tool','status'=>'published','method'=>$method])->order('id','desc')->select()->toArray() as $row){if(OpenPolicy::matches($row['path'],$path)===null)continue;$config=OpenStore::json($row['config']);return array_replace($registered,['kind'=>'tool','contract_id'=>$row['id'],'input'=>$config['input'],'version'=>$row['version'],'code'=>$row['code']]);}throw new OpenException('not_found','工具操作尚未发布',404);
    }
    public static function selected(array $config,array $params): bool
    {
        if(isset($config['model'])&&($params['model']??'')!==$config['model'])return false;
        foreach($config['when']??[] as $name=>$value){$actual=$params[$name]??($name==='stream'?false:null);if($actual!==$value)return false;}
        return true;
    }
    public static function match(string $method,string $path,array $params): array
    {
        foreach(OpenStore::table('contract')->where(['status'=>'published','method'=>$method,'kind'=>'market'])->order('id','desc')->select()->toArray() as $row){$bindings=OpenPolicy::matches($row['path'],$path);if($bindings===null)continue;$config=OpenStore::json($row['config']);if(!self::selected($config,$params))continue;return $config+['kind'=>'market','contract_id'=>$row['id'],'product_id'=>$row['product_id'],'code'=>$row['code'],'path'=>$row['path'],'method'=>$method,'version'=>$row['version'],'params'=>$bindings];}throw new OpenException('not_found','接口尚未发布或模型未开放',404);
    }
}
