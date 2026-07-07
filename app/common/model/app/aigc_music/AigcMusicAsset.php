<?php

namespace app\common\model\app\aigc_music;

use app\common\model\app\AppBaseModel;

class AigcMusicAsset extends AppBaseModel
{
    protected $name = 'aigc_music_asset';
    protected $json = ['audit_json'];
    protected $jsonAssoc = true;
}
