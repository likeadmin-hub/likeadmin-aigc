<?php
namespace app\common\service\open_platform;
/** Each fixed tool action receives fresh parameters; cached query merges cannot leak between actions. */
final class OpenToolRequest extends \app\Request
{
    private ?\think\Request $uploadRequest = null;
    public function file(string $name = '')
    {
        // The framework parser expects raw PHP upload arrays, not UploadedFile
        // objects returned by file(). Delegate to the original upload request.
        return $this->uploadRequest ? $this->uploadRequest->file($name) : parent::file($name);
    }
    public static function from(\think\Request $original,array $params,array $operation): self
    {
        $request=new self();
        $request->uploadRequest=$original;
        $request->withServer($original->server())->withHeader($original->header());
        $request->withGet($params)->withPost($params)->withInput(OpenStore::encode((object)$params));
        $request->setMethod(explode(' ',$operation['route']??'POST tasks',2)[0]);
        $request->setController('app.'.$operation['app_code'].'.'.($operation['controller']??'generate'));
        $request->setAction($operation['action']??'index');
        foreach(['tenantId','tenantSn','tenantResolveMode','userId','userInfo','source','openPlatformKey'] as $key)if(isset($original->$key))$request->$key=$original->$key;
        return $request;
    }
}
