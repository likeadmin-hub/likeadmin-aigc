<?php
namespace app\common\service\app\aigc_short_drama\vnext;

use RuntimeException;

/** Read-only, owner-scoped projection. Never exposes the entire frozen model/provider input. */
final class ManualRevisionHistory
{
    public static function page(array $input, int $tenant, int $user, int $page): array
    {
        if ($page < 1) throw new RuntimeException('修改记录页码无效', 422);
        $history = $input['context']['manual_edit_history'] ?? null;
        $empty = ['status' => 'not_recorded', 'page' => $page, 'total' => 0, 'records' => [], 'next_page' => null];
        if ($history === null) return $empty;
        if (!is_array($history)) throw new RuntimeException('修改记录暂时无法核对，请稍后重试', 409);
        ManualContentRevision::assertHistory($history);
        $events = $history['events']; $total = count($events);
        foreach ($events as $event) if (($event['tenant_id'] ?? null) !== $tenant || ($event['user_id'] ?? null) !== $user) {
            throw new RuntimeException('修改记录不属于当前用户', 409);
        }
        if (!$total) return $empty;
        $result = ['status' => 'recorded', 'page' => $page, 'total' => $total, 'records' => [], 'next_page' => $page < $total ? $page + 1 : null];
        if ($page > $total) return $result;
        $event = $events[$total - $page]; $sources = $input['context']['sources'] ?? [];
        $document = $input['context']['canonical_input'] ?? [];
        $names = array_column($document['entities'] ?? [], 'canonical_name', 'entity_id'); $changes = [];
        foreach (ContentDocument::rows($event['changes'] ?? null, 'manual_history.changes') as $change) {
            $source = $sources[$change['source_id'] ?? ''] ?? [];
            $text = ContentDocument::text($source['text'] ?? null, 'manual_history.after_text');
            $before = ContentDocument::text($change['before_text'] ?? null, 'manual_history.before_text');
            if (($source['hash'] ?? '') !== hash('sha256', $text) || ($change['after_text_hash'] ?? '') !== $source['hash']
                || ($change['before_text_hash'] ?? '') !== hash('sha256', $before)
                || ($source['version'] ?? '') !== $event['revision_id']
                || ($source['origin']['event_id'] ?? '') !== $event['event_id']
                || ($source['origin']['unit_id'] ?? '') !== $change['unit_id']
                || ($source['origin']['tenant_id'] ?? null) !== $tenant || ($source['origin']['user_id'] ?? null) !== $user) {
                throw new RuntimeException('修改记录来源不一致，请稍后重试', 409);
            }
            // Quotes are captured at edit time; no external attachment or foreign task is fetched here.
            $quotes = [];
            foreach (ContentDocument::rows($change['before_source_links'] ?? [], 'manual_history.before_sources') as $link) {
                if (is_string($link['quote'] ?? null)) $quotes[] = $link['quote'];
            }
            $changes[] = ['unit_id' => $change['unit_id'], 'speaker_name' => $names[$change['speaker_id'] ?? ''] ?? '',
                'before_text' => $before, 'after_text' => $text, 'before_source_quotes' => $quotes];
        }
        $result['records'][] = ['event_id' => $event['event_id'], 'edit_number' => $total - $page + 1,
            'created_at' => $event['created_at'], 'actor_label' => '你', 'changes' => $changes];
        return $result;
    }
}
