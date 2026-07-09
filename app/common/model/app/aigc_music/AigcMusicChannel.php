<?php

namespace app\common\model\app\aigc_music;

use app\common\model\app\AppBaseModel;

class AigcMusicChannel extends AppBaseModel
{
    protected $name = 'aigc_music_channel';
    protected $json = ['config_json'];
    protected $jsonAssoc = true;
}
