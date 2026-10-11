<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\open_platform\OpenSchema as S;
use app\common\service\open_platform\OpenException;
function reject($value,$schema){try{S::validate($value,$schema);}catch(OpenException){return;}throw new RuntimeException('Invalid schema value accepted');}
$shape=['type'=>'object','$defs'=>['media'=>['type'=>'object','required'=>['url'],'properties'=>['url'=>['type'=>'string','pattern'=>'^https://']]]],'required'=>['media'],'properties'=>['media'=>['$ref'=>'#/$defs/media'],'size'=>['type'=>['integer','null'],'minimum'=>1],'mode'=>['oneOf'=>[['type'=>'string','enum'=>['fast']],['type'=>'integer','minimum'=>1]]]],'additionalProperties'=>false];
S::validate(['media'=>['url'=>'https://example.com/a'],'size'=>null,'mode'=>1],$shape);
reject(['media'=>['url'=>'http://example.com/a']],$shape);reject(['media'=>[]],$shape);reject(['media'=>['url'=>'https://example.com/a'],'size'=>0],$shape);reject(['media'=>['url'=>'https://example.com/a'],'mode'=>false],$shape);
reject(1,['oneOf'=>[['type'=>'number'],['type'=>'integer']]]);reject([],['allOf'=>[['required'=>['id']]]]);reject(['a','a'],['type'=>'array','uniqueItems'=>true]);
S::validate(null,['type'=>'string','nullable'=>true]);reject('x',['$ref'=>'https://example.com/schema']);reject([],['$ref'=>'#/missing']);reject([],['$ref'=>'#/$defs/cycle','$defs'=>['cycle'=>['$ref'=>'#/$defs/cycle']]]);
$q=S::query(['limit'=>'2','enabled'=>'false','ids'=>'[1,2]','label'=>'007'],['type'=>'object','properties'=>['limit'=>['type'=>'integer'],'enabled'=>['type'=>'boolean'],'ids'=>['type'=>'array','items'=>['type'=>'integer']],'label'=>['type'=>['string','integer']]]]);
if($q!==['limit'=>2,'enabled'=>false,'ids'=>[1,2],'label'=>'007'])throw new RuntimeException('Query normalization mismatch');
$strict=['type'=>'object','properties'=>['metadata'=>['type'=>'object'],'items'=>['type'=>'array']]];
S::validate(json_decode('{"metadata":{},"items":[]}'),$strict,'$',0,null,true);
try{S::validate(json_decode('{"metadata":[],"items":[]}'),$strict,'$',0,null,true);throw new RuntimeException('JSON array accepted as object');}catch(OpenException){}
try{S::validate(json_decode('{"metadata":{},"items":{}}'),$strict,'$',0,null,true);throw new RuntimeException('JSON object accepted as array');}catch(OpenException){}
S::validate(json_decode('{"0":"zero"}'),['type'=>'object','required'=>['0']],'$',0,null,true);
reject(1,['type'=>'number','minimum'=>1,'exclusiveMinimum'=>true]);reject(3,['type'=>'number','maximum'=>3,'exclusiveMaximum'=>true]);S::validate(2,['type'=>'number','minimum'=>1,'maximum'=>3,'exclusiveMinimum'=>true,'exclusiveMaximum'=>true]);
$objects=S::query(['options'=>'{"speed":"1.5"}','empty'=>'{}'],['type'=>'object','properties'=>['options'=>['type'=>'object','properties'=>['speed'=>['type'=>'number']]],'empty'=>['type'=>'object']]]);
if(!($objects['options'] instanceof \stdClass)||$objects['options']->speed!==1.5||!($objects['empty'] instanceof \stdClass))throw new RuntimeException('Multipart object shape or nested numeric value changed');
$fashion=\app\common\service\open_platform\OpenToolRegistry::get('aigc_fashion_lookbook')['operations'];$native=$fashion['POST tasks']['input'];
foreach([
 ['clothes_images'=>['of_'.str_repeat('a',32)],'model_image'=>['uri'=>'of_'.str_repeat('b',32)]],
 ['clothes'=>'https://example.com/a.png,https://example.com/b.png','model_preset_id'=>1],
 ['source_images'=>[['image'=>'https://example.com/a.png']],'model'=>['url'=>'https://example.com/model.png']],
] as $params)S::validate(json_decode(json_encode($params)),$native,'$',0,null,true);
reject(['clothes_images'=>['a']],$native);reject(['model_preset_id'=>1],$native);
reject(['clothes_images'=>array_fill(0,7,'a'),'model_image'=>'b'],$native);
reject(['clothes_images'=>['a'],'model_preset_id'=>0],$native);
reject(['clothes_images'=>['a'],'model_image'=>['unexpected'=>'b']],$native);
S::validate([],$fashion['POST estimate']['input']);
echo "PASS nested refs, nullable unions, exclusive schema branches, typed query inputs and native fashion aliases/preset/list bounds\n";
