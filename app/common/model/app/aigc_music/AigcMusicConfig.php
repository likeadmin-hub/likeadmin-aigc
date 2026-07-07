<?php

namespace app\common\model\app\aigc_music;

use app\common\model\app\AppBaseModel;

class AigcMusicConfig extends AppBaseModel
{
    protected $name = 'aigc_music_config';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
