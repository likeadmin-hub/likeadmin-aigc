<?php
// Installation-owner opt-in. Tenant administrators cannot change this setting through an API.
return [
    'source_author_tenant_id' => (int)env('canvas_presets.source_author_tenant_id', 0),
];
