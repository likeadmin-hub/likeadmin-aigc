<?php
namespace app\common\service\app\aigc_micro_video;
// Temporary compatibility while the retiring tool is still present.
class_alias(\app\common\service\ai\StudioVideoCatalogService::class, __NAMESPACE__.'\\MicroVideoCatalog');
