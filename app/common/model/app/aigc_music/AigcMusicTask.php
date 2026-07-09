<?php

namespace app\common\model\app\aigc_music;

use app\common\model\app\AppBaseModel;

class AigcMusicTask extends AppBaseModel
{
    protected $name = 'aigc_music_task';
    protected $json = ['provider_payload', 'result_json'];
    protected $jsonAssoc = true;
}
