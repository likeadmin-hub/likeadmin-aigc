<?php

namespace app\common\model\app\aigc_music_cover;

use app\common\model\app\AppBaseModel;

class AigcMusicCoverConfig extends AppBaseModel
{
    protected $name = 'aigc_music_cover_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
