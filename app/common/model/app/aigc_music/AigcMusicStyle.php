<?php

namespace app\common\model\app\aigc_music;

use app\common\model\app\AppBaseModel;

class AigcMusicStyle extends AppBaseModel
{
    protected $name = 'aigc_music_style';
    protected $json = ['preset_json'];
    protected $jsonAssoc = true;
}
