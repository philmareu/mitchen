<?php

use Illuminate\Http\Request;

it('believes X-Forwarded-Proto only from a proxy listed in TRUSTED_PROXIES', function () {
    $sessionCookie = fn () => $this->withHeader('X-Forwarded-Proto', 'https')
        ->get(route('filament.admin.auth.login'))
        ->getCookie(config('session.cookie'), decrypt: false);

    try {
        expect($sessionCookie()->isSecure())->toBeFalse();

        config(['trustedproxy.proxies' => '127.0.0.1']);

        expect($sessionCookie()->isSecure())->toBeTrue();
    } finally {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }
});
