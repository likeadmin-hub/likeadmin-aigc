<?php

namespace app\common\model\app\aigc_music;

use app\common\model\app\AppBaseModel;

class AigcMusicPersona extends AppBaseModel
{
    protected $name = 'aigc_music_persona';
    protected $json = ['prompt_json', 'audit_json'];
    protected $jsonAssoc = true;
}
