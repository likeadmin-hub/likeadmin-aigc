<?php
declare(strict_types=1);
namespace app\common\service;

// Keep extensions using the previous service name compatible.
class_alias(LikeAdminBrandService::class, __NAMESPACE__.'\\InspirationBrandService');
