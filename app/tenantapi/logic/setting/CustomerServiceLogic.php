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

namespace app\tenantapi\logic\setting;

use app\common\logic\BaseLogic;
use app\common\service\ConfigService;
use app\common\service\FileService;

/**
 * 客服设置逻辑
 * Class CustomerServiceLogic
 * @package app\tenantapi\logic\setting
 */
class CustomerServiceLogic extends BaseLogic
{
    /**
     * @notes 获取客服设置
     * @return array
     * @author ljj
     * @date 2022/2/15 12:05 下午
     */
    public static function getConfig()
    {
        $qrCode = ConfigService::get('customer_service', 'qr_code');
        $qrCode = empty($qrCode) ? '' : FileService::getFileUrl($qrCode);
        $config = [
            'qr_code' => $qrCode,
            'wechat' => ConfigService::get('customer_service', 'wechat', ''),
            'phone' => ConfigService::get('customer_service', 'phone', ''),
            'service_time' => ConfigService::get('customer_service', 'service_time', ''),
            'enterprise_wechat_url' => ConfigService::get('customer_service', 'enterprise_wechat_url', ''),
            'pc_help_enabled' => (int)ConfigService::get('customer_service', 'pc_help_enabled', 1),
            'pc_help_faqs' => self::normalizeFaqs(ConfigService::get('customer_service', 'pc_help_faqs', []), true),
        ];
        return $config;
    }

    /**
     * @notes 设置客服设置
     * @param $params
     * @author ljj
     * @date 2022/2/15 12:11 下午
     */
    public static function setConfig($params)
    {
        $allowField = ['qr_code','wechat','phone','service_time','enterprise_wechat_url','pc_help_enabled','pc_help_faqs'];
        foreach($params as $key => $value) {
            if(in_array($key, $allowField)) {
                if ($key == 'qr_code') {
                    $value = FileService::setFileUrl($value);
                } elseif ($key == 'pc_help_enabled') {
                    $value = (int)$value ? 1 : 0;
                } elseif ($key == 'pc_help_faqs') {
                    $value = self::normalizeFaqs($value, false);
                }
                ConfigService::set('customer_service', $key, $value);
            }
        }
    }

    public static function normalizeFaqs($faqs, bool $publicOnly = false): array
    {
        if (is_string($faqs)) {
            $decoded = json_decode($faqs, true);
            $faqs = is_array($decoded) ? $decoded : [];
        }
        $faqs = is_array($faqs) ? $faqs : [];
        $items = [];
        foreach ((array)$faqs as $index => $item) {
            if (!is_array($item)) {
                continue;
            }
            $question = trim((string)($item['question'] ?? ''));
            $answer = trim((string)($item['answer'] ?? ''));
            if ($question === '' && $answer === '') {
                continue;
            }
            $status = (int)($item['status'] ?? 1) ? 1 : 0;
            if ($publicOnly && !$status) {
                continue;
            }
            $items[] = [
                'id' => trim((string)($item['id'] ?? '')) ?: uniqid('faq_', true),
                'question' => mb_substr($question, 0, 120),
                'answer' => $answer,
                'sort' => (int)($item['sort'] ?? (count($faqs) - $index)),
                'status' => $status,
            ];
        }
        usort($items, static fn($a, $b) => ($b['sort'] <=> $a['sort']) ?: strcmp($a['id'], $b['id']));
        return $items;
    }
}
