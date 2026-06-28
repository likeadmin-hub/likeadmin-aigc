<?php

namespace app\tenantapi\validate\notice;

use app\common\validate\BaseValidate;

class PcNoticeValidate extends BaseValidate
{
    protected $rule = [
        'id' => 'require|number',
        'title' => 'require|max:120',
        'summary' => 'max:255',
        'content' => 'require',
        'image' => 'max:255',
        'is_popup' => 'in:0,1',
        'status' => 'in:0,1',
        'sort' => 'number',
    ];

    protected $message = [
        'id.require' => '请选择公告',
        'id.number' => '公告参数错误',
        'title.require' => '请输入公告标题',
        'title.max' => '公告标题最多120个字符',
        'summary.max' => '公告摘要最多255个字符',
        'content.require' => '请输入公告正文',
        'image.max' => '封面图参数错误',
        'is_popup.in' => '自动弹窗状态错误',
        'status.in' => '公告状态错误',
        'sort.number' => '排序值必须为数字',
    ];

    public function sceneDetail(): PcNoticeValidate
    {
        return $this->only(['id']);
    }

    public function sceneAdd(): PcNoticeValidate
    {
        return $this->only(['title', 'summary', 'content', 'image', 'is_popup', 'status', 'sort']);
    }

    public function sceneEdit(): PcNoticeValidate
    {
        return $this->only(['id', 'title', 'summary', 'content', 'image', 'is_popup', 'status', 'sort']);
    }

    public function sceneDelete(): PcNoticeValidate
    {
        return $this->only(['id']);
    }

    public function sceneStatus(): PcNoticeValidate
    {
        return $this->only(['id', 'status']);
    }
}
