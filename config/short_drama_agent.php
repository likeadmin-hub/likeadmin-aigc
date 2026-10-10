<?php
return [
    'engine' => 'deepagents_v1',
    // Private SDK process; install requirements into this isolated environment.
    // Override through server environment, never a browser/model parameter.
    'python' => env('short_drama_agent.python', '/opt/short-drama-agent/bin/python'),
];
