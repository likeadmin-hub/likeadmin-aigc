<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use app\common\service\app\aigc_short_drama\ShortDramaAttachmentSource;
use app\common\service\app\aigc_short_drama\ShortDramaSourceBoundary;
use RuntimeException;

/** Explicit screenplay labels only. Never infer a speaker, location or missing prose. */
final class AttachmentScreenplay
{
    public const VERSION = 3;

    public static function prepare(array $package, array $episode, array $entities): array
    {
        ShortDramaAttachmentSource::assertPackage($package); ShortDramaAttachmentSource::assertEpisode($episode);
        $trace = $episode['attachment_trace'] ?? []; $full = $package['source'];
        if (($trace['package_hash'] ?? '') !== $package['package_hash'] || ($trace['source_hash'] ?? '') !== $full['hash']
            || ($trace['file_sha256'] ?? '') !== $package['file_sha256'] || ($trace['source_task_id'] ?? '') !== $package['task_id']
            || ($trace['source_id'] ?? '') !== $full['source_id'] || ($trace['source_version'] ?? '') !== $full['version']) throw new RuntimeException('附件分集来源与确认文件不一致', 409);
        $text = ContentDocument::slice($full['text'], $trace['start'], $trace['end'], $episode['source_content']);
        $source = SourceTrace::capture($full['source_id'] . ':episode:' . $episode['episode_number'], $package['package_hash'], $text, 'faithful');
        $source['origin'] = ['kind' => 'attachment_episode', 'file_sha256' => $package['file_sha256'], 'source_task_id' => $package['task_id'],
            'source_id' => $full['source_id'], 'source_version' => $full['version'], 'source_hash' => $full['hash'],
            'start' => $trace['start'], 'end' => $trace['end'], 'extraction' => $package['extraction']];
        $names = [];
        foreach ($entities as $entity) if (in_array($entity['kind'], ['location', 'character', 'prop'], true)) {
            if ($entity['kind'] === 'character' && (in_array($entity['name'], ['场景', '地点', '动作', '环境', '旁白'], true) || preg_match('/^动作[（(]/u', $entity['name']))) throw new RuntimeException('人物名称与附件结构标记重名，请明确区分后再导入', 422);
            $names[$entity['kind']][$entity['name']][] = $entity['ref'];
        }
        $lookup = static function (string $kind, string $name) use ($names): string {
            $matches = $names[$kind][$name] ?? [];
            if (count($matches) !== 1) throw new RuntimeException('附件中的人物或场景“' . $name . '”无法唯一对应已确认设定，请核对名称', 422);
            return $matches[0];
        };
        $scenes = []; $layout = []; $cursor = 0; $heading = false;
        foreach (explode("\n", $text) as $line) {
            $length = mb_strlen($line, 'UTF-8'); $end = $cursor + $length;
            if ($end < mb_strlen($text, 'UTF-8')) $end++;
            if ($end === $cursor) continue;
            $entry = ['start' => $cursor, 'end' => $end];
            // A tab or at least two spaces explicitly continues the immediately
            // preceding content unit. Colon-labelled lines remain new labelled
            // units (and unknown speakers are rejected, never guessed).
            $last = count($layout) - 1;
            if (trim($line) !== '' && preg_match('/^(?:\t| {2})/u', $line)
                && !preg_match('/^([\t ]*)([^：:\n]+)([：:])([\s\S]*)$/u', $line)
                && $last >= 0 && $layout[$last]['kind'] === 'content') {
                $previous = &$layout[$last];
                $unit = &$scenes[$previous['scene_index']]['units'][$previous['unit_index']];
                $unit['text'] .= "\n" . $line;
                $unit['source_links'][0]['end'] = $cursor + $length;
                $unit['source_links'][0]['quote'] = $unit['text'];
                $previous['end'] = $end;
                unset($previous, $unit);
                $cursor = $end;
                continue;
            }
            if (trim($line) === '') $entry['kind'] = 'spacing';
            elseif (!$heading && !$scenes && ShortDramaSourceBoundary::sections($line)) {
                $sections = ShortDramaSourceBoundary::sections($line);
                if (array_keys($sections) !== [(int)$episode['episode_number']]) throw new RuntimeException('附件分集标题与本集不符', 422);
                $entry['kind'] = 'episode_heading'; $heading = true;
            } else {
                if (!preg_match('/^([\t ]*)([^：:\n]+)([：:])([\s\S]*)$/u', $line, $matches)) throw new RuntimeException('附件第' . $episode['episode_number'] . '集存在未标明用途的正文，请标明场景、发言者或动作参与人物', 422);
                $label = trim($matches[2]); $body = $matches[4];
                if (in_array($label, ['场景', '地点'], true)) {
                    if ($scenes && !end($scenes)['units']) throw new RuntimeException('附件存在没有正文的场景，请核对场景边界', 422);
                    $scenes[] = ['location' => $lookup('location', trim($body)), 'units' => []];
                    $entry['kind'] = 'scene_heading'; $entry['scene_index'] = count($scenes) - 1;
                } else {
                    if (!$scenes) throw new RuntimeException('附件正文前缺少“场景：地点名”，不能猜测拍摄地点', 422);
                    ContentDocument::text($body, 'attachment.unit');
                    $actors = []; $props = []; $speakerLabel = $label; $action = $label === '环境';
                    if ($label === '动作') throw new RuntimeException('附件动作未标明参与人物，请使用“动作（人物名）：内容”；无人物的画面使用“环境：内容”', 422);
                    if (preg_match('/^动作[（(](.+)[）)]$/u', $label, $actorMatch)) {
                        $action = true;
                        $parts = preg_split('/[；;]/u', $actorMatch[1]);
                        foreach ($parts as $part) {
                            $part = trim($part);
                            if (str_starts_with($part, '道具=')) {
                                foreach (preg_split('/[、，,]/u', substr($part, strlen('道具='))) as $name) $props[] = $lookup('prop', trim($name));
                            } else foreach (preg_split('/[、，,]/u', $part) as $name) $actors[] = $lookup('character', trim($name));
                        }
                        $actors = array_values(array_unique($actors));
                    } elseif (preg_match('/^(.+?)[（(]道具=(.+)[）)]$/u', $label, $propMatch)) {
                        $speakerLabel = trim($propMatch[1]); $action = $speakerLabel === '环境';
                        foreach (preg_split('/[、，,]/u', $propMatch[2]) as $name) $props[] = $lookup('prop', trim($name));
                    }
                    $start = $cursor + mb_strlen($matches[1] . $matches[2] . $matches[3], 'UTF-8');
                    $unit = ['type' => $action ? 'action' : 'dialogue', 'text' => $body,
                        'source_links' => [['source_id' => $source['source_id'], 'source_version' => $source['version'], 'source_hash' => $source['hash'],
                            'start' => $start, 'end' => $cursor + $length, 'quote' => $body]]];
                    if ($speakerLabel === '旁白') $unit['speech_type'] = 'narration';
                    elseif ($action) $unit['characters'] = $actors;
                    else $unit['speaker'] = $lookup('character', $speakerLabel);
                    if ($props) $unit['props'] = array_values(array_unique($props));
                    $scene = count($scenes) - 1; $entry += ['kind' => 'content', 'scene_index' => $scene, 'unit_index' => count($scenes[$scene]['units']), 'text_start' => $start];
                    $scenes[$scene]['units'][] = $unit;
                }
            }
            $layout[] = $entry; $cursor = $end;
        }
        if (!$scenes || !end($scenes)['units'] || $cursor !== mb_strlen($text, 'UTF-8')) throw new RuntimeException('附件没有完整可定位的场景正文', 422);
        return ['schema_version' => self::VERSION, 'source' => $source, 'layout' => $layout,
            'screenplay' => ['title' => ContentDocument::text($episode['title'] ?? null, 'episode.title'), 'scenes' => $scenes]];
    }

    public static function document(array $context): array
    {
        $import = $context['attachment_import'];
        if (!in_array($import['schema_version'] ?? 0, [1, 2, self::VERSION], true) || ($context['structured_screenplay'] ?? null) !== $import['screenplay']) throw new RuntimeException('附件结构版本已变化', 409);
        $source = $import['source'];
        if (($context['sources'][$source['source_id']] ?? null) !== $source || $source['hash'] !== hash('sha256', $source['text'])) throw new RuntimeException('附件正文来源不一致', 409);
        $document = ContentDocument::normalize($import['screenplay'], $context);
        $layout = $import['layout']; $cursor = 0;
        foreach ($layout as &$entry) {
            if ($entry['start'] !== $cursor) throw new RuntimeException('附件布局存在未保留的范围', 409);
            ContentDocument::slice($source['text'], $entry['start'], $entry['end']); $cursor = $entry['end'];
            if ($entry['kind'] === 'content') {
                $unit = $document['scenes'][$entry['scene_index']]['units'][$entry['unit_index']];
                $entry['unit_id'] = $unit['unit_id']; $entry['text_hash'] = $unit['text_hash'];
            }
        }
        unset($entry);
        if ($cursor !== mb_strlen($source['text'], 'UTF-8')) throw new RuntimeException('附件结尾范围不完整', 409);
        $document['transforms'][] = ['code' => 'attachment_import', 'schema_version' => $import['schema_version'],
            'source_id' => $source['source_id'], 'source_hash' => $source['hash'], 'origin' => $source['origin'], 'layout' => $layout];
        return $document;
    }
}
