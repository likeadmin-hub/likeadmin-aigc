<?php
// +----------------------------------------------------------------------
// | likeadmin快速开发前后端分离管理后台（PHP版）
// +----------------------------------------------------------------------
// | 欢迎阅读学习系统程序代码，建议反馈是我们前进的动力
// | 开源版本可自由商用，可去除界面版权logo
// | gitee下载：https://gitee.com/likeshop_gitee/likeadmin
// | github下载：https://github.com/likeshop-github/likeadmin
// | 访问官网：https://www.likeadmin.cn
// | likeadmin团队 版权所有 拥有最终解释权
// +----------------------------------------------------------------------
// | author: likeadminTeam
// +----------------------------------------------------------------------

declare(strict_types=1);

namespace app\common\cache;

use think\App;
use think\Cache;
use Throwable;

/**
 * 缓存基础类，用于管理缓存
 * Class BaseCache
 * @package app\common\cache
 */
abstract class BaseCache extends Cache
{
    /**
     * 缓存标签
     * @var string
     */
    protected $tagName;

    public function __construct()
    {
        parent::__construct(app());
        $this->tagName = get_class($this);
    }


    /**
     * @notes 重写父类set，自动打上标签
     * @param string $key
     * @param mixed $value
     * @param null $ttl
     * @return bool
     * @author 段誉
     * @date 2021/12/27 14:16
     */
    public function set($key, $value, $ttl = null): bool
    {
        return $this->store()->tag($this->tagName)->set($key, $value, $ttl);
    }


    /**
     * @notes 读取缓存，遇到损坏的序列化缓存时自动清理，避免底层诊断信息暴露给用户
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     * @author Codex
     * @date 2026/05/25
     */
    public function get($key, mixed $default = null): mixed
    {
        try {
            return parent::get($key, $default);
        } catch (Throwable $e) {
            try {
                $this->delete((string)$key);
            } catch (Throwable $deleteException) {
                // Ignore cleanup failure and let caller rebuild or use default value.
            }
            return $default;
        }
    }


    /**
     * @notes 清除缓存类所有缓存
     * @return bool
     * @author 段誉
     * @date 2021/12/27 14:16
     */
    public function deleteTag(): bool
    {
        return $this->tag($this->tagName)->clear();
    }

}
