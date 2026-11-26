<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Any MCP client can register itself with Mitchen, under any name it
    | likes. What it can't choose freely is where the cook is sent back to
    | after approving it, which is what keeps a stranger's site from passing
    | itself off as Claude. By default that is Claude's own apps
    | (https://claude.ai) and a client on the cook's own machine such as
    | Claude Code (http://localhost, which covers 127.0.0.1 and any port).
    |
    | To let another client connect, list its address with these in
    | MCP_REDIRECT_DOMAINS, separated by commas, each with its scheme and
    | host: "https://claude.ai,http://localhost,https://chatgpt.com".
    | "*" allows any address.
    |
    */

    'redirect_domains' => array_values(array_filter(array_map(
        trim(...),
        explode(',', (string) env('MCP_REDIRECT_DOMAINS', 'https://claude.ai,http://localhost')),
    ))),

];
