<?php
/**
 * SeederLinux Lite - Configuration
 * Auto-detect base URL and environment settings
 */

declare(strict_types=1);

class Config {
    private static array $settings = [];

    /**
     * Initialize configuration
     */
    public static function init(): void {
        self::$settings = [
            'base_url' => self::detectBaseUrl(),
            'app_name' => $_ENV['APP_NAME'] ?? 'SeederLinux Lite',
            'app_version' => $_ENV['APP_VERSION'] ?? '1.0.0',
            'debug' => filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ];
    }

    /**
     * Detect base URL from server
     */
    public static function detectBaseUrl(): string {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $port = '';

        // Include port if non-standard
        if (!in_array($_SERVER['SERVER_PORT'] ?? '80', ['80', '443'])) {
            $port = ':' . $_SERVER['SERVER_PORT'];
        }

        return "$protocol://$host$port";
    }

    /**
     * Get base URL
     */
    public static function getBaseUrl(): string {
        if (empty(self::$settings)) {
            self::init();
        }
        return self::$settings['base_url'];
    }

    /**
     * Get full URL for a path
     */
    public static function url(string $path = ''): string {
        $baseUrl = self::getBaseUrl();
        $path = ltrim($path, '/');
        return $path ? "$baseUrl/$path" : $baseUrl;
    }

    /**
     * Get asset URL
     */
    public static function asset(string $path): string {
        return self::url("assets/$path");
    }

    /**
     * Get public page URL
     */
    public static function page(string $page): string {
        return self::url($page);
    }

    /**
     * Get API URL
     */
    public static function api(string $action = ''): string {
        if ($action) {
            return self::url("api/?action=$action");
        }
        return self::url("api/");
    }

    /**
     * Get setting
     */
    public static function get(string $key, $default = null) {
        if (empty(self::$settings)) {
            self::init();
        }
        return self::$settings[$key] ?? $default;
    }

    /**
     * Check if debug mode is enabled
     */
    public static function isDebug(): bool {
        return self::get('debug', false);
    }
}

// Initialize on load
Config::init();
