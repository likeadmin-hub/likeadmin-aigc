<?php
namespace app\common\service\decorate\agent;

use InvalidArgumentException;

/** Applies a bounded declarative edit vocabulary. Never executes model code. */
class DecorationAgentOperations
{
    private array $scope;
    private array $changes = [];
    private array $images = [];

    public static function apply(array $candidate, array $operations, array $scope, ?DecorationAgentCatalog $catalog = null): array
    {
        $engine = new self(); $engine->scope = $scope;
        if (!in_array($scope['scope'] ?? '', ['page', 'selected', 'new_page'], true)) self::fail('Invalid edit scope');
        if (count($operations) > 100) self::fail('Too many operations');
        $candidate['data'] = $candidate['data'] ?? [];
        self::index($candidate['data']);
        foreach ($operations as $operation) {
            if (!is_array($operation)) self::fail('Operation must be an object');
            $before = self::index($candidate['data']);
            $engine->execute($candidate, $operation);
            $after = self::index($candidate['data']);
            $counts = function ($map) { $counts=[]; foreach ($map as $node) { $name=$node['widget']['name']; $counts[$name]=($counts[$name]??0)+1; } return $counts; };
            $oldCounts=$counts($before);
            foreach ($counts($after) as $name=>$count) if ($count>1 && $count>($oldCounts[$name]??0) && !DecorationAgentCatalog::definition($name)['repeatable']) self::fail('Component is not repeatable: '.$name);
        }
        return ['candidate' => $candidate, 'changes' => $engine->changes, 'image_requests' => $engine->images];
    }

    public static function index(array $data, array $parentPath = [], int $depth = 1, array &$seen = []): array
    {
        if ($depth > 4) self::fail('Maximum component nesting depth is 4');
        $map = [];
        foreach ($data as $i => $widget) {
            if (!is_array($widget) || !is_string($widget['name'] ?? null)) self::fail('Malformed component tree');
            $path = array_merge($parentPath, [$i]);
            $id = (string)($widget['id'] ?? ('legacy_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $widget['name']) . '_' . implode('_', $path)));
            if ($id === '' || isset($seen[$id])) self::fail('Duplicate or empty component id: ' . $id);
            $seen[$id] = true;
            $map[$id] = ['path' => $path, 'widget' => $widget];
            if (!empty($widget['children'])) $map += self::index($widget['children'], $path, $depth + 1, $seen);
        }
        return $map;
    }
    private static function fail(string $message): void { throw new InvalidArgumentException($message); }
    private function assertScope(string $id, array $tree): void
    {
        if (($this->scope['scope'] ?? '') !== 'selected') return;
        $selected = (string)($this->scope['selected_id'] ?? '');
        if (!isset($tree[$selected], $tree[$id])) self::fail('Selected component is missing');
        $prefix = $tree[$selected]['path'];
        if (array_slice($tree[$id]['path'], 0, count($prefix)) !== $prefix) self::fail('Operation escapes selected component');
    }
    private static function &widget(array &$data, array $path): array
    {
        $node =& $data[array_shift($path)];
        foreach ($path as $i) $node =& $node['children'][$i];
        return $node;
    }
    private static function &siblings(array &$data, array $path): array
    {
        array_pop($path); $list =& $data;
        foreach ($path as $i) $list =& $list[$i]['children'];
        return $list;
    }
    private function insert(array &$data, array $widget, array $op): void
    {
        $parentId = (string)($op['parent_id'] ?? ''); $map = self::index($data);
        $list =& $data;
        if ($parentId !== '') {
            if (!isset($map[$parentId])) self::fail('Parent component is missing');
            $this->assertScope($parentId, $map);
            $parent =& self::widget($data, $map[$parentId]['path']);
            if (!DecorationAgentCatalog::definition($parent['name'])['children']['supported']) self::fail('Parent does not support children');
            if (!isset($parent['children'])) $parent['children'] = [];
            $list =& $parent['children'];
        } elseif (($this->scope['scope'] ?? '') === 'selected') self::fail('Root insertion escapes selected component');
        $index = $op['index'] ?? count($list);
        if (!is_int($index) || $index < 0 || $index > count($list)) self::fail('Insertion index out of range');
        array_splice($list, $index, 0, [$widget]);
    }
    private static function newId(): string { return 'agent_' . bin2hex(random_bytes(12)); }
    private static function cloneIds(array $widget): array
    {
        $widget['id'] = self::newId();
        if (isset($widget['children'])) $widget['children'] = array_map([self::class, 'cloneIds'], $widget['children']);
        return $widget;
    }
    private function execute(array &$page, array $op): void
    {
        $type = $op['type'] ?? $op['op'] ?? '';
        $map = self::index($page['data']); $id = (string)($op['widget_id'] ?? '');
        if (in_array($type, ['configure_page','replace_page'], true)) {
            if ($this->scope['scope'] === 'selected') self::fail('Page edit escapes selected scope');
            if ($type === 'configure_page') $this->configurePage($page, $op);
            else {
                $next = [];
                foreach ($op['components'] ?? [] as $spec) $next[] = $this->build($spec);
                $page['data'] = $next;
            }
        } elseif ($type === 'add_component') {
            $widget = $this->build($op); $id = $widget['id']; $this->insert($page['data'], $widget, $op);
        } else {
            if (!isset($map[$id])) self::fail('Target component is missing: ' . $id);
            $this->assertScope($id, $map);
            $widget =& self::widget($page['data'], $map[$id]['path']);
            $definition = DecorationAgentCatalog::definition($widget['name']);
            if ($type === 'update_component') $this->fields($widget, $definition, $op['fields'] ?? []);
            elseif ($type === 'delete_component') {
                $list =& self::siblings($page['data'], $map[$id]['path']); array_splice($list, end($map[$id]['path']), 1);
            } elseif ($type === 'copy_component') $this->insert($page['data'], self::cloneIds($widget), $op);
            elseif ($type === 'move_component') {
                $target = $op['parent_id'] ?? '';
                if ($target !== '' && isset($map[$target]) && array_slice($map[$target]['path'],0,count($map[$id]['path'])) === $map[$id]['path']) self::fail('Cannot move component into itself');
                $copy = $widget; $list =& self::siblings($page['data'], $map[$id]['path']); array_splice($list, end($map[$id]['path']), 1);
                $this->insert($page['data'], $copy, $op);
            } elseif ($type === 'edit_list') $this->editList($widget, $definition, $op);
            elseif ($type === 'bind_source') $this->bind($widget, $definition, $op);
            elseif ($type === 'request_image') $this->image($widget, $definition, $id, $op);
            else self::fail('Unsupported decoration operation: ' . $type);
        }
        $this->changes[] = ['type' => $type, 'widget_id' => $id, 'fields' => array_keys($op['fields'] ?? [])];
    }
    private function build(array $spec): array
    {
        $widget = DecorationAgentCatalog::create((string)($spec['name'] ?? ''), self::newId());
        $definition = DecorationAgentCatalog::definition($widget['name']);
        $this->fields($widget, $definition, $spec['fields'] ?? []);
        if (!empty($spec['children'])) {
            if (!$definition['children']['supported']) self::fail('Component does not support children');
            $widget['children'] = array_map(fn($child) => $this->build($child), $spec['children']);
        }
        return $widget;
    }
    private function fields(array &$widget, array $definition, array $fields): void
    {
        foreach ($fields as $path => $value) {
            $schema = $this->field($definition, $path, $widget);
            if ($path === 'content.font_size_mode') self::fail('Font compatibility mode is set only by an explicit font edit');
            if ($widget['name'] === 'notice' && $path === 'content.font_size' && self::get($widget,$path) !== $value) $widget['content']['font_size_mode'] = 'configured';
            $this->validate($value, $schema);
            if (isset($schema['list'])) {
                $old = $widget['content'][substr($path, 8)] ?? [];
                foreach ($value as $i => $row) if (isset($old[$i]) && is_array($old[$i])) $value[$i] = array_replace($old[$i], $row);
            } elseif (($schema['value_type'] ?? '') === 'object' && is_array($value)) {
                $parts = explode('.', $path); $old = $widget; foreach ($parts as $part) $old = is_array($old) ? ($old[$part] ?? []) : [];
                if (is_array($old)) $value = ($schema['type'] ?? '') === 'link' ? array_replace(array_diff_key($old, array_flip(['path','url','query','type','name','title'])), $value) : array_replace($old, $value);
            }
            self::set($widget, $path, $value);
        }
    }
    private function field(array $definition, string $path, array $widget): array
    {
        if (!preg_match('/^(content|styles)\.([a-zA-Z0-9_-]+)(?:\.(.*))?$/D', $path, $match)) self::fail('Invalid field path: ' . $path);
        $section = $match[1]; $key = $match[2]; $rest = $match[3] ?? '';
        $schema = $definition[($section==='styles'?'style':'content') . '_fields'][$key] ?? null;
        if (!$schema) self::fail('Unapproved component field: ' . $path);
        if ($rest === '') {
            if (in_array($key, ['source_key','source_params','data_mode','field_map'], true)) self::fail('Use bind_source for source configuration');
            if (isset($definition['lists'][$key])) return $schema + ['list' => $definition['lists'][$key]];
            return $schema;
        }
        if ($section === 'content' && isset($definition['lists'][$key]) && preg_match('/^(\d+)\.([a-zA-Z0-9_-]+)$/D', $rest, $row)) {
            if (!isset($widget['content'][$key][(int)$row[1]])) self::fail('List row does not exist');
            $rowSchema = $definition['lists'][$key]['fields'][$row[2]] ?? null;
            if (!$rowSchema || $row[2] === 'id') self::fail('Unapproved list field');
            return $rowSchema;
        }
        // Nested objects can only edit properties described by the trusted default.
        if (($schema['value_type'] ?? '') === 'object' && array_key_exists($rest, $schema['default']) && strpos($rest,'.') === false) {
            $default = $schema['default'][$rest];
            return ['value_type' => is_int($default)||is_float($default)?'number':(is_bool($default)?'boolean':(is_array($default)?'object':'string')), 'default' => $default, 'type' => 'text'];
        }
        self::fail('Unapproved nested field: ' . $path);
    }
    public static function get(array $object, string $path)
    {
        $value=$object; foreach(explode('.', $path) as $part) { if(!is_array($value)||!array_key_exists($part,$value))return null; $value=$value[$part]; } return $value;
    }
    public static function set(array &$object, string $path, $value): void
    {
        $parts = explode('.', $path); $leaf = array_pop($parts); $node =& $object;
        foreach ($parts as $part) { if (!isset($node[$part])) $node[$part]=[]; if (!is_array($node[$part])) self::fail('Field parent is not an object'); $node =& $node[$part]; }
        $node[$leaf] = $value;
    }
    private function validate($value, array $schema): void
    {
        $type = $schema['value_type'] ?? 'string';
        $valid = $type === 'number' ? (is_int($value)||is_float($value)) : ($type === 'string' ? is_string($value) : ($type === 'boolean' ? is_bool($value) : ($type === 'null' ? $value===null : is_array($value))));
        if (!$valid) self::fail('Wrong field value type: expected ' . $type);
        if ($type === 'array' && array_values($value)!==$value) self::fail('Expected a list');
        if ($type === 'number' && (!is_finite((float)$value) || (isset($schema['min']) && $value < $schema['min']) || (isset($schema['max']) && $value > $schema['max']))) self::fail('Field is outside allowed numeric range');
        if (isset($schema['max_length']) && mb_strlen($value) > $schema['max_length']) self::fail('Field text is too long');
        if (!empty($schema['options']) && !in_array($value, array_column($schema['options'],'value'), true)) self::fail('Field value is not an allowed option');
        if (($schema['type'] ?? '') === 'link') $this->link($value);
        if (($schema['type'] ?? '') === 'richtext') {
            // A small formatting-only subset; attributes, embedded assets and
            // navigation require dedicated approved fields rather than HTML.
            if (preg_match('/<[^>]*[\s=][^>]*>|<\s*!(?:--|DOCTYPE)/i', $value) || strip_tags($value, '<p><br><b><strong><em><i><u><ul><ol><li><h1><h2><h3><blockquote>') !== $value) self::fail('Unsafe rich text');
        }
        if (($schema['type'] ?? '') === 'image' && $value !== '' && (!is_string($value) || !preg_match('~^(https?://|/)(?!/)~i',$value))) self::fail('Invalid image URL');
        if (isset($schema['list'])) $this->validateList($value,$schema['list']);
        if ($type === 'object' && !empty($schema['default']) && ($schema['type'] ?? '') !== 'link') {
            foreach ($value as $key=>$entry) {
                if (!array_key_exists($key,$schema['default'])) self::fail('Unapproved object field');
                $default=$schema['default'][$key];
                $this->validate($entry,['value_type'=>is_int($default)||is_float($default)?'number':(is_bool($default)?'boolean':(is_array($default)?'array':'string'))]);
            }
        }
    }
    private function link(array $link): void
    {
        if (!$link) return;
        foreach ($this->scope['allowed_links'] ?? [] as $allowed) {
            if (!is_array($allowed)) continue;
            if ($link == $allowed) return;
            if (($link['path'] ?? null) !== ($allowed['path'] ?? null) || ($link['query'] ?? []) != ($allowed['query'] ?? [])) continue;
            $matches = true; foreach ($link as $key=>$value) if (!array_key_exists($key,$allowed) || $allowed[$key] != $value) { $matches=false; break; }
            if ($matches && !empty($link['path'])) return;
        }
        $url = $link['path'] ?? $link['url'] ?? '';
        if (isset($link['path'], $link['url']) && $link['path'] !== $link['url']) self::fail('Conflicting link destinations');
        if (array_diff(array_keys($link), ['path','url','type','name','title'])) self::fail('Unapproved external link fields');
        if (is_string($url) && filter_var($url,FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($url,PHP_URL_SCHEME) ?: ''),['https','http'],true) && !parse_url($url,PHP_URL_USER) && in_array($url,$this->scope['approved_urls'] ?? [],true)) return;
        self::fail('Link is not available or explicitly approved');
    }
    private function validateList(array $rows, array $contract): void
    {
        if (count($rows)<$contract['min_items'] || ($contract['max_items']!==null && count($rows)>$contract['max_items'])) self::fail('List length outside component limits');
        foreach ($rows as $row) {
            if (!is_array($row)) self::fail('List rows must be objects');
            foreach ($row as $key=>$value) {
                if ($key==='id') self::fail('Row IDs are assigned by server');
                if (!isset($contract['fields'][$key])) self::fail('Unapproved list row field');
                $this->validate($value,$contract['fields'][$key]);
            }
        }
    }
    private function editList(array &$widget,array $definition,array $op): void
    {
        $field=$op['field'] ?? 'data';$contract=$definition['lists'][$field]??null;
        if (!$contract) self::fail('Unapproved component list');
        $rows=$widget['content'][$field]??[];$oldCount=count($rows);$action=$op['action']??'';$i=$op['index']??null;
        if ($action==='add') { $row=$op['row']??[];$this->validateList([$row],array_replace($contract,['min_items'=>0]));$row['id']=self::newId();$i=$i??count($rows);if(!is_int($i)||$i<0||$i>count($rows))self::fail('List insertion index invalid');array_splice($rows,$i,0,[$row]); }
        else {
            if (!is_int($i)||!isset($rows[$i])) self::fail('List row index invalid');
            if ($action==='update') foreach ($op['fields']??[] as $key=>$value) {if($key==='id'||!isset($contract['fields'][$key]))self::fail('Unapproved list row field');$this->validate($value,$contract['fields'][$key]);$rows[$i][$key]=$value;}
            elseif($action==='delete')array_splice($rows,$i,1);
            elseif($action==='copy'){ $row=$rows[$i];$row['id']=self::newId();array_splice($rows,$i+1,0,[$row]); }
            elseif($action==='move'){ $to=$op['to_index']??null;if(!is_int($to)||$to<0||$to>=count($rows))self::fail('List move index invalid');$row=$rows[$i];array_splice($rows,$i,1);array_splice($rows,$to,0,[$row]); }
            else self::fail('Unsupported list action');
        }
        if((count($rows)<$contract['min_items']&&count($rows)<$oldCount)||($contract['max_items']!==null&&count($rows)>$contract['max_items']&&count($rows)>$oldCount))self::fail('List length outside component limits');
        $widget['content'][$field]=$rows;
    }
    private function bind(array &$widget,array $definition,array $op): void
    {
        $source=$definition['source'];$key=$op['source_key']??'';$mode=$op['mode']??'source';
        if(!in_array($mode,$source['modes'],true)||($mode!=='manual'&&!in_array($key,$source['allowed_keys'],true))||($mode==='manual'&&$key!==''&&!in_array($key,$source['allowed_keys'],true)))self::fail('Unsupported component data source');
        $params=$op['params']??[];if(!is_array($params))self::fail('Source params must be an object');
        $rules = $key==='' ? [] : ($key==='article' ? ['limit'=>['value_type'=>'number','min'=>1,'max'=>50], 'sort'=>['value_type'=>'string','options'=>[['value'=>'new'],['value'=>'hot']]]] : ($key==='app_entries' ? ['terminal'=>['value_type'=>'string','options'=>[['value'=>'uniapp'],['value'=>'mobile'],['value'=>'pc']]]] : (in_array($key,['user_profile','membership_summary','account_summary'],true)?[]:['limit'=>['value_type'=>'number','min'=>1,'max'=>50]])));
        foreach($params as $name=>$value){$schema=$rules[$name]??null;if(!$schema)self::fail('Unapproved source parameter');$this->validate($value,$schema);}
        $oldKey=$widget['content']['source_key']??'';$widget['content']['source_key']=$key;$widget['content']['data_mode']=$mode;$widget['content']['source_params']=$oldKey===$key?array_replace($widget['content']['source_params']??[],$params):$params;
    }
    private function image(array $widget,array $definition,string $id,array $op): void
    {
        $field=(string)($op['field']??'');$schema=$this->field($definition,$field,$widget);
        if(($schema['type']??'')!=='image')self::fail('Requested field is not an image slot');
        $prompt=trim((string)($op['prompt']??''));if($prompt===''||mb_strlen($prompt)>4000)self::fail('Image prompt length invalid');
        $ratio=$op['ratio']??'1:1';if(!in_array($ratio,['1:1','2:3','3:2','3:4','4:3','9:16','16:9','21:9'],true))self::fail('Image aspect ratio invalid');
        $references=$op['reference_file_ids']??[];if(!is_array($references)||count($references)>10)self::fail('Too many reference files');
        foreach($references as $file)if(!is_int($file)||$file<=0)self::fail('Reference file id invalid');
        $this->images[]=['widget_id'=>$id,'field'=>$field,'prompt'=>$prompt,'ratio'=>$ratio,'reference_file_ids'=>$references,'old_value'=>self::get($widget,$field)];
    }
    private function configurePage(array &$page,array $op): void
    {
        $schema=['title'=>['value_type'=>'string'],'title_type'=>['value_type'=>'number','options'=>[['value'=>0],['value'=>1],['value'=>2]]],'title_img'=>['value_type'=>'string','type'=>'image'],'show_nav'=>['value_type'=>'number','min'=>0,'max'=>1],'nav_bg_color'=>['value_type'=>'string'],'bg_type'=>['value_type'=>'number','min'=>0,'max'=>2],'bg_color'=>['value_type'=>'string'],'bg_image'=>['value_type'=>'string','type'=>'image'],'text_color'=>['value_type'=>'string','options'=>[['value'=>'1'],['value'=>'2']]]];
        if(isset($op['name'])){if(!is_string($op['name'])||trim($op['name'])===''||mb_strlen($op['name'])>100)self::fail('Invalid page name');$page['name']=$op['name'];}
        $meta=$page['meta']??[];$idx=null;foreach($meta as $i=>$entry)if(($entry['name']??'')==='page-meta'){$idx=$i;break;}
        if($idx===null){$meta[]=['name'=>'page-meta','title'=>'页面设置','content'=>['title_type'=>1,'bg_type'=>0],'styles'=>[]];$idx=count($meta)-1;}
        foreach($op['fields']??[] as $key=>$value){$key=preg_replace('/^content\./','',$key);if(!isset($schema[$key]))self::fail('Unapproved page meta field');$this->validate($value,$schema[$key]);$meta[$idx]['content'][$key]=$value;}
        $page['meta']=$meta;
    }
}
