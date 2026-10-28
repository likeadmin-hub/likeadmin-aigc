<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
use app\common\service\app\aigc_copywriting\CopywritingDocument as D;
use app\common\service\app\aigc_copywriting\CopywritingPromptService as P;
function check($v,$message){if(!$v)throw new RuntimeException($message);}
check(class_exists(D::class),'document protocol is not implemented');
$d=['schema_version'=>1,'page'=>['nodes'=>[]],'fields'=>[['id'=>'f','key'=>'topic','type'=>'text','required'=>true]],'prompt'=>['user_template'=>'主题 {{topic}}']];
check(D::normalize(D::normalize($d))===D::normalize($d),'normalize must be idempotent');
$d['fields'][]=['id'=>'g','key'=>'topic','type'=>'text'];check(count(D::validate($d))>0,'duplicate aliases rejected');array_pop($d['fields']);
check(P::render($d,['topic'=>'输入 {{other}}'])['user_prompt']==='主题 输入 {{other}}','single replacement only');
$d['fields'][]=['id'=>'b','key'=>'emoji','type'=>'switch','default'=>false,'on_prompt'=>'添加表情','off_prompt'=>'不添加表情'];$d['prompt']['user_template']='{{topic}} {{emoji}}';check(str_contains(P::render($d,['topic'=>'正文','emoji'=>false])['user_prompt'],'不添加表情'),'false retained');
try{P::render($d,['topic'=>'']);throw new RuntimeException('required not enforced');}catch(InvalidArgumentException $e){}
echo "copywriting contract PASS\n";
