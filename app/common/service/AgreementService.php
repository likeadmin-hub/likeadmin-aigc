<?php

namespace app\common\service;

class AgreementService
{
    public const TYPE_SERVICE = 'service';
    public const TYPE_PRIVACY = 'privacy';
    public const TYPE_COMMUNITY = 'community';
    public const TYPE_AI_USAGE = 'ai_usage';
    public const TYPE_PAID = 'paid';

    public const TYPES = [
        self::TYPE_SERVICE,
        self::TYPE_PRIVACY,
        self::TYPE_COMMUNITY,
        self::TYPE_AI_USAGE,
        self::TYPE_PAID,
    ];

    private const DEFAULTS = [
        self::TYPE_SERVICE => [
            'title' => '用户服务协议',
            'content' => '<h2>1. 协议范围</h2><p>欢迎使用本平台提供的 AI 创作、素材管理、会员及积分等服务。您注册、登录、访问或使用本平台，即表示您已阅读并同意本协议。</p><h2>2. 账号使用</h2><p>您应妥善保管账号、密码及验证码，不得出租、出借、转让账号。因您主动泄露或保管不当造成的损失，由您自行承担。</p><h2>3. 服务规则</h2><p>您应依法、诚信、合理使用平台服务，不得利用本平台生成、传播违法违规、侵权、虚假、欺诈、低俗或危害他人权益的内容。</p><h2>4. 服务变更</h2><p>平台可根据产品迭代、合规要求或运营安排，对功能、计费、展示方式进行调整，并通过页面提示、公告或站内通知告知。</p><h2>5. 免责声明</h2><p>AI 生成内容受模型能力、输入提示、网络环境等因素影响，平台不保证结果完全符合您的预期。您应对生成内容的使用场景和合规性自行判断。</p>',
        ],
        self::TYPE_PRIVACY => [
            'title' => '隐私政策',
            'content' => '<h2>1. 信息收集</h2><p>为完成账号注册、登录、安全验证、内容生成、订单支付和客户服务，我们可能收集您主动提交的账号信息、联系方式、素材、提示词、订单记录及设备环境信息。</p><h2>2. 信息使用</h2><p>我们仅在实现产品功能、保障账号安全、履行交易、优化服务和满足法律法规要求的范围内使用相关信息。</p><h2>3. 信息存储与保护</h2><p>平台将采取合理的安全措施保护您的个人信息和创作数据，防止未经授权的访问、披露、篡改或丢失。</p><h2>4. 信息共享</h2><p>除完成支付、存储、内容安全审核、模型调用等必要服务外，我们不会向无关第三方出售或非法提供您的个人信息。</p><h2>5. 用户权利</h2><p>您可依法申请查询、更正、删除个人信息，或注销账号。具体处理方式以平台提供的客服或账号管理入口为准。</p>',
        ],
        self::TYPE_COMMUNITY => [
            'title' => '社区自律公约',
            'content' => '<h2>1. 友好创作</h2><p>请尊重他人，保持友善、理性和建设性的表达，不发布侮辱、骚扰、歧视、威胁或煽动对立的内容。</p><h2>2. 尊重版权</h2><p>上传素材、提示词、案例或作品时，请确保您拥有合法权利或已获得授权，不侵犯他人的肖像权、名誉权、著作权、商标权等合法权益。</p><h2>3. 内容边界</h2><p>不得发布违法违规、低俗色情、暴力恐怖、赌博诈骗、虚假宣传、恶意营销或破坏平台秩序的内容。</p><h2>4. 共同维护</h2><p>发现违规内容或异常行为时，可通过平台客服或举报入口反馈。平台有权依据规则进行提醒、下架、限制功能或封禁处理。</p>',
        ],
        self::TYPE_AI_USAGE => [
            'title' => 'AI功能使用须知',
            'content' => '<h2>1. 生成结果说明</h2><p>AI 生成结果由模型根据您的输入自动生成，可能存在不准确、不完整、不稳定或不符合预期的情况，请在使用前自行审核。</p><h2>2. 输入内容要求</h2><p>请勿输入国家法律法规禁止的内容，不得诱导模型生成违法违规、侵权、危险、歧视、色情、暴力或误导性内容。</p><h2>3. 权利与授权</h2><p>您上传的图片、视频、音频、文本等素材应具备合法来源。因素材或生成内容引发的权利争议，由提交和使用该内容的用户承担相应责任。</p><h2>4. 内容审核</h2><p>平台可能对提示词、上传素材和生成结果进行安全审核，并可对风险内容采取拒绝生成、隐藏、删除或限制访问等措施。</p><h2>5. 商用提示</h2><p>如您将 AI 生成结果用于商业用途，请结合实际场景完成版权、肖像、品牌、广告合规等必要审查。</p>',
        ],
        self::TYPE_PAID => [
            'title' => '付费用户协议',
            'content' => '<h2>1. 购买说明</h2><p>积分、会员或其他付费权益属于站内虚拟权益，支付完成后将按页面展示规则充值或开通至当前账号，仅可用于平台指定功能。</p><h2>2. 使用规则</h2><p>虚拟权益不可转赠、不可提现，也不可兑换现金或其他未明确支持的权益。请结合实际需求选择对应套餐或积分包。</p><h2>3. 有效期说明</h2><p>充值积分、赠送积分、会员权益等可能存在不同有效期，具体以购买页、订单页或活动页面展示为准。</p><h2>4. 扣费与消耗</h2><p>使用 AI 生图、生视频、数字人、智能工具等功能时，系统会按页面提示或套餐规则扣减相应积分、次数或权益。</p><h2>5. 退款说明</h2><p>虚拟权益一经充值、开通或消耗，通常不支持无理由退款。若因系统异常导致未到账或重复扣费，可联系平台客服核实处理。</p>',
        ],
    ];

    public static function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, self::TYPES, true) ? $type : self::TYPE_SERVICE;
    }

    public static function getPolicy(string $type): array
    {
        $type = self::normalizeType($type);
        $default = self::DEFAULTS[$type];
        $title = trim((string)ConfigService::get('agreement', $type . '_title', ''));
        $content = trim((string)ConfigService::get('agreement', $type . '_content', ''));

        return [
            'type' => $type,
            'title' => $title !== '' ? $title : $default['title'],
            'content' => get_file_domain($content !== '' ? $content : $default['content']),
        ];
    }

    public static function getAgreementConfig(): array
    {
        $config = [];
        foreach (self::TYPES as $type) {
            $policy = self::getPolicy($type);
            $config[$type . '_title'] = $policy['title'];
            $config[$type . '_content'] = $policy['content'];
        }
        return $config;
    }

    public static function saveAgreementConfig(array $params): void
    {
        foreach (self::TYPES as $type) {
            $titleKey = $type . '_title';
            $contentKey = $type . '_content';
            if (array_key_exists($titleKey, $params)) {
                ConfigService::set('agreement', $titleKey, $params[$titleKey] ?? '');
            }
            if (array_key_exists($contentKey, $params)) {
                ConfigService::set('agreement', $contentKey, clear_file_domain($params[$contentKey] ?? ''));
            }
        }
    }
}
