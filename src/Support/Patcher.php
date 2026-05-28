<?php

namespace Webkul\NativephpRemote\Support;

use Illuminate\Filesystem\Filesystem;

class Patcher
{
    public function __construct(private Filesystem $files) {}

    public function apply(bool $force = false): void
    {
        if ($force) {
            $this->copyStubFiles();

            return;
        }

        $this->patchAndroidManifest();

        foreach ($this->androidRoots() as $root) {
            $this->patchAndroidLaravelEnvironment($root);
            $this->patchAndroidMainActivity($root);
            $this->patchAndroidWebViewManager($root);
            $this->patchAndroidNativeTopBar($root);
            $this->patchAndroidNativeSideNav($root);
        }

        foreach ($this->iosRoots() as $root) {
            $this->patchIosNativePhpApp($root);
            $this->patchIosContentView($root);
            $this->patchIosNativeSideNav($root);
        }
    }

    private function copyStubFiles(): void
    {
        foreach ($this->stubTargets() as $stubRelativePath => $targets) {
            $stubPath = $this->stubPath($stubRelativePath);

            if (! $this->files->exists($stubPath)) {
                continue;
            }

            foreach ($targets as $targetPath) {
                $this->ensureDirectoryExists(dirname($targetPath));
                $this->files->copy($stubPath, $targetPath);
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function stubTargets(): array
    {
        return [
            'android/app/src/main/AndroidManifest.xml' => [
                base_path('nativephp/android/app/src/main/AndroidManifest.xml'),
                base_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/AndroidManifest.xml'),
            ],
            'android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt' => [
                base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt'),
                base_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt'),
            ],
            'android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt' => [
                base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt'),
                base_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt'),
            ],
            'android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt' => [
                base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'),
                base_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt'),
            ],
            'android/app/src/main/java/com/nativephp/mobile/ui/NativeSideNav.kt' => [
                base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/ui/NativeSideNav.kt'),
                base_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/NativeSideNav.kt'),
            ],
            'android/app/src/main/java/com/nativephp/mobile/ui/NativeTopBar.kt' => [
                base_path('nativephp/android/app/src/main/java/com/nativephp/mobile/ui/NativeTopBar.kt'),
                base_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile/ui/NativeTopBar.kt'),
            ],
            'ios/NativePHP/ContentView.swift' => [
                base_path('nativephp/ios/NativePHP/ContentView.swift'),
                base_path('vendor/nativephp/mobile/resources/xcode/NativePHP/ContentView.swift'),
            ],
            'ios/NativePHP/NativePHPApp.swift' => [
                base_path('nativephp/ios/NativePHP/NativePHPApp.swift'),
                base_path('vendor/nativephp/mobile/resources/xcode/NativePHP/NativePHPApp.swift'),
            ],
            'ios/NativePHP/NativeUI/NativeSideNav.swift' => [
                base_path('nativephp/ios/NativePHP/NativeUI/NativeSideNav.swift'),
                base_path('vendor/nativephp/mobile/resources/xcode/NativePHP/NativeUI/NativeSideNav.swift'),
            ],
        ];
    }

    private function stubPath(string $relativePath): string
    {
        return dirname(__DIR__, 2).'/stubs/nativephp/'.$relativePath;
    }

    private function ensureDirectoryExists(string $path): void
    {
        if ($this->files->isDirectory($path)) {
            return;
        }

        $this->files->makeDirectory($path, 0755, true);
    }

    private function patchAndroidManifest(): void
    {
        foreach (array_filter([
            base_path('nativephp/android/app/src/main/AndroidManifest.xml'),
            base_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/AndroidManifest.xml'),
        ], fn (string $path): bool => $this->files->exists($path)) as $path) {
            $contents = $this->files->get($path);

            if (! str_contains($contents, 'android.permission.CAMERA')) {
                $contents = str_replace(
                    "    <uses-permission android:name=\"android.permission.ACCESS_NETWORK_STATE\" />\n",
                    "    <uses-permission android:name=\"android.permission.ACCESS_NETWORK_STATE\" />\n    <uses-permission android:name=\"android.permission.CAMERA\" />\n    <uses-permission android:name=\"android.permission.VIBRATE\" />\n\n    <uses-feature\n        android:name=\"android.hardware.camera.any\"\n        android:required=\"false\" />\n",
                    $contents,
                );
            }

            if (! str_contains($contents, 'android.permission.VIBRATE')) {
                $contents = str_replace(
                    "    <uses-permission android:name=\"android.permission.CAMERA\" />\n",
                    "    <uses-permission android:name=\"android.permission.CAMERA\" />\n    <uses-permission android:name=\"android.permission.VIBRATE\" />\n",
                    $contents,
                );
            }

            $this->files->put($path, $contents);
        }
    }

    /**
     * @return array<int, string>
     */
    private function androidRoots(): array
    {
        return array_values(array_filter([
            base_path('nativephp/android/app/src/main/java/com/nativephp/mobile'),
            base_path('vendor/nativephp/mobile/resources/androidstudio/app/src/main/java/com/nativephp/mobile'),
        ], fn (string $root): bool => $this->files->isDirectory($root)));
    }

    /**
     * @return array<int, string>
     */
    private function iosRoots(): array
    {
        return array_values(array_filter([
            base_path('nativephp/ios/NativePHP'),
            base_path('vendor/nativephp/mobile/resources/xcode/NativePHP'),
        ], fn (string $root): bool => $this->files->isDirectory($root)));
    }

    private function patchAndroidLaravelEnvironment(string $root): void
    {
        $path = $root.'/bridge/LaravelEnvironment.kt';

        $contents = $this->files->get($path);

        $contents = str_replace(
            <<<'KOTLIN'
                    if (value.isNotEmpty()) {
                        // Ensure path starts with /
                        if (!value.startsWith("/")) {
                            value = "/$value"
                        }
                        Log.d(TAG, "⚙️ Found start URL in .env: $value")
                        return value
                    }
KOTLIN,
            <<<'KOTLIN'
                    if (value.isNotEmpty()) {
                        if (!isAbsoluteUrl(value) && !value.startsWith("/")) {
                            value = "/$value"
                        }
                        value = normalizeHostedRemoteUrl(context, value)
                        Log.d(TAG, "⚙️ Found start URL in .env: $value")
                        return value
                    }
KOTLIN,
            $contents,
        );

        if (! str_contains($contents, 'fun getHostedRemoteHost(context: Context): String?')) {
            $contents = str_replace(
                <<<'KOTLIN'
        fun getStartURL(context: Context): String {
KOTLIN,
                <<<'KOTLIN'
        private fun isAbsoluteUrl(value: String): Boolean {
            return value.startsWith("http://") || value.startsWith("https://")
        }

        fun getHostedRemoteHost(context: Context): String? {
            return try {
                val startUrl = getRawStartURL(context)

                if (!isAbsoluteUrl(startUrl)) {
                    return null
                }

                URL(startUrl).host
            } catch (e: Exception) {
                Log.e(TAG, "⚠️ Error parsing hosted remote URL", e)
                null
            }
        }

        private fun getRawStartURL(context: Context): String {
            val appStorageDir = context.getDir("storage", Context.MODE_PRIVATE)
            val envFile = File(File(appStorageDir, "laravel"), ".env")

            if (!envFile.exists()) return "/"

            return try {
                val envContent = envFile.readText()
                val match = Regex("""NATIVEPHP_START_URL\s*=\s*([^\r\n]+)""").find(envContent)
                val value = match?.groupValues?.get(1)?.trim()?.trim('"', '\'') ?: ""

                if (value.isEmpty()) "/" else value
            } catch (e: Exception) {
                "/"
            }
        }

        fun getAppEnvironment(context: Context): String {
            val appStorageDir = context.getDir("storage", Context.MODE_PRIVATE)
            val laravelDir = File(appStorageDir, "laravel")
            val envFile = File(laravelDir, ".env")

            if (!envFile.exists()) {
                return ""
            }

            return try {
                val envContent = envFile.readText()
                val pattern = Regex("""APP_ENV\s*=\s*([^\r\n]+)""")
                val match = pattern.find(envContent)

                match?.groupValues?.get(1)
                    ?.trim()
                    ?.trim('"', '\'')
                    ?.lowercase()
                    ?: ""
            } catch (e: Exception) {
                Log.e(TAG, "⚠️ Error reading APP_ENV from .env file", e)
                ""
            }
        }

        fun shouldForceHttpsHostedRemote(context: Context): Boolean {
            return getAppEnvironment(context) == "production"
        }

        fun normalizeHostedRemoteUrl(context: Context, value: String): String {
            if (!isAbsoluteUrl(value) || !shouldForceHttpsHostedRemote(context)) {
                return value
            }

            return try {
                val remoteHost = getHostedRemoteHost(context) ?: return value
                val url = URL(value)

                if (url.protocol.equals("http", ignoreCase = true) && url.host.equals(remoteHost, ignoreCase = true)) {
                    value.replaceFirst("http://", "https://")
                } else {
                    value
                }
            } catch (e: Exception) {
                Log.e(TAG, "⚠️ Error normalizing hosted remote URL", e)
                value
            }
        }

        fun getStartURL(context: Context): String {
KOTLIN,
                $contents,
            );
        }

        $this->files->put($path, $contents);
    }

    private function patchAndroidMainActivity(string $root): void
    {
        $path = $root.'/ui/MainActivity.kt';

        $contents = $this->files->get($path);

        $contents = str_replace(
            <<<'KOTLIN'
            val target = pendingDeepLink ?: LaravelEnvironment.getStartURL(this)
            val fullUrl = "http://127.0.0.1$target"
            Log.d("DeepLink", "🚀 Loading final URL after WebView setup: $fullUrl")
            webView.loadUrl(fullUrl)
KOTLIN,
            <<<'KOTLIN'
            val target = LaravelEnvironment.normalizeHostedRemoteUrl(
                this,
                pendingDeepLink ?: LaravelEnvironment.getStartURL(this)
            )
            val fullUrl = if (target.startsWith("http://") || target.startsWith("https://")) {
                target
            } else {
                "http://127.0.0.1$target"
            }
            Log.d("DeepLink", "🚀 Loading final URL after WebView setup: $fullUrl")
            webView.loadUrl(fullUrl)
KOTLIN,
            $contents,
        );

        if (! str_contains($contents, '4001 -> {')) {
            $contents = str_replace(
                "        when (requestCode) {\n",
                "        when (requestCode) {\n            4001 -> {\n                if (::webViewManager.isInitialized) {\n                    webViewManager.onRequestPermissionsResult(requestCode, permissions, grantResults)\n                }\n            }\n",
                $contents,
            );
        }

        $contents = str_replace(
            <<<'KOTLIN'
        val path = extractPath(url)
        Log.d("Navigation", "🚀 Navigating with Inertia check: $path")

        // Escape the path for JavaScript string (use double quotes to avoid issues with /)
        val escapedPath = path.replace("\\", "\\\\").replace("\"", "\\\"")

        val jsCode = """
            (function() {
                var path = "$escapedPath";
                console.log('[NativePHP] Navigation requested:', path);

                // Check if Inertia router is available
                if (typeof window.router !== 'undefined' && typeof window.router.visit === 'function') {
                    console.log('[NativePHP] Using Inertia router.visit():', path);
                    window.router.visit(path);
                } else {
                    console.log('[NativePHP] Inertia not available, using location.href');
                    window.location.href = path;
                }
            })();
        """.trimIndent()
KOTLIN,
            <<<'KOTLIN'
        val isAbsoluteUrl = url.startsWith("http://") || url.startsWith("https://")
        val navigationTarget = if (isAbsoluteUrl) url else extractPath(url)
        Log.d("Navigation", "🚀 Navigating with Inertia check: $navigationTarget")

        val escapedTarget = navigationTarget
            .replace("\\", "\\\\")
            .replace("\"", "\\\"")

        val jsCode = """
            (function() {
                var target = "$escapedTarget";
                var hashIdx = target.indexOf('#');
                if (hashIdx >= 0) {
                    var targetBase = target.substring(0, hashIdx);
                    var hash = target.substring(hashIdx);
                    var currentBase = window.location.href.split('#')[0];
                    var currentPath = window.location.pathname + window.location.search;
                    if (!targetBase || targetBase === currentBase || targetBase === currentPath) {
                        console.log('[NativePHP] Hash-only navigation:', hash);
                        if (window.location.hash === hash) {
                            history.replaceState(null, '', currentPath);
                        }
                        window.location.hash = hash;
                        return;
                    }
                }

                if (typeof window.router !== 'undefined' && typeof window.router.visit === 'function') {
                    console.log('[NativePHP] Using Inertia router.visit():', target);
                    window.router.visit(target);
                } else {
                    console.log('[NativePHP] Using location.href:', target);
                    window.location.href = target;
                }
            })();
        """.trimIndent()
KOTLIN,
            $contents,
        );

        // Patch extractPath to preserve URL fragments (e.g. #scan-barcode)
        $contents = str_replace(
            <<<'KOTLIN'
                // Parse as full URL and extract path + query
                val parsedUrl = URL(url)
                // URL.getPath() returns empty string for root, not null - handle both cases
                val path = if (parsedUrl.path.isNullOrEmpty()) "/" else parsedUrl.path
                val query = parsedUrl.query
                val result = if (query != null) "$path?$query" else path
KOTLIN,
            <<<'KOTLIN'
                // Parse as full URL and extract path + query + fragment
                val parsedUrl = URL(url)
                // URL.getPath() returns empty string for root, not null - handle both cases
                val path = if (parsedUrl.path.isNullOrEmpty()) "/" else parsedUrl.path
                val query = parsedUrl.query
                val fragment = parsedUrl.ref
                val result = buildString {
                    append(path)
                    if (query != null) append("?$query")
                    if (fragment != null) append("#$fragment")
                }
KOTLIN,
            $contents,
        );

        // Add updateNativeUI bridge method for Livewire SPA navigation
        if (! str_contains($contents, 'fun updateNativeUI(')) {
            $contents = str_replace(
                "        @android.webkit.JavascriptInterface\n        fun openDrawer() {",
                "        @android.webkit.JavascriptInterface\n        fun updateNativeUI(json: String) {\n            Log.d(\"AndroidBridge\", \"📱 updateNativeUI called from JavaScript\")\n            runOnUiThread {\n                if (json.isBlank()) {\n                    NativeUIState.clearAll()\n                } else {\n                    NativeUIState.updateFromJson(json)\n                }\n            }\n        }\n\n        @android.webkit.JavascriptInterface\n        fun openDrawer() {",
                $contents,
            );
        }

        // Add vibrate and showToast bridge methods for JS-to-native haptics/toasts
        if (! str_contains($contents, 'fun vibrate()')) {
            $contents = str_replace(
                "        @android.webkit.JavascriptInterface\n        fun updateNativeUI(",
                "        @android.webkit.JavascriptInterface\n        fun vibrate() {\n            try {\n                val vibrator = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.S) {\n                    (getSystemService(android.os.VibratorManager::class.java))?.defaultVibrator\n                } else {\n                    @Suppress(\"DEPRECATION\")\n                    getSystemService(VIBRATOR_SERVICE) as? android.os.Vibrator\n                }\n                if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.O) {\n                    vibrator?.vibrate(android.os.VibrationEffect.createOneShot(50, android.os.VibrationEffect.DEFAULT_AMPLITUDE))\n                } else {\n                    @Suppress(\"DEPRECATION\")\n                    vibrator?.vibrate(50)\n                }\n            } catch (e: Exception) {\n                Log.e(\"AndroidBridge\", \"Vibrate failed: \${e.message}\")\n            }\n        }\n\n        @android.webkit.JavascriptInterface\n        fun showToast(message: String, duration: String) {\n            runOnUiThread {\n                val length = if (duration == \"long\") android.widget.Toast.LENGTH_LONG else android.widget.Toast.LENGTH_SHORT\n                android.widget.Toast.makeText(this@MainActivity, message, length).show()\n            }\n        }\n\n        @android.webkit.JavascriptInterface\n        fun updateNativeUI(",
                $contents,
            );
        }

        // Remove requestLayout() from WebView update block — it runs on every
        // Compose recomposition and kills scroll performance
        $contents = str_replace(
            <<<'KOTLIN'
                            update = { view ->
                                // Force layout recalculation when Compose size changes
                                // This ensures viewport units (100vh, 100vw) work correctly
                                view.requestLayout()
                            }
KOTLIN,
            '',
            $contents,
        );

        // Enable hardware acceleration and disable overscroll for smooth scrolling
        $contents = str_replace(
            <<<'KOTLIN'
            settings.mediaPlaybackRequiresUserGesture = false
        }
KOTLIN,
            <<<'KOTLIN'
            settings.mediaPlaybackRequiresUserGesture = false
        }

        webView.setLayerType(android.view.View.LAYER_TYPE_HARDWARE, null)
        webView.overScrollMode = android.view.View.OVER_SCROLL_NEVER
KOTLIN,
            $contents,
        );

        $this->files->put($path, $contents);
    }

    private function patchAndroidWebViewManager(string $root): void
    {
        $path = $root.'/network/WebViewManager.kt';

        $contents = $this->files->get($path);

        if (! str_contains($contents, 'import com.nativephp.mobile.bridge.LaravelEnvironment')) {
            $contents = str_replace(
                "import com.acsbendi.requestinspectorwebview.RequestInspectorWebViewClient\n",
                "import com.acsbendi.requestinspectorwebview.RequestInspectorWebViewClient\nimport com.nativephp.mobile.bridge.LaravelEnvironment\n",
                $contents,
            );
        }

        if (! str_contains($contents, 'import org.json.JSONArray')) {
            $contents = str_replace(
                "import org.json.JSONObject\n",
                "import org.json.JSONObject\nimport org.json.JSONArray\n",
                $contents,
            );
        }

        if (! str_contains($contents, 'import android.Manifest')) {
            $contents = str_replace(
                "package com.nativephp.mobile.network\n\n",
                "package com.nativephp.mobile.network\n\nimport android.Manifest\n",
                $contents,
            );
        }

        if (! str_contains($contents, 'import android.content.pm.PackageManager')) {
            $contents = str_replace(
                "import android.content.Intent\n",
                "import android.content.Intent\nimport android.content.pm.PackageManager\n",
                $contents,
            );
        }

        if (! str_contains($contents, 'import androidx.core.app.ActivityCompat')) {
            $contents = str_replace(
                "import android.app.Activity\n",
                "import android.app.Activity\nimport androidx.core.app.ActivityCompat\nimport androidx.core.content.ContextCompat\n",
                $contents,
            );
        }

        if (! str_contains($contents, 'private fun isHostedRemoteUrl(url: String, context: Context): Boolean')) {
            $contents = str_replace(
                <<<'KOTLIN'
class WebViewManager(
    private val context: Context,
    private val webView: WebView,
    private val phpBridge: PHPBridge
) {
KOTLIN,
                <<<'KOTLIN'
class WebViewManager(
    private val context: Context,
    private val webView: WebView,
    private val phpBridge: PHPBridge
) {
    private fun isHostedRemoteUrl(url: String, context: Context): Boolean {
        val remoteHost = LaravelEnvironment.getHostedRemoteHost(context) ?: return false
        val uri = Uri.parse(url)

        return uri.scheme in listOf("http", "https")
            && uri.host?.equals(remoteHost, ignoreCase = true) == true
    }

KOTLIN,
                $contents,
            );
        }

        if (! str_contains($contents, 'private val cameraPermissionRequestCode = 4001')) {
            $contents = str_replace(
                "    private val TAG = \"PHPMonitor\"\n",
                "    private val TAG = \"PHPMonitor\"\n    private val cameraPermissionRequestCode = 4001\n",
                $contents,
            );
        }

        if (! str_contains($contents, 'private var pendingMediaPermissionRequest: PermissionRequest? = null')) {
            $contents = str_replace(
                "    private var customViewCallback: WebChromeClient.CustomViewCallback? = null\n",
                "    private var customViewCallback: WebChromeClient.CustomViewCallback? = null\n    private var pendingMediaPermissionRequest: PermissionRequest? = null\n",
                $contents,
            );
        }

        $contents = str_replace(
            <<<'KOTLIN'
                if ((url.startsWith("http://") || url.startsWith("https://")) &&
                    !url.contains("127.0.0.1") &&
                    !url.contains("localhost") &&
                    request.isForMainFrame
                ) {
                    // This is a navigation request to an external site - open in browser
                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
                    view.context.startActivity(intent)
                    return true
                }
KOTLIN,
            <<<'KOTLIN'
                if ((url.startsWith("http://") || url.startsWith("https://")) &&
                    !url.contains("127.0.0.1") &&
                    !url.contains("localhost") &&
                    request.isForMainFrame
                ) {
                    if (isHostedRemoteUrl(url, view.context)) {
                        if (url.startsWith("http://") && LaravelEnvironment.shouldForceHttpsHostedRemote(view.context)) {
                            val correctedUrl = LaravelEnvironment.normalizeHostedRemoteUrl(view.context, url)

                            if (correctedUrl != url) {
                                Log.d(TAG, "🔒 Upgrading hosted remote navigation to HTTPS: $correctedUrl")
                                view.loadUrl(correctedUrl)
                                return true
                            }
                        }

                        return false
                    }

                    // This is a navigation request to an external site - open in browser
                    val intent = Intent(Intent.ACTION_VIEW, Uri.parse(url))
                    view.context.startActivity(intent)
                    return true
                }
KOTLIN,
            $contents,
        );

        if (! str_contains($contents, 'override fun onPermissionRequest(request: PermissionRequest)')) {
            $contents = str_replace(
                "                return true\n            }\n",
                "                return true\n            }\n\n            override fun onPermissionRequest(request: PermissionRequest) {\n                val activity = context as? Activity\n\n                if (activity == null) {\n                    request.deny()\n                    return\n                }\n\n                val requiresVideoCapture = request.resources.contains(PermissionRequest.RESOURCE_VIDEO_CAPTURE)\n\n                if (!requiresVideoCapture) {\n                    request.grant(request.resources)\n                    return\n                }\n\n                if (ContextCompat.checkSelfPermission(activity, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED) {\n                    request.grant(request.resources)\n                    return\n                }\n\n                pendingMediaPermissionRequest = request\n                ActivityCompat.requestPermissions(\n                    activity,\n                    arrayOf(Manifest.permission.CAMERA),\n                    cameraPermissionRequestCode\n                )\n            }\n",
                $contents,
            );
        }

        if (! str_contains($contents, 'private fun hydrateHostedNativeUi(view: WebView)')) {
            $contents = str_replace(
                <<<'KOTLIN'
                // Inject JavaScript to capture form submissions and AJAX requests
                injectJavaScript(view)
            }
KOTLIN,
                <<<'KOTLIN'
                hydrateHostedNativeUi(view)

                // Inject JavaScript to capture form submissions and AJAX requests
                injectJavaScript(view)
            }

            private fun hydrateHostedNativeUi(view: WebView) {
                view.evaluateJavascript(
                    """
                    (() => {
                        const nativeUiNode = document.getElementById('barcode-native-ui');

                        return nativeUiNode ? nativeUiNode.textContent : null;
                    })();
                    """.trimIndent()
                ) { result ->
                    val nativeUiJson = decodeJavascriptString(result)

                    if (nativeUiJson.isNullOrBlank()) {
                        Log.d(TAG, "ℹ️ No hosted native UI payload found in DOM")
                        NativeUIState.clearAll()
                        return@evaluateJavascript
                    }

                    Log.d(TAG, "✅ Hosted native UI payload found in DOM")
                    NativeUIState.updateFromJson(nativeUiJson)
                }
            }

            private fun decodeJavascriptString(result: String?): String? {
                if (result == null || result == "null") {
                    return null
                }

                return try {
                    JSONArray("[$result]").getString(0)
                } catch (_: Exception) {
                    null
                }
            }
KOTLIN,
                $contents,
            );
        }

        if (! str_contains($contents, 'CookieManager.getInstance().flush()')) {
            $contents = str_replace(
                "                Log.d(TAG, \"✅ Page finished loading: \$url\")\n",
                "                Log.d(TAG, \"✅ Page finished loading: \$url\")\n\n                CookieManager.getInstance().flush()\n",
                $contents,
            );
        }

        if (! str_contains($contents, 'fun onRequestPermissionsResult(')) {
            $contents = str_replace(
                "\n    private fun injectJavaScript(view: WebView) {\n",
                "\n    fun onRequestPermissionsResult(\n        requestCode: Int,\n        permissions: Array<out String>,\n        grantResults: IntArray\n    ) {\n        if (requestCode != cameraPermissionRequestCode) {\n            return\n        }\n\n        val request = pendingMediaPermissionRequest ?: return\n        pendingMediaPermissionRequest = null\n\n        val granted = grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED\n\n        if (granted) {\n            request.grant(request.resources)\n        } else {\n            request.deny()\n        }\n    }\n\n    private fun injectJavaScript(view: WebView) {\n",
                $contents,
            );
        }

        // Route /_native/ API calls from hosted remote to local PHP runtime
        if (! str_contains($contents, 'Routing native API call to local runtime')) {
            $contents = str_replace(
                <<<'KOTLIN'
                if (!url.contains("127.0.0.1") && !url.contains("localhost")) {
                    // This is an external resource - let the WebView handle it directly
                    Log.d(TAG, "📡 External resource - passing to system: $url")
                    return null // Returning null lets the WebView load it normally
                }
KOTLIN,
                <<<'KOTLIN'
                if (!url.contains("127.0.0.1") && !url.contains("localhost")) {
                    // Intercept /_native/ API calls from hosted remote and route to local PHP runtime
                    if (isHostedRemoteUrl(url, view.context)) {
                        val path = Uri.parse(url).path
                        if (path != null && path.startsWith("/_native/")) {
                            val localUrl = "http://127.0.0.1$path" +
                                (Uri.parse(url).encodedQuery?.let { "?$it" } ?: "")
                            Log.d(TAG, "🔀 Routing native API call to local runtime: $localUrl")

                            val localUri = Uri.parse(localUrl)
                            val localRequest = object : WebResourceRequest {
                                override fun getUrl(): Uri = localUri
                                override fun isForMainFrame(): Boolean = request.isForMainFrame
                                override fun isRedirect(): Boolean = request.isRedirect
                                override fun hasGesture(): Boolean = request.hasGesture()
                                override fun getMethod(): String = request.method
                                override fun getRequestHeaders(): Map<String, String> = request.requestHeaders
                            }

                            val postData = if (request.method.equals("POST", ignoreCase = true)) {
                                val reqId = request.requestHeaders?.get("X-NativePHP-Req-Id")
                                if (reqId != null) {
                                    reqId.split(",").map { it.trim() }
                                        .firstNotNullOfOrNull { id ->
                                            if (id.isNotEmpty()) phpBridge.consumePostData(id) else null
                                        }
                                } else {
                                    phpBridge.consumePostData(url) ?: phpBridge.consumePostData(path)
                                }
                            } else null

                            return phpHandler.handlePHPRequest(localRequest, postData)
                        }
                    }

                    // This is an external resource - let the WebView handle it directly
                    Log.d(TAG, "📡 External resource - passing to system: $url")
                    return null // Returning null lets the WebView load it normally
                }
KOTLIN,
                $contents,
            );
        }

        $this->files->put($path, $contents);
    }

    private function patchAndroidNativeTopBar(string $root): void
    {
        $path = $root.'/ui/NativeTopBar.kt';

        if (! $this->files->exists($path)) {
            return;
        }

        $contents = $this->files->get($path);

        if (! str_contains($contents, 'private fun hostedRemoteHost(context: android.content.Context): String?')) {
            $contents = str_replace(
                <<<'KOTLIN'
/**
 * Check if a URL is external (not a relative path or localhost)
 */
private fun isExternalUrl(url: String): Boolean {
    return (url.startsWith("http://") || url.startsWith("https://"))
            && !url.contains("127.0.0.1")
            && !url.contains("localhost")
}
KOTLIN,
                <<<'KOTLIN'
private fun hostedRemoteHost(context: android.content.Context): String? {
    return LaravelEnvironment.getHostedRemoteHost(context)
}

/**
 * Check if a URL is external (not a relative path or localhost)
 */
private fun isExternalUrl(url: String, context: android.content.Context): Boolean {
    if (!(url.startsWith("http://") || url.startsWith("https://"))) {
        return false
    }

    if (url.contains("127.0.0.1") || url.contains("localhost")) {
        return false
    }

    val host = Uri.parse(url).host ?: return true
    val remoteHost = hostedRemoteHost(context) ?: return true

    return !host.equals(remoteHost, ignoreCase = true)
}
KOTLIN,
                $contents,
            );
        }

        $contents = str_replace('if (isExternalUrl(url)) {', 'if (isExternalUrl(url, context)) {', $contents);

        $this->files->put($path, $contents);
    }

    private function patchAndroidNativeSideNav(string $root): void
    {
        $path = $root.'/ui/NativeSideNav.kt';

        if (! $this->files->exists($path)) {
            return;
        }

        $contents = $this->files->get($path);

        if (! str_contains($contents, 'private fun hostedRemoteHost(context: android.content.Context): String?')) {
            $contents = str_replace(
                <<<'KOTLIN'
/**
 * Check if a URL is external (not a relative path or localhost)
 */
private fun isExternalUrl(url: String): Boolean {
    return (url.startsWith("http://") || url.startsWith("https://"))
            && !url.contains("127.0.0.1")
            && !url.contains("localhost")
}
KOTLIN,
                <<<'KOTLIN'
private fun hostedRemoteHost(context: android.content.Context): String? {
    return LaravelEnvironment.getHostedRemoteHost(context)
}

/**
 * Check if a URL is external (not a relative path or localhost)
 */
private fun isExternalUrl(url: String, context: android.content.Context): Boolean {
    if (!(url.startsWith("http://") || url.startsWith("https://"))) {
        return false
    }

    if (url.contains("127.0.0.1") || url.contains("localhost")) {
        return false
    }

    val host = Uri.parse(url).host ?: return true
    val remoteHost = hostedRemoteHost(context) ?: return true

    return !host.equals(remoteHost, ignoreCase = true)
}
KOTLIN,
                $contents,
            );
        }

        $contents = str_replace('val shouldOpenExternal = item.openInBrowser == true || isExternalUrl(item.url)', 'val shouldOpenExternal = item.openInBrowser == true || isExternalUrl(item.url, context)', $contents);

        $this->files->put($path, $contents);
    }

    private function patchIosNativePhpApp(string $root): void
    {
        $path = $root.'/NativePHPApp.swift';

        $contents = $this->files->get($path);

        $contents = str_replace(
            <<<'SWIFT'
            if !value.isEmpty {
                // Ensure path starts with /
                if !value.hasPrefix("/") {
                    value = "/" + value
                }
                DebugLogger.shared.log("⚙️ Found start URL in .env: \(value)")
                return value
SWIFT,
            <<<'SWIFT'
            if !value.isEmpty {
                if !isAbsoluteUrl(value) && !value.hasPrefix("/") {
                    value = "/" + value
                }
                value = normalizeHostedRemoteUrl(value)
                DebugLogger.shared.log("⚙️ Found start URL in .env: \(value)")
                return value
SWIFT,
            $contents,
        );

        if (! str_contains($contents, 'static func getHostedRemoteHost() -> String?')) {
            $contents = str_replace(
                <<<'SWIFT'
    /// Read the NATIVEPHP_START_URL from the .env file
    static func getStartURL() -> String {
SWIFT,
                <<<'SWIFT'
    static func isAbsoluteUrl(_ value: String) -> Bool {
        value.hasPrefix("http://") || value.hasPrefix("https://")
    }

    static func getHostedRemoteHost() -> String? {
        let startUrl = getRawStartURL()

        guard isAbsoluteUrl(startUrl),
              let url = URL(string: startUrl) else {
            return nil
        }

        return url.host
    }

    private static func getRawStartURL() -> String {
        let appPath = AppUpdateManager.shared.getAppPath()
        let envPath = URL(fileURLWithPath: appPath).appendingPathComponent(".env")

        guard FileManager.default.fileExists(atPath: envPath.path),
              let envContent = try? String(contentsOf: envPath, encoding: .utf8) else {
            return "/"
        }

        let pattern = #"NATIVEPHP_START_URL\s*=\s*([^\r\n]+)"#

        guard let regex = try? NSRegularExpression(pattern: pattern),
              let match = regex.firstMatch(in: envContent, range: NSRange(envContent.startIndex..., in: envContent)),
              let valueRange = Range(match.range(at: 1), in: envContent) else {
            return "/"
        }

        let value = String(envContent[valueRange])
            .trimmingCharacters(in: .whitespaces)
            .trimmingCharacters(in: CharacterSet(charactersIn: "\"'"))

        return value.isEmpty ? "/" : value
    }

    static func getAppEnvironment() -> String {
        let appPath = AppUpdateManager.shared.getAppPath()
        let envPath = URL(fileURLWithPath: appPath).appendingPathComponent(".env")

        guard FileManager.default.fileExists(atPath: envPath.path),
              let envContent = try? String(contentsOf: envPath, encoding: .utf8) else {
            return ""
        }

        let pattern = #"APP_ENV\s*=\s*([^\r\n]+)"#

        guard let regex = try? NSRegularExpression(pattern: pattern),
              let match = regex.firstMatch(in: envContent, range: NSRange(envContent.startIndex..., in: envContent)),
              let valueRange = Range(match.range(at: 1), in: envContent) else {
            return ""
        }

        return String(envContent[valueRange])
            .trimmingCharacters(in: .whitespaces)
            .trimmingCharacters(in: CharacterSet(charactersIn: "\"'"))
            .lowercased()
    }

    static func shouldForceHttpsHostedRemote() -> Bool {
        getAppEnvironment() == "production"
    }

    static func normalizeHostedRemoteUrl(_ value: String) -> String {
        guard isAbsoluteUrl(value),
              shouldForceHttpsHostedRemote(),
              let remoteHost = getHostedRemoteHost(),
              var components = URLComponents(string: value),
              components.host?.caseInsensitiveCompare(remoteHost) == .orderedSame,
              components.scheme?.lowercased() == "http" else {
            return value
        }

        components.scheme = "https"

        return components.url?.absoluteString ?? value
    }

    /// Read the NATIVEPHP_START_URL from the .env file
    static func getStartURL() -> String {
SWIFT,
                $contents,
            );
        }

        $this->files->put($path, $contents);
    }

    private function patchIosContentView(string $root): void
    {
        $path = $root.'/ContentView.swift';

        $contents = $this->files->get($path);

        $contents = str_replace(
            <<<'SWIFT'
    private func isExternalUrl(_ url: String) -> Bool {
        return (url.hasPrefix("http://") || url.hasPrefix("https://"))
            && !url.contains("127.0.0.1")
            && !url.contains("localhost")
    }
SWIFT,
            <<<'SWIFT'
    private func isExternalUrl(_ url: String) -> Bool {
        guard (url.hasPrefix("http://") || url.hasPrefix("https://")) else {
            return false
        }

        if url.contains("127.0.0.1") || url.contains("localhost") {
            return false
        }

        guard let host = URL(string: url)?.host,
              let remoteHost = NativePHPApp.getHostedRemoteHost() else {
            return true
        }

        return host.caseInsensitiveCompare(remoteHost) != .orderedSame
    }
SWIFT,
            $contents,
        );

        $contents = str_replace(
            <<<'SWIFT'
                let startPath = NativePHPApp.getStartURL()
                let startPage = URL(string: "php://127.0.0.1\(startPath)")
                webView.load(URLRequest(url: startPage ?? fallbackURL))
SWIFT,
            <<<'SWIFT'
                let startPath = NativePHPApp.getStartURL()

                if NativePHPApp.isAbsoluteUrl(startPath),
                   let startPage = URL(string: startPath) {
                    webView.load(URLRequest(url: startPage))
                } else {
                    let startPage = URL(string: "php://127.0.0.1\(startPath)")
                    webView.load(URLRequest(url: startPage ?? fallbackURL))
                }
SWIFT,
            $contents,
        );

        $contents = str_replace(
            <<<'SWIFT'
            if ["http", "https", "tel", "mailto", "sms", "facetime", "facetime-audio"].contains(scheme) {
                UIApplication.shared.open(url)
                decisionHandler(.cancel)
            } else {
                decisionHandler(.allow)
            }
SWIFT,
            <<<'SWIFT'
            if ["tel", "mailto", "sms", "facetime", "facetime-audio"].contains(scheme) {
                UIApplication.shared.open(url)
                decisionHandler(.cancel)
                return
            }

            if ["http", "https"].contains(scheme) {
                if let remoteHost = NativePHPApp.getHostedRemoteHost(),
                   url.host?.caseInsensitiveCompare(remoteHost) == .orderedSame {
                    decisionHandler(.allow)
                    return
                }

                UIApplication.shared.open(url)
                decisionHandler(.cancel)
            } else {
                decisionHandler(.allow)
            }
SWIFT,
            $contents,
        );

        $contents = str_replace(
            <<<'SWIFT'
            if let urlString = notification.userInfo?["url"] as? String {
                if let url = URL(string: urlString) {
SWIFT,
            <<<'SWIFT'
            if let urlString = notification.userInfo?["url"] as? String {
                let normalizedUrlString = NativePHPApp.normalizeHostedRemoteUrl(urlString)

                if let url = URL(string: normalizedUrlString) {
SWIFT,
            $contents,
        );

        // Patch extractPath to preserve URL fragments (e.g. #scan-barcode)
        $contents = str_replace(
            <<<'SWIFT'
            // Parse as full URL and extract path + query
            if let parsedUrl = URL(string: url) {
                let path = parsedUrl.path.isEmpty ? "/" : parsedUrl.path
                let query = parsedUrl.query
                let result = query != nil ? "\(path)?\(query!)" : path

                return result
            }
SWIFT,
            <<<'SWIFT'
            // Parse as full URL and extract path + query + fragment
            if let parsedUrl = URL(string: url) {
                let path = parsedUrl.path.isEmpty ? "/" : parsedUrl.path
                let query = parsedUrl.query
                let fragment = parsedUrl.fragment
                var result = query != nil ? "\(path)?\(query!)" : path
                if let fragment = fragment {
                    result += "#\(fragment)"
                }

                return result
            }
SWIFT,
            $contents,
        );

        // Patch navigateWithInertia to handle hash-only navigation
        $contents = str_replace(
            <<<'SWIFT'
            let js = """
            (function() {
                var path = "\(escapedPath)";
                console.log('[NativePHP] Navigation requested:', path);

                // Check if Inertia router is available
                if (typeof window.router !== 'undefined' && typeof window.router.visit === 'function') {
                    console.log('[NativePHP] Using Inertia router.visit():', path);
                    window.router.visit(path);
                } else {
                    console.log('[NativePHP] Inertia not available, using location.href');
                    window.location.href = path;
                }
            })();
            """
SWIFT,
            <<<'SWIFT'
            let js = """
            (function() {
                var target = "\(escapedPath)";
                var hashIdx = target.indexOf('#');
                if (hashIdx >= 0) {
                    var targetBase = target.substring(0, hashIdx);
                    var hash = target.substring(hashIdx);
                    var currentBase = window.location.href.split('#')[0];
                    var currentPath = window.location.pathname + window.location.search;
                    if (!targetBase || targetBase === currentBase || targetBase === currentPath) {
                        console.log('[NativePHP] Hash-only navigation:', hash);
                        if (window.location.hash === hash) {
                            history.replaceState(null, '', currentPath);
                        }
                        window.location.hash = hash;
                        return;
                    }
                }

                if (typeof window.router !== 'undefined' && typeof window.router.visit === 'function') {
                    console.log('[NativePHP] Using Inertia router.visit():', target);
                    window.router.visit(target);
                } else {
                    console.log('[NativePHP] Using location.href:', target);
                    window.location.href = target;
                }
            })();
            """
SWIFT,
            $contents,
        );

        $this->files->put($path, $contents);
    }

    private function patchIosNativeSideNav(string $root): void
    {
        $path = $root.'/NativeUI/NativeSideNav.swift';

        if (! $this->files->exists($path)) {
            return;
        }

        $contents = $this->files->get($path);

        $contents = str_replace(
            <<<'SWIFT'
    private func isExternalUrl(_ url: String) -> Bool {
        return (url.hasPrefix("http://") || url.hasPrefix("https://"))
            && !url.contains("127.0.0.1")
            && !url.contains("localhost")
    }
SWIFT,
            <<<'SWIFT'
    private func isExternalUrl(_ url: String) -> Bool {
        guard (url.hasPrefix("http://") || url.hasPrefix("https://")) else {
            return false
        }

        if url.contains("127.0.0.1") || url.contains("localhost") {
            return false
        }

        guard let host = URL(string: url)?.host,
              let remoteHost = NativePHPApp.getHostedRemoteHost() else {
            return true
        }

        return host.caseInsensitiveCompare(remoteHost) != .orderedSame
    }
SWIFT,
            $contents,
        );

        $this->files->put($path, $contents);
    }
}
