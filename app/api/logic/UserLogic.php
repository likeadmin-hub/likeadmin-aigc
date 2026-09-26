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

namespace app\api\logic;


use app\common\enum\notice\NoticeEnum;
use app\common\enum\user\UserTerminalEnum;
use app\common\logic\BaseLogic;
use app\common\model\user\User;
use app\common\model\user\UserAuth;
use app\common\service\FileService;
use app\common\service\ConfigService;
use app\common\service\membership\MembershipService;
use app\common\service\distribution\DistributionService;
use app\common\service\sms\SmsDriver;
use app\common\service\wechat\WeChatMnpService;
use app\api\service\UserTokenService;
use app\api\service\WechatAccountService;
use think\facade\Db;
use think\facade\Config;

/**
 * 会员逻辑层
 * Class TenantLogic
 * @package app\shopapi\logic
 */
class UserLogic extends BaseLogic
{

    /**
     * @notes 个人中心
     * @param array $userInfo
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @author 段誉
     * @date 2022/9/16 18:04
     */
    public static function center(array $userInfo): array
    {
        $user = User::where(['id' => $userInfo['user_id']])
            ->field('id,sn,sex,account,nickname,real_name,avatar,mobile,create_time,is_new_user,user_money,password')
            ->findOrEmpty();

        $wechatStatus = self::wechatBindingStatus((int)$userInfo['user_id']);
        foreach ($wechatStatus as $key => $value) $user[$key] = $value;
        $user['is_auth'] = (int)match ((int)($userInfo['terminal'] ?? 0)) {
            UserTerminalEnum::WECHAT_OA => $wechatStatus['has_oa_auth'],
            UserTerminalEnum::WECHAT_MMP => $wechatStatus['has_mnp_auth'],
            default => false,
        };

        $user['has_password'] = !empty($user['password']);
        foreach ((new \app\api\service\PcWechatService())->bindingStatus((int)$userInfo['user_id']) as $key => $value) $user[$key] = $value;
        $membership = MembershipService::status((int)($userInfo['tenant_id'] ?? 0), (int)$userInfo['user_id']);
        foreach ($membership as $key => $value) {
            $user[$key] = $value;
        }
        $tenantId = (int)($userInfo['tenant_id'] ?? 0);
        $user['distribution_enabled'] = DistributionService::isEnabled($tenantId) ? 1 : 0;
        $user['distribution_invite_code'] = $user['distribution_enabled']
            ? (string)DistributionService::ensurePromoter($tenantId, (int)$userInfo['user_id'])['invite_code']
            : '';
        $user->hidden(['password']);
        return $user->toArray();
    }


    /**
     * @notes 个人信息
     * @param $userId
     * @return array
     * @author 段誉
     * @date 2022/9/20 19:45
     */
    public static function info(int $userId)
    {
        $user = User::where(['id' => $userId])
            ->field('id,sn,sex,account,password,nickname,real_name,avatar,mobile,create_time,user_money')
            ->findOrEmpty();
        $user['has_password'] = !empty($user['password']);
        $user['has_auth'] = self::hasWechatAuth($userId);
        foreach (self::wechatBindingStatus($userId) as $key => $value) $user[$key] = $value;
        foreach ((new \app\api\service\PcWechatService())->bindingStatus($userId) as $key => $value) $user[$key] = $value;
        $user['version'] = config('project.version');
        $user->hidden(['password']);
        return $user->toArray();
    }


    /**
     * @notes 设置用户信息
     * @param int $userId
     * @param array $params
     * @return User|false
     * @author 段誉
     * @date 2022/9/21 16:53
     */
    public static function setInfo(int $userId, array $params)
    {
        try {
            if ($params['field'] == "avatar") {
                $params['value'] = FileService::setFileUrl($params['value']);
            }

            return User::where(['id' => $userId])->update([
                $params['field'] => $params['value']
            ]);
        } catch (\Exception $e) {
            self::$error = $e->getMessage();
            return false;
        }
    }


    /**
     * @notes 是否有微信授权信息
     * @param $userId
     * @return bool
     * @author 段誉
     * @date 2022/9/20 19:36
     */
    public static function wechatBindingStatus(int $userId): array
    {
        $terminals = UserAuth::where('user_id', $userId)
            ->whereIn('terminal', [UserTerminalEnum::WECHAT_OA, UserTerminalEnum::WECHAT_MMP])
            ->column('terminal');
        $terminals = array_map('intval', $terminals);
        return [
            'has_oa_auth' => in_array(UserTerminalEnum::WECHAT_OA, $terminals, true),
            'has_mnp_auth' => in_array(UserTerminalEnum::WECHAT_MMP, $terminals, true),
        ];
    }

    public static function hasWechatAuth(int $userId)
    {
        //是否有微信授权登录
        $terminal = [UserTerminalEnum::WECHAT_MMP, UserTerminalEnum::WECHAT_OA,UserTerminalEnum::PC];
        $auth = UserAuth::where(['user_id' => $userId])
            ->whereIn('terminal', $terminal)
            ->findOrEmpty();
        return !$auth->isEmpty();
    }


    /**
     * @notes 重置登录密码
     * @param $params
     * @return bool
     * @author 段誉
     * @date 2022/9/16 18:06
     */
    public static function resetPassword(array $params)
    {
        try {
            // 校验验证码
            $smsDriver = new SmsDriver();
            if (!$smsDriver->verify($params['mobile'], $params['code'], NoticeEnum::FIND_LOGIN_PASSWORD_CAPTCHA)) {
                throw new \Exception('验证码错误');
            }

            // 重置密码
            $passwordSalt = Config::get('project.unique_identification');
            $password = create_password($params['password'], $passwordSalt);

            // 更新
            User::where('mobile', $params['mobile'])->update([
                'password' => $password
            ]);

            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 修稿密码
     * @param $params
     * @param $userId
     * @return bool
     * @author 段誉
     * @date 2022/9/20 19:13
     */
    public static function changePassword(array $params, int $userId)
    {
        try {
            $user = User::findOrEmpty($userId);
            if ($user->isEmpty()) {
                throw new \Exception('用户不存在');
            }

            // 密码盐
            $passwordSalt = Config::get('project.unique_identification');

            if (!empty($user['password'])) {
                if (empty($params['old_password'])) {
                    throw new \Exception('请填写旧密码');
                }
                $oldPassword = create_password($params['old_password'], $passwordSalt);
                if ($oldPassword != $user['password']) {
                    throw new \Exception('原密码不正确');
                }
            }

            // 保存密码
            $password = create_password($params['password'], $passwordSalt);
            $user->password = $password;
            $user->save();

            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 获取小程序手机号
     * @param array $params
     * @return bool
     * @throws \Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface
     * @author 段誉
     * @date 2023/2/27 11:49
     */
    public static function getMobileByMnp(array $params)
    {
        try {
            if ((int)ConfigService::get('login', 'mnp_phone_auth', config('project.login.mnp_phone_auth')) !== 1) {
                throw new \Exception('当前小程序未开启微信手机号授权，请使用短信验证码绑定');
            }
            $response = (new WeChatMnpService())->getUserPhoneNumber($params['code']);
            $phoneNumber = $response['phone_info']['purePhoneNumber'] ?? '';
            if (empty($phoneNumber)) {
                throw new \Exception('获取手机号码失败');
            }

            $user = User::where([
                ['mobile', '=', $phoneNumber],
                ['id', '<>', $params['user_id']]
            ])->findOrEmpty();

            if (!$user->isEmpty()) {
                if (!self::isMergeableWechatShadow((int)$params['user_id'])) {
                    throw new \Exception('该手机号已有账号，请使用原账号登录后再绑定微信');
                }

                return self::mergeWechatShadow(
                    (int)$params['user_id'],
                    $user,
                    (int)($params['terminal'] ?? UserTerminalEnum::WECHAT_MMP)
                );
            }

            // 绑定手机号
            User::update([
                'mobile' => $phoneNumber
            ], ['id' => $params['user_id']]);

            return ['merged' => false];
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 绑定手机号
     * @param $params
     * @return bool
     * @author 段誉
     * @date 2022/9/21 17:28
     */
    public static function bindMobile(array $params)
    {
        try {
            // 变更手机号场景
            $sceneId = NoticeEnum::CHANGE_MOBILE_CAPTCHA;

            // 绑定手机号场景
            if ($params['type'] == 'bind') {
                $sceneId = NoticeEnum::BIND_MOBILE_CAPTCHA;
            }

            // 校验短信
            $checkSmsCode = (new SmsDriver())->verify($params['mobile'], $params['code'], $sceneId);
            if (!$checkSmsCode) {
                throw new \Exception('验证码错误');
            }

            $user = User::where([
                ['mobile', '=', $params['mobile']],
                ['id', '<>', $params['user_id']],
            ])->findOrEmpty();
            if (!$user->isEmpty()) {
                if ($user->id != $params['user_id'] && self::isMergeableWechatShadow((int)$params['user_id'])) {
                    return self::mergeWechatShadow(
                        (int)$params['user_id'],
                        $user,
                        (int)($params['terminal'] ?? UserTerminalEnum::PC)
                    );
                }
                throw new \Exception('该手机号已有账号，请使用原账号登录后再绑定');
            }

            User::update([
                'mobile' => $params['mobile'],
            ], ['id' => $params['user_id']]);

            return ['merged' => false];
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

    /**
     * 微信临时账号只允许在手机号验证成功后合并，避免覆盖已有业务账号。
     */
    private static function isMergeableWechatShadow(int $userId): bool
    {
        $user = User::where('id', $userId)->findOrEmpty();
        return WechatAccountService::isMergeableShadow($user);
    }

    /**
     * 将微信临时账号的身份记录迁移到已验证的手机号账号，并刷新当前终端 token。
     */
    private static function mergeWechatShadow(int $shadowUserId, User $targetUser, int $terminal): array
    {
        return Db::transaction(function () use ($shadowUserId, $targetUser, $terminal) {
            if (!empty($targetUser->is_disable)) {
                throw new \Exception('您的账号异常，请联系客服。');
            }
            $authRows = UserAuth::where('user_id', $shadowUserId)->select();
            foreach ($authRows as $auth) {
                $sameOpenid = UserAuth::where('openid', $auth->openid)
                    ->where('user_id', '<>', $targetUser->id)
                    ->findOrEmpty();
                if (!$sameOpenid->isEmpty() && (int)$sameOpenid->user_id !== $shadowUserId) {
                    throw new \Exception('微信身份已绑定其他账号，暂时无法合并');
                }

                $targetAuth = UserAuth::where([
                    'user_id' => $targetUser->id,
                    'openid' => $auth->openid,
                ])->findOrEmpty();
                if ($targetAuth->isEmpty()) {
                    $auth->user_id = $targetUser->id;
                    $auth->save();
                } else {
                    $auth->delete();
                }
            }

            User::where('id', $shadowUserId)->update([
                'is_disable' => 1,
                'delete_time' => time(),
                'update_time' => time(),
            ]);

            $tokenInfo = UserTokenService::setToken($targetUser, $terminal);
            return [
                'merged' => true,
                'token' => $tokenInfo['token'],
                'mobile' => $targetUser->mobile,
            ];
        });
    }

    /**
     * 解绑当前终端的微信身份。手机号或密码至少保留一种时才允许解绑。
     */
    public static function unbindWechat(array $params): bool
    {
        try {
            $user = User::where('id', $params['user_id'])->findOrEmpty();
            if ($user->isEmpty()) {
                throw new \Exception('用户不存在');
            }
            if (empty($user->mobile) && empty($user->password)) {
                throw new \Exception('请先绑定手机号或设置登录密码');
            }

            $auth = UserAuth::where([
                'user_id' => $params['user_id'],
                'terminal' => (int)$params['terminal'],
            ])->findOrEmpty();
            if ($auth->isEmpty()) {
                throw new \Exception('当前端未绑定微信');
            }
            $auth->delete();
            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }

}
