<?php
require dirname(__DIR__,2).'/vendor/autoload.php';
$called=0;$response=new app\common\service\open_platform\OpenStreamResponse(function()use(&$called){$called++;echo "data: fixture\n\n";});
if($called!==0||$response->getCode()!==200)throw new RuntimeException('Stream was not deferred');
ob_start();$response->send();$body=ob_get_clean();if($called!==1||$body!=="data: fixture\n\n")throw new RuntimeException('Stream response did not send exactly once');
echo "PASS deferred stream response uses installed framework initialization and sends once\n";
