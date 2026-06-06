<?php

namespace app\common\model\app\smart_clip;

use app\common\model\app\AppBaseModel;

class SmartClipChannelSpec extends AppBaseModel
{
    protected $name = 'smart_clip_channel_spec';
    protected $json = ['provider_params_json'];
    protected $jsonAssoc = true;
}
