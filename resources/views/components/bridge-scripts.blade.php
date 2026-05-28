@props([
    'enabled' => \Webkul\NativephpRemote\Support\NativeRemote::bridgeEnabled(),
])

@if ($enabled)
    <script>
        window.BarcodeNative = {
            enabled: true,

            vibrate() {
                try {
                    if (window.AndroidBridge?.vibrate) {
                        window.AndroidBridge.vibrate();
                    } else if (window.webkit?.messageHandlers?.haptic) {
                        window.webkit.messageHandlers.haptic.postMessage('');
                    }
                } catch (e) {}
            },

            toast(message, duration = 'short') {
                if (! message) return;

                try {
                    if (window.AndroidBridge?.showToast) {
                        window.AndroidBridge.showToast(message, duration);
                    } else if (window.webkit?.messageHandlers?.toast) {
                        window.webkit.messageHandlers.toast.postMessage({ message, duration });
                    }
                } catch (e) {}
            },
        };

        // Re-hydrate native UI after Livewire SPA navigation
        document.addEventListener('livewire:navigated', function () {
            var el = document.getElementById('barcode-native-ui');
            var json = el ? el.textContent : '';

            if (window.AndroidBridge && typeof window.AndroidBridge.updateNativeUI === 'function') {
                window.AndroidBridge.updateNativeUI(json || '');
            }

            if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.nativeUI) {
                window.webkit.messageHandlers.nativeUI.postMessage(json || '');
            }
        });
    </script>
@else
    <script>
        window.BarcodeNative = {
            enabled: false,
            vibrate() {},
            toast() {},
        };
    </script>
@endif
