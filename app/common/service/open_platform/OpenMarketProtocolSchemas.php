<?php
namespace app\common\service\open_platform;

/** Reviewed static protocol structure. Examples are documentation, never execution evidence. */
final class OpenMarketProtocolSchemas
{
    private const FILE = __DIR__ . '/market-protocol-schemas.v1.json';

    public static function definition(string $name): ?array
    {
        static $registry;
        $registry ??= json_decode(file_get_contents(self::FILE), false, 64, JSON_THROW_ON_ERROR);
        $entry = $registry->definitions->{$name} ?? null;
        if (!$entry) return null;
        // Return fresh typed examples so binding a public model never mutates the registry.
        $typed = json_decode(json_encode($entry, JSON_THROW_ON_ERROR), false, 64, JSON_THROW_ON_ERROR);
        $data = json_decode(json_encode($typed, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        foreach (['submit_responses', 'query_responses'] as $field) if (isset($typed->{$field})) {
            foreach (get_object_vars($typed->{$field}) as $status => $response) foreach (get_object_vars($response->content ?? new \stdClass()) as $type => $content) {
                foreach (get_object_vars($content->examples ?? new \stdClass()) as $key => $example) $data[$field][$status]['content'][$type]['examples'][$key]['value'] = $example->value;
            }
        }
        if (isset($typed->request_example)) $data['request_example'] = (array)$typed->request_example;
        $data['trace'] = ['registry_version' => $registry->version, 'definition' => $name, 'upstream_revision' => $registry->reviewed_upstream_revision, 'digest' => hash_file('sha256', self::FILE), 'sources' => $data['sources']];
        return $data;
    }

    public static function applicationInput(string $app, string $api): ?array
    {
        return self::definition('app.' . $app . '.' . $api);
    }

    public static function modelTask(array $product): array
    {
        $entry = self::definition('model_task');
        foreach (['submit_responses', 'query_responses'] as $field) foreach ($entry[$field] as &$response) foreach ($response['content'] as &$content) foreach ($content['examples'] ?? [] as &$example) {
            $data = $example['value']->data;
            if (isset($data->model)) $data->model = (string)$product['upstream_model_code'];
            if (isset($data->channel)) $data->channel = (string)($product['upstream_channel_code'] ?? '');
        }
        return $entry;
    }
}
