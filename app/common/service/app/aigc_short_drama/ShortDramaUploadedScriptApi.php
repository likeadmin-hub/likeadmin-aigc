<?php
namespace app\common\service\app\aigc_short_drama;

/** Uploads and explicit retries share the application API route, never an LLM fallback. */
final class ShortDramaUploadedScriptApi
{
    public static function request(array $request): array
    {
        $source = (array)($request['_file_parse_fallback'] ?? []);
        $text = $source['text'] ?? $request['_file_parse_model']['text'] ?? '';
        unset($request['parse_model_id'], $request['_file_parse_model'], $request['_file_parse_fallback']);
        $request['source'] = 'market_file_qa_parse';
        $request['script_source'] = 'upload';
        // Retain immutable source validation, without a paid model or repair state.
        if (is_string($text) && $text !== '') {
            $request['_file_parse_fallback'] = ['version' => 1, 'text' => $text];
        } elseif (isset($source['unavailable_reason'])) {
            $request['_file_parse_fallback'] = ['version' => 1, 'unavailable_reason' => $source['unavailable_reason']];
        }
        return $request;
    }
}
