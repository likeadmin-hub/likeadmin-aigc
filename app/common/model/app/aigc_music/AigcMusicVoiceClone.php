<?php

namespace app\common\model\app\aigc_music;

use app\common\model\app\AppBaseModel;

class AigcMusicVoiceClone extends AppBaseModel
{
    protected $name = 'aigc_music_voice_clone';
    protected $json = ['auth_json', 'provider_payload'];
    protected $jsonAssoc = true;
}
