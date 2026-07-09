<?php

namespace app\common\model\app\aigc_music;

use app\common\model\app\AppBaseModel;

class AigcMusicExport extends AppBaseModel
{
    protected $name = 'aigc_music_export';
    protected $json = ['result_json'];
    protected $jsonAssoc = true;
}
