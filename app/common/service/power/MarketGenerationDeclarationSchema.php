<?php
namespace app\common\service\power;

use InvalidArgumentException;

/** Converts only explicitly typed platform children; never infers types from examples or prose. */
final class MarketGenerationDeclarationSchema
{
    public static function expand(array $schema): array
    {
        if (isset($schema['type']) || isset($schema['properties']) || isset($schema['oneOf']) || isset($schema['anyOf']) || isset($schema['allOf'])) return self::node($schema);
        foreach ($schema as &$definition) if (is_array($definition)) $definition=self::node($definition);
        unset($definition);return $schema;
    }

    private static function node(array $definition): array
    {
        if (isset($definition['children']) && is_array($definition['children']) && $definition['children']!==[]) {
            $children=$definition['children'];$type=$definition['type'] ?? '';
            if ($type==='array' && array_keys($children)===['item'] && is_array($children['item'])) {
                $item=self::node($children['item']);
                if (isset($definition['items']) && self::node($definition['items'])!==$item) throw new InvalidArgumentException('数组子结构声明冲突');
                $definition['items']=$item;unset($definition['children']);
            } elseif ($type==='object' && !array_is_list($children) && count(array_filter($children,'is_array'))===count($children)) {
                $properties=[];foreach($children as $name=>$child)$properties[$name]=self::node($child);
                if (isset($definition['properties']) && self::expand($definition['properties'])!==$properties) throw new InvalidArgumentException('对象子结构声明冲突');
                $definition['properties']=$properties;unset($definition['children']);
            }
        }
        foreach ((array)($definition['properties'] ?? []) as $key=>$child) if(is_array($child))$definition['properties'][$key]=self::node($child);
        if(is_array($definition['items'] ?? null))$definition['items']=self::node($definition['items']);
        foreach(['oneOf','anyOf','allOf'] as $group)foreach((array)($definition[$group] ?? []) as $i=>$branch)if(is_array($branch))$definition[$group][$i]=self::node($branch);
        return $definition;
    }
}
