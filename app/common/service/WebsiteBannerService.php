<?php

namespace app\common\service;

class WebsiteBannerService
{
    private const CONFIG_TYPE = 'website';
    private const CONFIG_NAME = 'pc_home_banners';

    public static function lists(bool $onlyVisible = false): array
    {
        $rows = ConfigService::get(self::CONFIG_TYPE, self::CONFIG_NAME, []);
        if (!is_array($rows)) {
            $rows = [];
        }
        $rows = array_map([self::class, 'normalizeForOutput'], $rows);
        if ($onlyVisible) {
            $now = time();
            $rows = array_values(array_filter($rows, static function ($row) use ($now) {
                if ((int)($row['status'] ?? 1) !== 1) {
                    return false;
                }
                $start = self::parseTime($row['start_time'] ?? '');
                $end = self::parseTime($row['end_time'] ?? '');
                if ($start > 0 && $start > $now) {
                    return false;
                }
                if ($end > 0 && $end < $now) {
                    return false;
                }
                return !empty($row['media_url']);
            }));
        }
        usort($rows, static function ($left, $right) {
            $sortCompare = (int)($right['sort'] ?? 0) <=> (int)($left['sort'] ?? 0);
            if ($sortCompare !== 0) {
                return $sortCompare;
            }
            return strcmp((string)($left['id'] ?? ''), (string)($right['id'] ?? ''));
        });
        return $rows;
    }

    public static function save(array $params): array
    {
        $rows = self::lists(false);
        $item = self::normalizeForStorage($params);
        $updated = false;
        foreach ($rows as $index => $row) {
            if ((string)$row['id'] === (string)$item['id']) {
                $rows[$index] = $item;
                $updated = true;
                break;
            }
        }
        if (!$updated) {
            $rows[] = $item;
        }
        self::persist($rows);
        return self::normalizeForOutput($item);
    }

    public static function delete(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            return;
        }
        $rows = array_values(array_filter(self::lists(false), static fn($row) => (string)$row['id'] !== $id));
        self::persist($rows);
    }

    public static function status(string $id, int $status): void
    {
        $id = trim($id);
        $rows = self::lists(false);
        foreach ($rows as &$row) {
            if ((string)$row['id'] === $id) {
                $row['status'] = $status === 1 ? 1 : 0;
                break;
            }
        }
        self::persist($rows);
    }

    private static function persist(array $rows): void
    {
        $storageRows = array_map([self::class, 'normalizeForStorage'], $rows);
        ConfigService::set(self::CONFIG_TYPE, self::CONFIG_NAME, $storageRows);
    }

    private static function normalizeForStorage(array $params): array
    {
        $mediaType = (string)($params['media_type'] ?? 'image');
        if (!in_array($mediaType, ['image', 'video'], true)) {
            $mediaType = 'image';
        }
        $linkType = (string)($params['link_type'] ?? 'none');
        if (!in_array($linkType, ['none', 'path', 'url', 'app'], true)) {
            $linkType = 'none';
        }
        $id = trim((string)($params['id'] ?? ''));
        if ($id === '') {
            $id = 'banner_' . str_replace('.', '', uniqid('', true));
        }
        return [
            'id' => $id,
            'title' => mb_substr(trim((string)($params['title'] ?? '')), 0, 80),
            'description' => mb_substr(trim((string)($params['description'] ?? $params['subtitle'] ?? '')), 0, 200),
            'media_type' => $mediaType,
            'media_uri' => FileService::setFileUrl((string)($params['media_uri'] ?? $params['media_url'] ?? $params['image'] ?? '')),
            'poster_uri' => FileService::setFileUrl((string)($params['poster_uri'] ?? $params['poster_url'] ?? '')),
            'link_type' => $linkType,
            'link_path' => trim((string)($params['link_path'] ?? '')),
            'link_url' => trim((string)($params['link_url'] ?? '')),
            'link_app_code' => trim((string)($params['link_app_code'] ?? '')),
            'sort' => (int)($params['sort'] ?? 0),
            'status' => (int)($params['status'] ?? 1) === 1 ? 1 : 0,
            'start_time' => trim((string)($params['start_time'] ?? '')),
            'end_time' => trim((string)($params['end_time'] ?? '')),
        ];
    }

    private static function normalizeForOutput(array $row): array
    {
        $row = self::normalizeForStorage($row);
        $row['media_url'] = $row['media_uri'] !== '' ? FileService::getFileUrl($row['media_uri']) : '';
        $row['poster_url'] = $row['poster_uri'] !== '' ? FileService::getFileUrl($row['poster_uri']) : '';
        return $row;
    }

    private static function parseTime($value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return 0;
        }
        $timestamp = strtotime($value);
        return $timestamp ? (int)$timestamp : 0;
    }
}
