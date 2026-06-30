<?php

namespace app\common\model;

use app\common\model\user\User;
use app\common\service\FileService;

class PcFeedback extends BaseModel
{
    protected $name = 'pc_feedback';
    protected $json = ['images'];
    protected $jsonAssoc = true;

    public function user()
    {
        return $this->hasOne(User::class, 'id', 'user_id')
            ->field(['id', 'sn', 'nickname', 'avatar', 'mobile']);
    }

    public function getImagesUrlAttr($value, $data): array
    {
        $images = $data['images'] ?? [];
        if (is_string($images)) {
            $decoded = json_decode($images, true);
            $images = is_array($decoded) ? $decoded : [];
        }
        return array_values(array_map(static fn($uri) => FileService::getFileUrl((string)$uri), array_filter((array)$images)));
    }
}
