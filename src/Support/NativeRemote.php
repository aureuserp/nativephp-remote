<?php

namespace Webkul\NativephpRemote\Support;

class NativeRemote
{
    /**
     * Check if the current request originates from NativePHP's Jump bridge.
     */
    public static function requestIsJump(): bool
    {
        $jumpHttpPort = (int) (getenv('JUMP_HTTP_PORT') ?: 3000);
        $userAgent = request()?->userAgent() ?? '';
        $isMobileUserAgent = str_contains($userAgent, 'iPhone')
            || str_contains($userAgent, 'iPad')
            || str_contains($userAgent, 'Android');

        return (int) request()?->getPort() === $jumpHttpPort
            && $isMobileUserAgent;
    }

    /**
     * Check if the current request is from a native app (on-device runtime, hosted remote, or Jump).
     */
    public static function requestIsNative(): bool
    {
        return request()?->server('NATIVEPHP_RUNNING') === 'true'
            || request()?->boolean('nativephp') === true
            || request()?->cookie(static::cookieName()) === '1'
            || static::requestIsJump();
    }

    /**
     * Check if the native bridge (vibrate, toast, etc.) is available.
     */
    public static function bridgeEnabled(): bool
    {
        return static::requestIsNative();
    }

    /**
     * Check if native navigation (top bar, side nav, bottom nav) should be used.
     */
    public static function usesNativeNavigation(): bool
    {
        return static::requestIsNative();
    }

    /**
     * Check if the app is running in hosted remote mode (start_url is an absolute URL).
     */
    public static function usesHostedRemoteShell(): bool
    {
        $startUrl = (string) config('nativephp.start_url', '');

        return str_starts_with($startUrl, 'http://') || str_starts_with($startUrl, 'https://');
    }

    /**
     * Generate a URL for native navigation, respecting hosted remote vs on-device mode.
     */
    public static function navigationUrl(string $route, array $parameters = []): string
    {
        if (static::usesHostedRemoteShell()) {
            return route($route, $parameters);
        }

        if (static::requestIsNative()) {
            return route($route, $parameters, false);
        }

        return route($route, $parameters);
    }

    /**
     * Generate a URL that triggers a hash-based action (e.g. #scan-barcode).
     */
    public static function hashActionUrl(string $hash): ?string
    {
        if (static::usesHostedRemoteShell()) {
            return request()?->fullUrl().'#'.$hash;
        }

        return request()?->getRequestUri().'#'.$hash;
    }

    /**
     * Get the cookie name used to persist native shell detection.
     */
    public static function cookieName(): string
    {
        return (string) config('nativephp-remote.cookie_name', 'nativephp_remote_shell');
    }

    /**
     * Get the cookie lifetime in minutes.
     */
    public static function cookieLifetime(): int
    {
        return (int) config('nativephp-remote.cookie_lifetime', 60 * 24 * 30);
    }

    /**
     * Get iOS permission descriptions.
     *
     * @return array<string, string>
     */
    public static function iosPermissions(): array
    {
        return (array) config('nativephp-remote.ios_permissions', []);
    }
}
