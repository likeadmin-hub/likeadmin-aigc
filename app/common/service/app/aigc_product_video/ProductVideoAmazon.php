<?php
declare(strict_types=1);

namespace app\common\service\app\aigc_product_video;

use app\common\service\open_platform\{OpenSafeHttp, OpenPolicy, OpenException};
use InvalidArgumentException;

/** Public product pages only. Never execute page scripts or collect related-product images. */
final class ProductVideoAmazon
{
    private const DOMAINS = 'com|co\.uk|de|fr|it|es|co\.jp|ca|com\.au|in|com\.br|com\.mx|sg|ae|sa|nl|se|pl|com\.be|com\.tr|ie|co\.za|eg';
    private const SHORT_HOSTS = ['a.co', 'amzn.to', 'amzn.eu', 'amzn.asia'];

    public static function target(string $input): array
    {
        $url = html_entity_decode(trim($input), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (strlen($url) > 2000 || preg_match('/[\x00-\x20\\\\]/', $url)) throw new InvalidArgumentException('请填写完整的亚马逊商品链接');
        $parts = parse_url($url);
        if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            throw new InvalidArgumentException('请填写有效的亚马逊商品链接');
        }
        $host = strtolower($parts['host'] ?? '');
        if (in_array($host, self::SHORT_HOSTS, true)) {
            if (empty($parts['path']) || $parts['path'] === '/') throw new InvalidArgumentException('亚马逊短链接不完整');
            return ['url' => 'https://' . $host . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : ''), 'asin' => '', 'short' => true];
        }
        if (!self::productHost($host) || !preg_match('~/(?:dp|gp/product|gp/aw/d)/([a-z0-9]{10})(?:/|$)~i', $parts['path'] ?? '', $match)) {
            throw new InvalidArgumentException('请填写亚马逊商品详情页链接，暂不支持搜索或店铺链接');
        }
        parse_str($parts['query'] ?? '', $query);
        $selection = [];
        foreach (['th', 'psc'] as $key) if (isset($query[$key]) && is_scalar($query[$key]) && preg_match('/^\d{1,3}$/D', (string)$query[$key])) $selection[$key] = (string)$query[$key];
        return ['url' => 'https://' . $host . '/dp/' . strtoupper($match[1]) . ($selection ? '?' . http_build_query($selection) : ''), 'asin' => strtoupper($match[1]), 'short' => false];
    }

    public static function productHost(string $host): bool
    {
        return (bool)preg_match('/(?:^|\.)amazon\.(?:' . self::DOMAINS . ')$/D', strtolower($host));
    }

    public static function imageUrl(string $url): bool
    {
        $p = parse_url($url);
        return $p && strtolower($p['scheme'] ?? '') === 'https' && !isset($p['user']) && !isset($p['pass']) && !isset($p['port'])
            && !preg_match('/[\x00-\x20\\\\]/', $url)
            && (bool)preg_match('/(?:^|\.)(?:media-amazon|images-amazon)\.com$/D', strtolower($p['host'] ?? ''));
    }

    /** Every redirect is host-checked and DNS-pinned before fetching. */
    public static function fetch(string $url, bool $image = false): array
    {
        $deadline = microtime(true) + ($image ? 12 : 22);
        for ($redirect = 0; $redirect < 5; $redirect++) {
            if (!$image) $url = self::target($url)['url'];
            $host = strtolower((string)parse_url($url, PHP_URL_HOST));
            if ($image ? !self::imageUrl($url) : (!self::productHost($host) && !in_array($host, self::SHORT_HOSTS, true))) {
                throw new InvalidArgumentException('商品链接跳转到了不支持的地址');
            }
            [$host, $ip] = self::address($url);
            $body = ''; $headers = []; $maximum = $image ? 10485760 : 4194304;
            $timeout = (int)ceil($deadline - microtime(true));
            if ($timeout < 1) throw new InvalidArgumentException('亚马逊读取超时，请稍后重试');
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RESOLVE => [$host . ':443:' . (str_contains($ip, ':') ? '[' . $ip . ']' : $ip)],
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout), CURLOPT_TIMEOUT => $timeout,
                CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LikeAdminProductImport/1.0)',
                CURLOPT_HTTPHEADER => ['Accept: ' . ($image ? 'image/webp,image/*' : 'text/html,application/xhtml+xml'), 'Accept-Language: en-US,en;q=0.9'],
                CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$headers) {
                    if (str_starts_with($line, 'HTTP/')) $headers = [];
                    elseif (str_contains($line, ':')) { [$name, $value] = explode(':', $line, 2); $headers[strtolower(trim($name))] = trim($value); }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static function ($ch, $bytes) use (&$body, $maximum) {
                    if (strlen($body) + strlen($bytes) > $maximum) return 0;
                    $body .= $bytes; return strlen($bytes);
                },
            ]);
            try { $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); }
            finally { curl_close($curl); }
            if ($ok === false) throw new InvalidArgumentException($image ? '商品图片暂时无法读取' : '亚马逊页面暂时无法读取，请稍后重试或手动上传');
            if (in_array($status, [301,302,303,307,308], true) && isset($headers['location'])) {
                $url = OpenSafeHttp::redirectUrl($url, $headers['location']); continue;
            }
            if ($status < 200 || $status >= 300) throw new InvalidArgumentException($image ? '商品图片暂时无法读取' : '亚马逊暂未开放本次页面访问，请重试或手动上传');
            if (!$image) $url = self::target($url)['url'];
            return ['url' => $url, 'body' => $body];
        }
        throw new InvalidArgumentException('商品链接跳转次数过多，请使用完整详情页链接');
    }

    public static function metadata(string $html, string $base): array
    {
        if (strlen($html) > 4194304) throw new InvalidArgumentException('商品页面过大，请改用手动上传');
        $target = self::target($base);
        $doc = new \DOMDocument(); $previous = libxml_use_internal_errors(true);
        try { $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new \DOMXPath($doc);
        $text = static fn(string $path): string => self::text($xpath->query($path)->item(0)?->textContent ?? '');
        // Generic metadata is a fallback; the product gallery and product sections take priority.
        $fallback = ProductVideoProducts::metadata($html, $base);
        $name = $text('//*[@id="productTitle"]') ?: $fallback['name'];
        $brand = $text('//*[@id="bylineInfo"]');
        $brand = preg_replace('/^(?:Visit the\s+)|\s+Store$|^(?:Brand|品牌)\s*[:：]\s*/iu', '', $brand);
        $bullets = []; $details = [];
        foreach ($xpath->query('//*[@id="feature-bullets"]//li') as $node) {
            $value = self::text($node->textContent);
            if ($value !== '' && !preg_match('/make sure this fits|see more product details/i', $value)) $bullets[] = mb_substr($value, 0, 450);
        }
        foreach ($xpath->query('//*[starts-with(@id,"productDetails_")]//tr') as $node) {
            $cells = $xpath->query('./th|./td', $node);
            if ($cells->length >= 2) {
                $key = self::text($cells->item(0)->textContent); $value = self::text($cells->item(1)->textContent);
                if ($key !== '' && $value !== '' && !preg_match('/customer reviews|best sellers rank/i', $key)) $details[] = $key . '：' . $value;
            }
        }
        foreach ($xpath->query('//*[@id="detailBullets_feature_div"]//li') as $node) {
            $value = self::text($node->textContent);
            if ($value !== '' && !preg_match('/customer reviews|best sellers rank/i', $value)) $details[] = $value;
        }
        $images = []; $seen = [];
        $add = static function ($url) use (&$images, &$seen) {
            if (!is_string($url)) return;
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($url, '//')) $url = 'https:' . $url;
            if (!self::imageUrl($url)) return;
            $key = preg_replace('/\._[^\/]+_\.(jpe?g|png|webp)(?:\?.*)?$/i', '.$1', $url);
            if (isset($seen[$key])) return;
            $seen[$key] = true; $images[] = $url;
        };
        foreach ($xpath->query('//*[@id="landingImage" or @id="imgBlkFront" or @id="main-image"]') as $node) {
            $add($node->getAttribute('data-old-hires'));
            $dynamic = json_decode($node->getAttribute('data-a-dynamic-image'), true);
            if (is_array($dynamic)) {
                uasort($dynamic, static fn($a, $b) => array_product((array)$b) <=> array_product((array)$a));
                foreach ($dynamic as $url => $size) $add($url);
            }
            $add($node->getAttribute('src'));
        }
        foreach ($doc->getElementsByTagName('script') as $script) {
            $content = $script->textContent;
            foreach (self::values($content, 'colorImages') as $gallery) {
                // Other color keys contain unselected variants. Only import the current initial gallery.
                foreach ((array)($gallery['initial'] ?? []) as $image) if (is_array($image)) $add(($image['hiRes'] ?? '') ?: ($image['large'] ?? ''));
            }
            foreach (self::encodedInitial($content) as $image) if (is_array($image)) $add(($image['hiRes'] ?? '') ?: ($image['large'] ?? ''));
            foreach (self::values($content, 'imageGalleryData') as $gallery) foreach ($gallery as $image) if (is_array($image)) $add(($image['hiRes'] ?? '') ?: ($image['mainUrl'] ?? $image['large'] ?? ''));
        }
        if (!$images) foreach ($fallback['images'] as $url) $add($url);
        if (!$name || !$images || preg_match('/robot check|amazon captcha/i', $name)) {
            throw new InvalidArgumentException('亚马逊未返回可读取的商品资料，可能需要验证；请重试或手动上传');
        }
        $lines = ['【商品名称】' . $name];
        if ($brand !== '') $lines[] = '【品牌】' . $brand;
        $lines[] = '【商品编号】' . $target['asin'];
        if ($bullets) $lines[] = "【商品卖点】\n" . implode("\n", array_map(static fn($v) => '• ' . $v, array_slice(array_unique($bullets), 0, 8)));
        if ($details) $lines[] = "【商品规格】\n" . implode("\n", array_map(static fn($v) => mb_substr($v, 0, 300), array_slice(array_unique($details), 0, 10)));
        $description = $text('//*[@id="productDescription"]');
        if ($description !== '') $lines[] = '【商品介绍】' . mb_substr($description, 0, 1200);
        elseif (!$bullets && $fallback['product_info'] !== $name) $lines[] = $fallback['product_info'];
        return ['name' => mb_substr($name, 0, 100), 'product_info' => mb_substr(implode("\n", $lines), 0, 3000), 'images' => array_slice($images, 0, 60), 'source_url' => $target['url'], 'asin' => $target['asin']];
    }

    private static function text(string $value): string
    {
        return trim(preg_replace('/[\s\x{200e}\x{200f}]+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    /** Some current Amazon pages wrap the gallery JSON in A.$.parseJSON('...'). */
    private static function encodedInitial(string $script): array
    {
        if (!preg_match('/["\']colorImages["\']\s*:\s*\{\s*["\']initial["\']\s*:\s*(?:[\w$]+\.)*(?:parseJSON|parse)\s*\(\s*(["\'])/', $script, $match, PREG_OFFSET_CAPTURE)) return [];
        $quote = $match[1][0]; $start = $match[1][1] + 1; $value = '';
        for ($i = $start, $end = min(strlen($script), $start + 524288); $i < $end; $i++) {
            $c = $script[$i];
            if ($c === $quote) return (array)json_decode($value, true, 32);
            if ($c !== '\\') { $value .= $c; continue; }
            if (++$i >= $end) return [];
            $escape = $script[$i];
            if ($escape === 'u') {
                $sequence = substr($script, $i - 1, 6); $decoded = json_decode('"' . $sequence . '"');
                if (!is_string($decoded)) return [];
                $value .= $decoded; $i += 4;
            } elseif ($escape === 'x') {
                $hex = substr($script, $i + 1, 2); if (!ctype_xdigit($hex)) return [];
                $value .= chr(hexdec($hex)); $i += 2;
            } else $value .= ['n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>"\x08",'f'=>"\x0c"][$escape] ?? $escape;
        }
        return [];
    }

    /** Optional per-app public DNS for local fake-IP proxies; all returned IPs remain validated and pinned. */
    private static function address(string $url): array
    {
        try { return OpenSafeHttp::resolve($url); }
        catch (OpenException $e) { if (!env('product_video.import_public_dns', false)) throw $e; }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $cacheKey = 'product_video_public_dns_v1:' . hash('sha256', $host);
        $ips = \think\facade\Cache::get($cacheKey);
        if (!is_array($ips) || !$ips) {
            $body = ''; $curl = curl_init('https://dns.google/resolve?name=' . rawurlencode($host) . '&type=A&edns_client_subnet=0.0.0.0%2F0');
            curl_setopt_array($curl, [CURLOPT_RESOLVE => ['dns.google:443:8.8.8.8'], CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6,
                CURLOPT_WRITEFUNCTION => static function ($ch, $bytes) use (&$body) { if (strlen($body) + strlen($bytes) > 65536) return 0; $body .= $bytes; return strlen($bytes); }]);
            try { $ok = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); }
            finally { curl_close($curl); }
            $data = json_decode($body, true);
            if (!$ok || $status !== 200 || ($data['Status'] ?? -1) !== 0) throw new InvalidArgumentException('商品域名暂时无法解析，请稍后重试');
            $ips = [];
            foreach ($data['Answer'] ?? [] as $answer) if (($answer['type'] ?? 0) === 1) $ips[] = (string)($answer['data'] ?? '');
        }
        if (!$ips) throw new InvalidArgumentException('商品域名暂时无法解析，请稍后重试');
        foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !OpenPolicy::isPublicIp($ip)) throw new InvalidArgumentException('商品域名未返回有效公网地址');
        \think\facade\Cache::set($cacheKey, $ips, 60);
        return [$host, $ips[0]];
    }

    /** Read bounded JSON values inside scripts without eval, regex object matching or executing JS. */
    private static function values(string $script, string $key): array
    {
        preg_match_all('/["\']' . preg_quote($key, '/') . '["\']\s*:\s*([\[{])/', $script, $matches, PREG_OFFSET_CAPTURE);
        $values = [];
        foreach (array_slice($matches[1], 0, 8) as [$start, $offset]) {
            $depth = 0; $quote = false; $escape = false;
            for ($i = $offset, $end = min(strlen($script), $offset + 524288); $i < $end; $i++) {
                $c = $script[$i];
                if ($quote) { if ($escape) $escape = false; elseif ($c === '\\') $escape = true; elseif ($c === '"') $quote = false; continue; }
                if ($c === '"') $quote = true;
                elseif ($c === '[' || $c === '{') $depth++;
                elseif ($c === ']' || $c === '}') {
                    if (--$depth === 0) { $value = json_decode(substr($script, $offset, $i - $offset + 1), true, 32); if (is_array($value)) $values[] = $value; break; }
                }
            }
        }
        return $values;
    }
}
