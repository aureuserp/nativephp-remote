<?php

namespace Webkul\NativephpRemote\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Webkul\NativephpRemote\Support\NativeRemote;

class PersistNativeShell
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->boolean('nativephp')) {
            Cookie::queue(cookie(
                name: NativeRemote::cookieName(),
                value: '1',
                minutes: NativeRemote::cookieLifetime(),
                secure: $request->isSecure(),
                sameSite: 'lax',
            ));
        }

        return $response;
    }
}
