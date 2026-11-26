<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | The reverse proxies in front of Mitchen, if there are any. Mitchen believes
    | their X-Forwarded-* headers, which is how it learns the visitor's real
    | address and that the request arrived over HTTPS. Without this, an app
    | behind a proxy that handles HTTPS builds http:// links, tells an MCP
    | client to sign in at an http:// address, and sets its session cookie
    | without the Secure flag.
    |
    | A proxy on the same machine is 127.0.0.1. Separate several addresses or
    | CIDR ranges with commas. "*" trusts whatever connects, which is only
    | safe when nothing but the proxy can reach the app.
    |
    */

    'proxies' => env('TRUSTED_PROXIES') ?: null,

];
