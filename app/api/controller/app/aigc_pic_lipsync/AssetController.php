<?php
namespace app\api\controller\app\aigc_pic_lipsync;
use app\api\controller\BaseApiController;
use app\common\service\app\image_human\ImageHumanService;
use app\common\service\app\aigc_music\AigcMusicAssetService;
use app\common\model\app\aigc_music\AigcMusicAsset;
use app\common\service\storage\StorageConfigService;
use app\common\service\UploadService;
use app\common\enum\FileEnum;
use Exception;
class AssetController extends BaseApiController
{
    public function images() { return $this->success('获取成功', ImageHumanService::avatarLists((int)$this->request->tenantId, $this->userId)); }
    public function audios() {
        $rows = AigcMusicAsset::where(['tenant_id' => (int)$this->request->tenantId, 'user_id' => $this->userId, 'asset_type' => 'pic_lipsync_audio', 'delete_time' => 0])->order('id','desc')->limit(100)->select()->toArray();
        foreach ($rows as &$row) $row['url'] = AigcMusicAssetService::assetUrl($row);
        return $this->success('获取成功', $rows);
    }
    public function upload_audio() { try { return $this->success('上传成功', AigcMusicAssetService::uploadAudio((int)$this->request->tenantId, $this->userId, ['asset_type' => 'pic_lipsync_audio'])); } catch (Exception $e) { return $this->fail($e->getMessage()); } }
    public function upload_image() {
        try {
            $file = UploadService::image(0, $this->userId, FileEnum::SOURCE_USER);
            $row = ImageHumanService::saveAvatar((int)$this->request->tenantId, $this->userId, ['name' => $file['name'] ?? '我的图片形象', 'image_uri' => $file['url']]);
            return $this->success('上传成功', $row);
        } catch (Exception $e) { return $this->fail($e->getMessage()); }
    }
}
