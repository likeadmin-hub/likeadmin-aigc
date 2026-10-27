<?php
require dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/app/Request.php';
$layout=['schema_version'=>1,'canvas'=>['width'=>1080,'height'=>1440,'background_asset_id'=>1],'layers'=>[['id'=>'code','type'=>'invite_code','x'=>.65,'y'=>.7,'w'=>.25,'h'=>.1875,'z'=>20,'user_move'=>true,'user_resize'=>false,'user_edit'=>false]]];
$request=(new app\Request())->withPost(['layout'=>$layout]);
foreach(['tenantapi','api'] as $module){
 $source=file_get_contents(dirname(__DIR__).'/app/'.$module.'/controller/distribution/PosterController.php');
 if(str_contains($source,'$this->request->post()'))throw new RuntimeException('Poster JSON must not pass through scalar trim: '.$module);
}
$input=$request->post('',[],null);
app\common\service\distribution\PosterLayout::validate($input['layout']);
if($input['layout']!==$layout)throw new RuntimeException('JSON types changed');
echo "PASS: JSON schema integers and layer boolean permissions preserved through request parsing\n";
