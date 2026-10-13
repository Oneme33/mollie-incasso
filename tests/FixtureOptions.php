<?php

namespace Lukraak\MollieIncasso\Tests;

// Request-local options: mock tests must never replace settings visible to other users.
final class FixtureOptions
{
    private static array $values = [];
    private static array $filters = [];
    private static array $updates = [];
    public static function start(): void
    {
        foreach (['mi_settings' => ['mode' => 'test'], 'mi_schema' => 1, 'mi_instance' => wp_generate_uuid4(), 'mi_site_binding' => '', 'mi_last_webhook_test' => null, 'mi_last_webhook_live' => null, 'mi_last_tick' => null, 'mi_cron_error' => []] as $key => $value) {
            self::scope($key, $value);
        }
    }
    public static function set(string $key, mixed $value, bool $autoload = false): void
    {
        self::$values[$key] = $value;
    }
    public static function remove(string $key): void
    {
        self::$values[$key] = null;
    }
    public static function scope(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
        self::$filters[$key] = static fn() => self::$values[$key];
        add_filter('pre_option_' . $key, self::$filters[$key]);
        self::$updates[$key] = static function ($new, $old) use ($key) {
            self::$values[$key] = $new;
            return $old;
        };
        add_filter('pre_update_option_' . $key, self::$updates[$key], 10, 2);
    }
    public static function finish(): void
    {
        foreach (self::$filters as $key => $filter) {
            remove_filter('pre_option_' . $key, $filter);
        }
        foreach (self::$updates as $key => $filter) {
            remove_filter('pre_update_option_' . $key, $filter);
        }
        self::$updates = [];
        self::$filters = [];
        self::$values = [];
    }
}
