<?php

namespace app\api\controller\app\aigc_canvas;

class ProxyController extends GenerateController
{
    public function chat()
    {
        return $this->text();
    }
}
