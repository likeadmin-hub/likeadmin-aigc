<?php

namespace app\common\service\ai;

/** Normalizes supplier failures without changing model availability. */
class UpstreamErrorMessageService
{
    public const TEMPORARILY_UNAVAILABLE = '上游暂时无法处理该模型，请稍后重试或切换模型';
    public const CLAUDE_CLIENT_RESTRICTED = '当前模型仅支持已授权的 Claude Code 渠道，不能通过当前算力市场转发。请切换模型，或由管理员配置官方 Claude API 渠道';

    public static function fromResponse(array $response, string $fallback = self::TEMPORARILY_UNAVAILABLE): string
    {
        [$code, $message] = self::details($response);
        if ($code === '' && $message === '') {
            return $fallback;
        }
        return self::normalize($message, $code);
    }

    public static function normalize(string $message = '', string $code = ''): string
    {
        $message = self::clean($message);
        $code = self::cleanCode($code);

        if ($code === '') {
            [$parsedCode, $parsedMessage] = self::codeFromMessage($message);
            $code = $parsedCode;
            $message = $parsedMessage;
        }

        if (self::isClaudeClientRestricted($message)) {
            return self::CLAUDE_CLIENT_RESTRICTED;
        }

        if (self::isParameterError($code, $message)) {
            $detail = $message !== '' ? $message : '上游请求参数不正确，请检查模型参数后重试';
            return self::withCode($code, $detail);
        }

        return self::withCode($code, self::TEMPORARILY_UNAVAILABLE);
    }

    /** @return array{0:string,1:string} */
    private static function details(array $response): array
    {
        $containers = [];
        self::collectContainers($response, $containers, 0);

        $code = '';
        $message = '';
        foreach ($containers as $container) {
            if (is_string($container)) {
                if ($message === '' && trim($container) !== '') {
                    $message = $container;
                }
                continue;
            }
            if (!is_array($container)) {
                continue;
            }
            if ($code === '') {
                foreach (['error_code', 'code', 'type'] as $key) {
                    if (isset($container[$key]) && is_scalar($container[$key])) {
                        $candidate = self::cleanCode((string)$container[$key]);
                        if ($candidate !== '') {
                            $code = $candidate;
                            break;
                        }
                    }
                }
            }
            if ($message === '') {
                foreach (['error_message', 'message', 'msg', 'detail', 'reason', 'fail_reason'] as $key) {
                    if (isset($container[$key]) && is_scalar($container[$key])) {
                        $candidate = self::clean((string)$container[$key]);
                        if ($candidate !== '' && strtolower($candidate) !== 'success') {
                            $message = $candidate;
                            break;
                        }
                    }
                }
            }
        }

        return [$code, $message];
    }

    /** @param array<int,array|string> $containers */
    private static function collectContainers(array $value, array &$containers, int $depth): void
    {
        if ($depth > 5) {
            return;
        }
        foreach (['error', 'output', 'data', 'result', 'response'] as $key) {
            if (!array_key_exists($key, $value)) {
                continue;
            }
            $nested = $value[$key];
            if (is_string($nested) && trim($nested) !== '') {
                $containers[] = $nested;
            } elseif (is_array($nested)) {
                if (self::isAssociative($nested)) {
                    self::collectContainers($nested, $containers, $depth + 1);
                    $containers[] = $nested;
                } else {
                    foreach ($nested as $item) {
                        if (is_array($item)) {
                            $containers[] = $item;
                            self::collectContainers($item, $containers, $depth + 1);
                        }
                    }
                }
            }
        }
        $containers[] = $value;
    }

    private static function isParameterError(string $code, string $message): bool
    {
        $value = strtolower($code . ' ' . $message);
        foreach ([
            'invalidparameter', 'invalid_parameter', 'parametererror', 'parameter_error',
            'validationerror', 'validation_error', 'invalidrequest', 'invalid_request',
            'invalid parameter', 'invalid argument', 'invalid value', 'invalid format',
            'request parameter', 'parameter ',
            'missing required', 'required field', 'required parameter', 'unknown field',
            'unknown parameter', 'unsupported value', 'out of range', 'expected one of',
            'is required', 'must be', 'must not be', 'should be',
            '参数错误', '参数不正确', '参数无效', '参数缺失', '缺少参数', '字段错误',
            '字段缺失', '必填字段', '不支持的参数', '取值错误',
        ] as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }
        return false;
    }

    public static function isClaudeClientRestricted(string $message): bool
    {
        $value = strtolower($message);
        return (str_contains($value, 'standard claude code client') && str_contains($value, 'anomaly in your client'))
            || str_contains($message, '标准 Claude Code 客户端');
    }

    /** @return array{0:string,1:string} */
    private static function codeFromMessage(string $message): array
    {
        if (preg_match('/^([A-Za-z][A-Za-z0-9_.-]{2,64})\s*[:：]\s*(.+)$/u', $message, $match) === 1) {
            return [self::cleanCode($match[1]), self::clean($match[2])];
        }
        if (preg_match('/^(.+?)\s*\(([A-Za-z][A-Za-z0-9_.-]{2,64})\)$/u', $message, $match) === 1) {
            return [self::cleanCode($match[2]), self::clean($match[1])];
        }
        return ['', $message];
    }

    private static function withCode(string $code, string $message): string
    {
        return mb_substr($code !== '' ? $code . '：' . $message : $message, 0, 1000, 'UTF-8');
    }

    private static function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? $value);
    }

    private static function cleanCode(string $value): string
    {
        $value = trim($value);
        if ($value === '' || in_array(strtolower($value), ['0', '1', '200', 'ok', 'success', 'failed', 'error', 'nil', '<nil>', 'null', '<null>', 'none', 'undefined'], true)) {
            return '';
        }
        return mb_substr($value, 0, 80, 'UTF-8');
    }

    private static function isAssociative(array $value): bool
    {
        return $value !== [] && array_keys($value) !== range(0, count($value) - 1);
    }
}
