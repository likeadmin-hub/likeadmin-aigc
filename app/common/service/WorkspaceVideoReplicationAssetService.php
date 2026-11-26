<?php
namespace app\common\service;

final class WorkspaceVideoReplicationAssetService extends WorkspaceProductVideoAssetService
{
    protected const SOURCE = 'video_replication';
    protected const APP = 'aigc_video_replication';
    protected const TITLE = '视频复刻';
}
