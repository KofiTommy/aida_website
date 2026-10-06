<?php
require_once __DIR__.'/media.php';
// Start the configured session before the homepage sends any HTML.
if (is_file(__DIR__ . '/config.local.php')) {
    try { require_once __DIR__ . '/bootstrap.php'; } catch (Throwable $error) {}
}
function cms_setting(string $key, string $fallback): string {
    static $settings = null;
    if ($settings === null) {
        try {
            $file = __DIR__ . '/config.local.php';
            if (!is_file($file)) { $settings=[]; } else { require_once __DIR__ . '/bootstrap.php'; $settings = db()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll(PDO::FETCH_KEY_PAIR); }
        } catch (Throwable $error) { $settings = []; }
    }
    return trim((string)($settings[$key] ?? '')) ?: $fallback;
}
function cms_text(string $key, string $fallback): string { return htmlspecialchars(cms_setting($key, $fallback), ENT_QUOTES, 'UTF-8'); }
