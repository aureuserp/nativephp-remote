import SwiftUI
import WebKit

extension NSNotification.Name {
    public static let reloadWebViewNotification = NSNotification.Name("ReloadWebViewNotification")
    public static let redirectToURLNotification = NSNotification.Name("RedirectToURLNotification")
    public static let navigateWithInertiaNotification = NSNotification.Name("NavigateWithInertiaNotification")
}

struct ContentView: View {
    @State private var phpOutput = ""
    @StateObject private var uiState = NativeUIState.shared

    var body: some View {
        NativeSideNavigation(onNavigate: handleNavigation) {
            WebViewLayoutContainer(onTabSelected: handleNavigation)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .safeAreaInset(edge: .top, spacing: 0) {
                    if uiState.hasTopBar() {
                        NativeTopBar(onNavigate: handleNavigation)
                    }
                }
        }
    }

    /// Handle navigation from any UI component
    /// Uses Inertia router if available for SPA-like navigation, falls back to full page load
    private func handleNavigation(_ url: String) {
        // Check if this is an external HTTP/HTTPS URL
        if isExternalUrl(url) {
            // Open external URLs in the default browser
            if let externalUrl = URL(string: url) {
                UIApplication.shared.open(externalUrl)
            }
            return
        }

        // Handle internal navigation using Inertia if available
        let path = extractPath(url)

        NotificationCenter.default.post(
            name: .navigateWithInertiaNotification,
            object: nil,
            userInfo: ["path": path]
        )
    }

    /// Check if URL is external (absolute HTTP/HTTPS not pointing to localhost)
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


    /// Extract path and query from URL, handling both full URLs and relative paths
    private func extractPath(_ url: String) -> String {
        if url.hasPrefix("php://") {
            // Extract just the path from php://127.0.0.1/path
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
        }

        if url.hasPrefix("http://") || url.hasPrefix("https://") {
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
        } else if url.hasPrefix("/") {
            return url
        } else {
            let result = "/\(url)"

            return result
        }

        // Fallback
        let fallback = url.hasPrefix("/") ? url : "/\(url)"

        return fallback
    }
}

/// Shared WKWebView instance holder to persist across view updates
class SharedWebView: ObservableObject {
    static let shared = SharedWebView()
    weak var webView: WKWebView?
    var coordinator: WebView.Coordinator?
}

/// Container that wraps a single WebView instance with appropriate layout based on UI state
struct WebViewLayoutContainer: View {
    @ObservedObject var uiState = NativeUIState.shared
    @Environment(\.horizontalSizeClass) var horizontalSizeClass
    let onTabSelected: (String) -> Void

    var body: some View {
        if uiState.hasBottomNav() {
            if #available(iOS 26.0, *) {
                // iOS 26+: WebView extends behind tab bar for Liquid Glass effect
                GeometryReader { geometry in
                    ZStack(alignment: .bottom) {
                        // Single WebView instance - extends to full screen
                        WebView(shared: SharedWebView.shared, horizontalSizeClass: horizontalSizeClass)
                            .frame(maxWidth: .infinity, maxHeight: .infinity)
                            .ignoresSafeArea()
                            // Add bottom padding so content isn't hidden behind tab bar
                            .safeAreaInset(edge: .bottom) {
                                Color.clear
                                    .frame(height: 49 + geometry.safeAreaInsets.bottom)
                            }

                        // Bottom navigation overlays at bottom
                        NativeBottomNavigation(onTabSelected: onTabSelected)
                    }
                    .ignoresSafeArea()
                }
            } else {
                // iOS 18 and below: WebView stops at tab bar
                ZStack(alignment: .bottom) {
                    // Single WebView instance - fills available space
                    WebView(shared: SharedWebView.shared, horizontalSizeClass: horizontalSizeClass)
                        .frame(maxWidth: .infinity, maxHeight: .infinity)
                        .ignoresSafeArea(.all, edges: uiState.hasTopBar() ? .horizontal : .all)

                    // Bottom navigation at bottom
                    NativeBottomNavigation(onTabSelected: onTabSelected)
                }
            }
        } else {
            // No bottom nav - WebView fills entire screen
            WebView(shared: SharedWebView.shared, horizontalSizeClass: horizontalSizeClass)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .ignoresSafeArea(.all, edges: uiState.hasTopBar() ? [.horizontal, .bottom] : .all)
        }
    }
}

struct WebView: UIViewRepresentable {
    static let dataStore = WKWebsiteDataStore.nonPersistent()
    let shared: SharedWebView
    let horizontalSizeClass: UserInterfaceSizeClass?

    func makeCoordinator() -> Coordinator {
        // Reuse existing coordinator if available to maintain LaravelBridge connection
        if let existingCoordinator = shared.coordinator {
            print("♻️ Reusing existing Coordinator")
            return existingCoordinator
        }

        print("🆕 Creating new Coordinator")
        let coordinator = Coordinator()
        shared.coordinator = coordinator
        return coordinator
    }

    static func dismantleUIView(_ uiView: WKWebView, coordinator: Coordinator) {
        // Don't remove observers or clear LaravelBridge when reusing the WebView
        // The shared instance will persist and we'll re-register in makeUIView if needed
        print("⚠️ dismantleUIView called - skipping observer removal for reused WebView")
    }

    class Coordinator: NSObject, WKNavigationDelegate, WKUIDelegate {
        let logger = ConsoleLogger()
        var webView: WKWebView?
        var hasCompletedInitialLoad = false

        func webView(_ webView: WKWebView,
                     decidePolicyFor navigationAction: WKNavigationAction,
                     decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            guard let url = navigationAction.request.url else {
                decisionHandler(.allow)
                return
            }

            let scheme = url.scheme?.lowercased() ?? ""

            // Rewrite http(s)://127.0.0.1 to php:// scheme — PHP/Symfony only understands
            // http/https so redirect()->intended() and $request->fullUrl() will always
            // produce http:// URLs for the local server. Route through the scheme handler's
            // redirect path which handles cookie injection from WKHTTPCookieStore.
            if (scheme == "http" || scheme == "https"),
               url.host == "127.0.0.1" {
                var components = URLComponents(url: url, resolvingAgainstBaseURL: false)
                components?.scheme = "php"
                if let phpURL = components?.url {
                    NotificationCenter.default.post(
                        name: .redirectToURLNotification,
                        object: nil,
                        userInfo: ["url": phpURL.absoluteString]
                    )
                }
                decisionHandler(.cancel)
                return
            }

            // Open external URLs and system schemes with the system handler
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
        }

        func webView(_ webView: WKWebView, didCommit navigation: WKNavigation!) {
            // Inject safe area insets IMMEDIATELY when navigation commits (before rendering)
            // This is the iOS equivalent of Android's onPageStarted
            injectSafeAreaInsets(webView)
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
            // On first load, dismiss the splash screen
            if !hasCompletedInitialLoad {
                hasCompletedInitialLoad = true
                DispatchQueue.main.async {
                    AppState.shared.markInitialized()
                }
            }

            // Fade in WebView smoothly once initial page load is complete
            DispatchQueue.main.async {
                UIView.animate(withDuration: 0.2) {
                    webView.alpha = 1.0
                }
            }

            // Re-inject safe area insets to ensure they're set (like Android does)
            injectSafeAreaInsets(webView)
        }

        @available(iOS 15.0, *)
        func webView(_ webView: WKWebView,
                     requestMediaCapturePermissionFor origin: WKSecurityOrigin,
                     initiatedByFrame frame: WKFrameInfo,
                     type: WKMediaCaptureType,
                     decisionHandler: @escaping (WKPermissionDecision) -> Void) {
            decisionHandler(.grant)
        }

        private func injectSafeAreaInsets(_ webView: WKWebView) {
            // Get insets from window scene (more reliable than webView.window which can be nil)
            let windowScene = UIApplication.shared.connectedScenes
                .compactMap { $0 as? UIWindowScene }
                .first

            let insets = windowScene?.windows.first?.safeAreaInsets ?? webView.window?.safeAreaInsets ?? .zero

            // Also get color scheme for CSS variable
            let isDarkMode = windowScene?.windows.first?.traitCollection.userInterfaceStyle == .dark
            let colorScheme = isDarkMode ? "dark" : "light"

            let js = """
            (function() {
                // Set CSS variables directly on documentElement for immediate availability
                if (document.documentElement) {
                    document.documentElement.style.setProperty('--inset-top', '\(insets.top)px');
                    document.documentElement.style.setProperty('--inset-right', '\(insets.right)px');
                    document.documentElement.style.setProperty('--inset-bottom', '\(insets.bottom)px');
                    document.documentElement.style.setProperty('--inset-left', '\(insets.left)px');
                    document.documentElement.style.setProperty('--native-color-scheme', '\(colorScheme)');
                }
            })();
            """

            webView.evaluateJavaScript(js, completionHandler: nil)
        }

        @MainActor
        func notifyLaravel(
            event: String,
            payload: [String: Any]
        ) {
            let event: String = {
                let data = try! JSONSerialization.data(withJSONObject: [event])
                var literal = String(data: data, encoding: .utf8)!
                literal.removeFirst()
                literal.removeLast()
                return literal
            }()

            // 1. Inject JS event into the current web page
            if let jsonData = try? JSONSerialization.data(withJSONObject: payload, options: []),
               let jsonString = String(data: jsonData, encoding: .utf8) {

                let js = """
                (function() {
                    const event = new CustomEvent(
                        "native-event",
                        {
                            detail: {
                                event: \(event),
                                payload: \(jsonString),
                            },
                        }
                    );
                    document.dispatchEvent(event);

                    fetch('/_native/api/events', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            event: \(event),
                            payload: \(jsonString),
                        })
                    }).then(response => response.json())
                      .then(data => console.log("API Event Dispatch Success:", JSON.stringify(data, null, 2)))
                      .catch(error => console.error("API Event Dispatch Error:", error));
                })();
                """

                self.webView?.evaluateJavaScript(js) { result, error in
                    if let error = error {
                        print("JavaScript injection error injecting event '\(event)': \(error)")
                    } else {
                        print("JavaScript event '\(event)' dispatched.")
                    }
                }

                // FUTURE: Send a request to Laravel backend directly
//                let request = RequestData(
//                    method: "POST",
//                    uri: "php://127.0.0.1/_native/api/events",
//                    data: jsonString,
//                    headers: [
//                        "Content-Type": "application/json"
//                    ])
//
//                _ = NativePHPApp.laravel(request: request)

            }
        }

        @objc func reloadWebView() {
            // Views are already cleared during persistent runtime reboot — just reload
            self.webView?.reload()
        }

        // Swipe gestures disabled for back/forward navigation
        // @objc func handleSwipeLeft(_ gesture: UISwipeGestureRecognizer) {
        //     if let webView = gesture.view as? WKWebView, webView.canGoForward {
        //         webView.goForward()
        //     }
        // }

        // @objc func handleSwipeRight(_ gesture: UISwipeGestureRecognizer) {
        //     if let webView = gesture.view as? WKWebView, webView.canGoBack {
        //         webView.goBack()
        //     }
        // }

        @objc func redirectToURL(_ notification: Notification) {
            if let urlString = notification.userInfo?["url"] as? String {
                let normalizedUrlString = NativePHPApp.normalizeHostedRemoteUrl(urlString)

                if let url = URL(string: normalizedUrlString) {
                    // Stop any current loading before starting new request
                    if self.webView?.isLoading == true {
                        self.webView?.stopLoading()
                    }

                    self.webView?.load(URLRequest(url: url))
                }
            }
        }

        /// Navigate using Inertia router if available, otherwise fall back to location.href
        /// This allows native edge component clicks to integrate with Inertia.js for SPA-like navigation
        @objc func navigateWithInertia(_ notification: Notification) {
            guard let path = notification.userInfo?["path"] as? String else { return }

            // Escape the path for JavaScript string
            let escapedPath = path
                .replacingOccurrences(of: "\\", with: "\\\\")
                .replacingOccurrences(of: "\"", with: "\\\"")

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

            self.webView?.evaluateJavaScript(js, completionHandler: nil)
        }

        @objc func keyboardWillShow(_ notification: Notification) {
            let js = "document.body.classList.add('keyboard-visible');"
            self.webView?.evaluateJavaScript(js, completionHandler: nil)
        }

        @objc func keyboardWillHide(_ notification: Notification) {
            let js = "document.body.classList.remove('keyboard-visible');"
            self.webView?.evaluateJavaScript(js, completionHandler: nil)
        }
    }

    func makeUIView(context: Context) -> WKWebView {
        let coordinator = context.coordinator

        // Reuse existing WebView if available (coordinator is also reused via makeCoordinator)
        if let existingWebView = shared.webView {
            print("♻️ Reusing existing WKWebView instance (coordinator already reused)")
            // Ensure coordinator has reference to webView
            coordinator.webView = existingWebView
            existingWebView.navigationDelegate = coordinator
            existingWebView.uiDelegate = coordinator
            existingWebView.alpha = 1.0

            // Observers are still registered (we don't remove them in dismantleUIView)
            // LaravelBridge is still connected (we don't clear it in dismantleUIView)

            return existingWebView
        }

        print("🆕 Creating new WKWebView instance with new Coordinator")

        // Initialize the custom scheme handler
        let schemeHandler = PHPSchemeHandler()

        // Configure WKWebView with the custom scheme handler
        let webConfiguration = WKWebViewConfiguration()

        webConfiguration.websiteDataStore = WebView.dataStore
        webConfiguration.setURLSchemeHandler(schemeHandler, forURLScheme: "php")
        webConfiguration.allowsInlineMediaPlayback = true

        let webView = WKWebView(frame: .zero, configuration: webConfiguration)
        webView.uiDelegate = coordinator

        // Store webView in coordinator and shared instance
        coordinator.webView = webView
        shared.webView = webView
        shared.coordinator = coordinator

        addDebugSupport(webView: webView, context: context)

        addNativeHelper(webView: webView)

        addSwipeGestureSupport(webView: webView, context: context)

        // Configure scrollView for proper safe area handling with viewport-fit=cover
        webView.scrollView.contentInsetAdjustmentBehavior = .never

        let fallbackPath = Bundle.main.path(forResource: "index", ofType: "html")
        let fallbackURL = URL(fileURLWithPath: fallbackPath!)

        // Set initial opacity to 0 for smooth fade-in (instead of hiding)
        webView.alpha = 0.0

        // Give AppDelegate time to process any launch deep links before deciding what to load
        DispatchQueue.main.asyncAfter(deadline: .now() + 0.1) {
            DebugLogger.shared.log("🌐 WebView setup after AppDelegate delay")

            // Check for pending deep link BEFORE marking ready
            // (marking ready might process and clear the pending URL immediately)
            let hasPendingDeepLink = DeepLinkRouter.shared.hasPendingURL()

            // Mark WebView as ready (this may trigger pending URL processing)
            DeepLinkRouter.shared.markWebViewReady()

            // Only load default URL if there was no pending deep link
            if !hasPendingDeepLink {
                DebugLogger.shared.log("🌐 No pending deep link, loading default URL")
                let startPath = NativePHPApp.getStartURL()

                if NativePHPApp.isAbsoluteUrl(startPath),
                   let startPage = URL(string: startPath) {
                    webView.load(URLRequest(url: startPage))
                } else {
                    let startPage = URL(string: "php://127.0.0.1\(startPath)")
                    webView.load(URLRequest(url: startPage ?? fallbackURL))
                }
            } else {
                DebugLogger.shared.log("🌐 Pending deep link detected, skipping default URL load")
            }
        }

        // Setup Laravel bridge - use shared coordinator so it persists
        LaravelBridge.shared.send = { [weak shared] event, payload in
            Task { @MainActor in
                shared?.coordinator?.notifyLaravel(event: event, payload: payload as [String : Any])
            }
        }

        // Register NotificationCenter observers
        NotificationCenter.default.addObserver(
            context.coordinator,
            selector: #selector(Coordinator.reloadWebView),
            name: .reloadWebViewNotification,
            object: nil
        )

        NotificationCenter.default.addObserver(
            context.coordinator,
            selector: #selector(Coordinator.redirectToURL),
            name: .redirectToURLNotification,
            object: nil
        )

        NotificationCenter.default.addObserver(
            context.coordinator,
            selector: #selector(Coordinator.navigateWithInertia),
            name: .navigateWithInertiaNotification,
            object: nil
        )

        // Keyboard visibility observers
        NotificationCenter.default.addObserver(
            context.coordinator,
            selector: #selector(Coordinator.keyboardWillShow),
            name: UIResponder.keyboardWillShowNotification,
            object: nil
        )

        NotificationCenter.default.addObserver(
            context.coordinator,
            selector: #selector(Coordinator.keyboardWillHide),
            name: UIResponder.keyboardWillHideNotification,
            object: nil
        )

        return webView
    }

    func addDebugSupport(webView: WKWebView, context: Context) {
        #if DEBUG
        let userContentController = webView.configuration.userContentController
        let consoleLoggingScript = """
        (function() {
            function capture(type) {
                var old = console[type];
                console[type] = function() {
                    var message = Array.prototype.slice.call(arguments).join(" ");
                    window.webkit.messageHandlers.console.postMessage({ type: type, message: message });
                    old.apply(console, arguments);
                };
            }
            ['log', 'warn', 'error', 'debug'].forEach(capture);
        })();
        """

        let userScript = WKUserScript(source: consoleLoggingScript, injectionTime: .atDocumentStart, forMainFrameOnly: false)
        userContentController.addUserScript(userScript)
        userContentController.add(context.coordinator.logger, name: "console")

        webView.isInspectable = true
        #endif
    }

    func addNativeHelper(webView: WKWebView) {
        let contentController = webView.configuration.userContentController

        // Register native UI update handler for Livewire SPA navigation
        contentController.add(NativeUIHandler(), name: "nativeUI")
        contentController.add(HapticHandler(), name: "haptic")
        contentController.add(ToastHandler(), name: "toast")

        // Inject safe area CSS FIRST at document start to prevent layout jump
        let safeAreaCSS = """
        (function() {
            var style = document.createElement('style');
            style.textContent = ':root{--inset-top:env(safe-area-inset-top,0px);--inset-right:env(safe-area-inset-right,0px);--inset-bottom:env(safe-area-inset-bottom,0px);--inset-left:env(safe-area-inset-left,0px)}@media(orientation:landscape){.nativephp-safe-area{padding-right:var(--inset-right);padding-left:var(--inset-left)}}@media(orientation:portrait){.nativephp-safe-area{padding-top:var(--inset-top);padding-bottom:var(--inset-bottom)}}';
            (document.head || document.documentElement).appendChild(style);
        })();
        """
        let safeAreaScript = WKUserScript(
            source: safeAreaCSS,
            injectionTime: .atDocumentStart,
            forMainFrameOnly: true
        )
        contentController.addUserScript(safeAreaScript)

        // Inject Native helper and other functionality at document end
        let helper = """
        const Native = {
            on: (event, callback) => {
                document.addEventListener("native-event", function (e) {
                    event = event.replace(/^(\\\\)+/, '');
                    e.detail.event = e.detail.event.replace(/^(\\\\)+/, '');

                    if (event === e.detail.event) {
                        return callback(e.detail.payload, event);
                    }
                });
            },
        };

        window.Native = Native;

        document.addEventListener("native-event", function (e) {
            e.detail.event = e.detail.event.replace(/^(\\\\)+/, '');

            if (window.Livewire) {
                window.Livewire.dispatch('native:' + e.detail.event, e.detail.payload);
            }
        });

        (function() {
            // Add platform identifier class
            document.body.classList.add('nativephp-ios');

            // Disable text selection
            document.body.style.userSelect = "none";
        })();
        """
        let script = WKUserScript(
            source: helper,
            injectionTime: .atDocumentEnd,
            forMainFrameOnly: true
        )
        contentController.addUserScript(script)
    }

    func addSwipeGestureSupport(webView: WKWebView, context: Context) {
        webView.navigationDelegate = context.coordinator
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {
        // No manual insets needed - safeAreaInset handles topbar automatically
        // Bottom nav uses its own safeAreaInset in WebViewLayoutContainer
    }
}

class NativeUIHandler: NSObject, WKScriptMessageHandler {
    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        guard let json = message.body as? String else { return }

        DispatchQueue.main.async {
            if json.isEmpty {
                NativeUIState.shared.clearAll()
            } else {
                NativeUIState.shared.updateFromJson(json)
            }
        }
    }
}

class HapticHandler: NSObject, WKScriptMessageHandler {
    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        DispatchQueue.main.async {
            UIImpactFeedbackGenerator(style: .medium).impactOccurred()
        }
    }
}

class ToastHandler: NSObject, WKScriptMessageHandler {
    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        guard let body = message.body as? [String: Any],
              let text = body["message"] as? String, !text.isEmpty else { return }

        let seconds: TimeInterval = (body["duration"] as? String) == "long" ? 3.5 : 2.0

        DispatchQueue.main.async {
            guard let scene = UIApplication.shared.connectedScenes.compactMap({ $0 as? UIWindowScene }).first,
                  let window = scene.windows.first else { return }

            let label = UILabel()
            label.text = text
            label.textColor = .white
            label.backgroundColor = UIColor.black.withAlphaComponent(0.75)
            label.textAlignment = .center
            label.font = .systemFont(ofSize: 14, weight: .medium)
            label.numberOfLines = 0
            label.layer.cornerRadius = 10
            label.clipsToBounds = true
            label.alpha = 0
            label.translatesAutoresizingMaskIntoConstraints = false

            window.addSubview(label)
            NSLayoutConstraint.activate([
                label.centerXAnchor.constraint(equalTo: window.centerXAnchor),
                label.bottomAnchor.constraint(equalTo: window.safeAreaLayoutGuide.bottomAnchor, constant: -24),
                label.widthAnchor.constraint(lessThanOrEqualTo: window.widthAnchor, multiplier: 0.8),
            ])
            let insets = UIEdgeInsets(top: 10, left: 20, bottom: 10, right: 20)
            label.layoutMargins = insets

            UIView.animate(withDuration: 0.25) { label.alpha = 1 }
            DispatchQueue.main.asyncAfter(deadline: .now() + seconds) {
                UIView.animate(withDuration: 0.25, animations: { label.alpha = 0 }) { _ in label.removeFromSuperview() }
            }
        }
    }
}

class ConsoleLogger: NSObject, WKScriptMessageHandler {
    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        if let body = message.body as? [String: Any],
           let type = body["type"] as? String,
           let logMessage = body["message"] as? String {
            print()
            print("JS \(type): \(logMessage)")
        }
    }
}
