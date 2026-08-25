<?php

namespace app\common\model\app\aigc_music_cover;

use app\common\model\app\AppBaseModel;

class AigcMusicCoverTask extends AppBaseModel
{
    protected $name = 'aigc_music_cover_task';
    protected $json = ['request_snapshot', 'pricing_snapshot'];
    protected $jsonAssoc = true;
}
