<?php
namespace app\common\service;

final class WorkspaceSaleVideoAssetService extends WorkspaceProductVideoAssetService
{
    protected const SOURCE = 'sale_video';
    protected const APP = 'aigc_sale_video';
    protected const TITLE = '带货视频';
}
