<?php
declare(strict_types=1);

require_once __DIR__ . '/ApDb.php';

/** Key/value install settings (staff email, release mode, tax rate, ...). Stored in ap_settings. */
final class ApSettings
{
    public static function get(string $key, ?string $default = null): ?string
    {
        $r = ApDb::get()->fetchOne('SELECT setting_value FROM ap_settings WHERE setting_key = ?', [$key]);
        return $r && $r['setting_value'] !== null ? (string)$r['setting_value'] : $default;
    }

    public static function set(string $key, string $value): void
    {
        $db = ApDb::get();
        $now = gmdate('Y-m-d H:i:s');
        if ($db->fetchOne('SELECT setting_key FROM ap_settings WHERE setting_key = ?', [$key])) {
            $db->update('ap_settings', ['setting_value' => $value, 'updated_at' => $now], 'setting_key = ?', [$key]);
        } else {
            $db->insert('ap_settings', ['setting_key' => $key, 'setting_value' => $value, 'updated_at' => $now]);
        }
    }
}
