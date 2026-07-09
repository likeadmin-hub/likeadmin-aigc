<?php

namespace app\common\model\app\aigc_music;

use app\common\model\app\AppBaseModel;

class AigcMusicChannelSpec extends AppBaseModel
{
    protected $name = 'aigc_music_channel_spec';
    protected $json = ['provider_params_json'];
    protected $jsonAssoc = true;
}
