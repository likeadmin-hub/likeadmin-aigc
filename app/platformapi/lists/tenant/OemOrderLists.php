<?php
namespace app\platformapi\lists\tenant;
/** Dedicated V2 workbench; historical orders remain in the existing tenant package view. */
class OemOrderLists extends TenantBrandOrderLists
{
    public function queryWhere(): array
    {
        return array_merge(parent::queryWhere(),[['o.flow_version','=',2]]);
    }
}
