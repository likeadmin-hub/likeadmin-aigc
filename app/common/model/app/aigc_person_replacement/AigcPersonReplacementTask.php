<?php

namespace app\common\model\app\aigc_person_replacement;

use app\common\model\app\AppBaseModel;

class AigcPersonReplacementTask extends AppBaseModel
{
    protected $name = 'aigc_person_replacement_task';
    protected $json = ['reference_images', 'request_snapshot', 'upstream_usage', 'provider_response'];
    protected $jsonAssoc = true;
}
