# NativePHP Remote

Connect NativePHP mobile apps to a centralized web server instead of running PHP on-device. Build one Laravel app that works as both a web app and a native mobile app.

## How It Works

NativePHP normally bundles your Laravel app and runs it locally on the device. This package patches NativePHP so the mobile WebView connects to your existing web server instead. All requests go to your server — the native shell provides the mobile experience (top bar, side nav, camera, haptics, etc.).

```
┌─────────────────┐       ┌──────────────────┐
│  Mobile Device   │       │   Your Server    │
│                  │       │                  │
│  NativePHP Shell │──────▶│  Laravel App     │
│  (WebView)       │  HTTP │  (same codebase) │
│                  │◀──────│                  │
│  Native Top Bar  │       │  Livewire/Blade  │
│  Native Side Nav │       │  Filament        │
│  Haptics/Toast   │       │                  │
└─────────────────┘       └──────────────────┘
```

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- [NativePHP Mobile](https://nativephp.com) ^3.0

## Installation

```bash
composer require webkul/nativephp-remote
```

The service provider is auto-discovered. To publish the config:

```bash
php artisan vendor:publish --tag=nativephp-remote-config
```

## Setup

### 1. Configure NativePHP Start URL

In your `config/nativephp.php` (or `.env` on the device), set the start URL to your server:

```php
'start_url' => env('NATIVEPHP_START_URL', 'https://your-app.com/mobile?nativephp=1'),
```

The `?nativephp=1` parameter is required on the first request — it sets a cookie so subsequent requests are recognized as native.

### 2. Patch NativePHP

After installing or updating NativePHP, run:

```bash
php artisan nativephp:patch-remote --force
```

This copies patched Kotlin/Swift files that enable:
- Hosted remote URL routing (WebView connects to your server)
- HTTPS enforcement in production
- Camera permission handling
- Smooth WebView scrolling
- Hash-based navigation for native actions
- JS-to-native bridge (vibrate, toast, native UI updates)
- `/_native/` API routing to local PHP runtime

Without `--force`, it applies incremental patches to existing files (useful after NativePHP updates).

### 3. Add Middleware

Add the middleware to routes that should support native mode:

```php
use Webkul\NativephpRemote\Http\Middleware\PersistNativeShell;
use Webkul\NativephpRemote\Http\Middleware\RenderHostedNativeUi;

Route::middleware(['web', PersistNativeShell::class, RenderHostedNativeUi::class])
    ->group(function () {
        // Your routes here
    });
```

- **PersistNativeShell** — Sets a cookie on the first `?nativephp=1` request so the app is recognized as native on subsequent requests.
- **RenderHostedNativeUi** — Sends Edge component data via `x-native-ui` response header and renders it in the DOM for native UI hydration.

### 4. Include Bridge Scripts

Add the bridge scripts component to your layout, before `</body>`:

```blade
<x-nativephp-remote::bridge-scripts />
```

This provides:
- `window.BarcodeNative.vibrate()` — Trigger device haptic feedback
- `window.BarcodeNative.toast(message, duration)` — Show native toast (`'short'` or `'long'`)
- Automatic native UI re-hydration after Livewire SPA navigation

### 5. Add Native UI JSON to Layout

For native top bar, side nav, and bottom nav to work, include the Edge data in your layout:

```blade
@if (\Webkul\NativephpRemote\Support\NativeRemote::usesNativeNavigation())
    {{-- Your native sidebar/header blade components here --}}

    <script id="barcode-native-ui" type="application/json">
        @json(\Native\Mobile\Edge\Edge::all())
    </script>
@endif
```

## PHP Helpers

```php
use Webkul\NativephpRemote\Support\NativeRemote;

// Check if the request is from a native app
NativeRemote::requestIsNative();

// Check if native navigation (top bar, side nav) should be rendered
NativeRemote::usesNativeNavigation();

// Check if the JS bridge (vibrate, toast) is available
NativeRemote::bridgeEnabled();

// Check if running in hosted remote mode
NativeRemote::usesHostedRemoteShell();

// Generate URLs that work in both native and web mode
NativeRemote::navigationUrl('my.route', ['param' => 1]);

// Generate a URL with a hash action (e.g., for triggering a scanner)
NativeRemote::hashActionUrl('scan-barcode');
// Returns: "https://your-app.com/current-page#scan-barcode"
```

## JavaScript Bridge

After including `<x-nativephp-remote::bridge-scripts />`:

```javascript
// Haptic feedback (50ms vibration on Android, medium impact on iOS)
window.BarcodeNative.vibrate();

// Toast message
window.BarcodeNative.toast('Item scanned!');
window.BarcodeNative.toast('Error occurred', 'long');

// Check if native bridge is available
if (window.BarcodeNative.enabled) {
    // Running in native app
}
```

## Dual-Mode Pages

Build pages that work both as web and native:

```blade
@unless (\Webkul\NativephpRemote\Support\NativeRemote::usesNativeNavigation())
    {{-- Web header/sidebar --}}
    <header>...</header>
@endunless

{{-- Main content — shared between web and native --}}
<main>
    {{ $slot }}
</main>
```

## Safe Area Insets

The native shell injects CSS variables for device safe areas (notch, navigation bar):

```css
/* Available CSS variables */
var(--inset-top)
var(--inset-right)
var(--inset-bottom)
var(--inset-left)

/* Example: fixed bottom bar that respects system navigation */
.bottom-bar {
    position: fixed;
    bottom: 0;
    padding-bottom: calc(0.5rem + var(--inset-bottom, 0px));
}
```

## Hash-Based Actions

Trigger native actions via URL hash fragments. Useful for native top bar buttons:

```blade
<native:top-bar-action
    id="scan"
    label="Scan"
    icon="qr-code"
    url="{{ \Webkul\NativephpRemote\Support\NativeRemote::hashActionUrl('scan-barcode') }}"
/>
```

Then listen in JavaScript:

```javascript
window.addEventListener('hashchange', () => {
    if (window.location.hash === '#scan-barcode') {
        // Start scanner
        window.history.replaceState({}, '', window.location.pathname + window.location.search);
    }
});
```

## Configuration

```php
// config/nativephp-remote.php

return [
    // Cookie name for native shell detection
    'cookie_name' => env('NATIVEPHP_REMOTE_COOKIE', 'nativephp_remote_shell'),

    // Cookie lifetime in minutes (default: 30 days)
    'cookie_lifetime' => 60 * 24 * 30,

    // Android permissions added during patching
    'android_permissions' => [
        'android.permission.CAMERA',
        'android.permission.VIBRATE',
    ],

    // iOS permission descriptions
    'ios_permissions' => [
        'NSCameraUsageDescription' => 'This app uses your camera to scan barcodes and QR codes.',
    ],
];
```

## What the Patcher Does

Running `php artisan nativephp:patch-remote --force` applies these changes to NativePHP's native code:

| File | Changes |
|------|---------|
| `AndroidManifest.xml` | Adds CAMERA and VIBRATE permissions |
| `LaravelEnvironment.kt` | Hosted remote host detection, HTTPS normalization, circular dependency fix |
| `MainActivity.kt` | Hosted remote URL loading, hash-aware navigation, hardware-accelerated WebView, JS bridge (vibrate, toast, updateNativeUI) |
| `WebViewManager.kt` | Hosted remote URL routing, camera permission handling, `/_native/` API interception, native UI DOM hydration |
| `NativeTopBar.kt` | Hosted remote external URL detection |
| `NativeSideNav.kt` | Hosted remote external URL detection |
| `ContentView.swift` | Fragment-preserving URL extraction, hash-aware navigation, native UI/haptic/toast handlers |
| `NativePHPApp.swift` | Hosted remote host detection, HTTPS normalization |
| `NativeSideNav.swift` (iOS) | Hosted remote external URL detection |

## License

MIT
