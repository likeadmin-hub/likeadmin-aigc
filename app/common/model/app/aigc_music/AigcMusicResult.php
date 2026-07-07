<?php

namespace app\common\model\app\aigc_music;

use app\common\model\app\AppBaseModel;

class AigcMusicResult extends AppBaseModel
{
    protected $name = 'aigc_music_result';
    protected $json = ['timing_json', 'result_json'];
    protected $jsonAssoc = true;
}
