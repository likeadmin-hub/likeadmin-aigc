<?php

namespace app\common\model\app;

class App extends AppBaseModel
{
    protected $name = 'app';
    protected $json = ['manifest_json'];
    protected $jsonAssoc = true;
}

