<?php
declare(strict_types=1);

/**
 * All Pest tables. ensure() is idempotent and self-healing, like NuCustomerRequests::ensureSchema().
 * DDL is portable between MySQL (production) and SQLite (unit tests); MySQL-only KEY lines are added by driver.
 * Money and quantities are DECIMAL; times are UTC DATETIME; ids are CHAR(36) UUIDs (see ApId).
 */
final class ApSchema
{
    /** @param object $db NuDatabase (or a stand-in exposing getDriverName() and exec()) */
    public static function ensure(object $db): void
    {
        $mysql = $db->getDriverName() === 'mysql';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        foreach (self::tables() as $name => [$columns, $keys]) {
            $extra = ($mysql && $keys) ? ', ' . implode(', ', array_map(fn ($k) => "KEY ($k)", $keys)) : '';
            $db->exec("CREATE TABLE IF NOT EXISTS ap_{$name} ({$columns}{$extra}){$suffix}");
        }
    }

    /** @return array<string,array{0:string,1:array<int,string>}> table => [column definitions, mysql index columns] */
    private static function tables(): array
    {
        $id = 'id CHAR(36) NOT NULL PRIMARY KEY';
        $created = 'created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $updated = 'updated_at DATETIME NULL';
        return [
            'settings' => ['setting_key VARCHAR(100) NOT NULL PRIMARY KEY, setting_value TEXT NULL, ' . $updated, []],
            'files' => [$id . ', purpose VARCHAR(40) NOT NULL, original_name VARCHAR(255) NULL, stored_name VARCHAR(255) NULL, mime VARCHAR(100) NULL, '
                . 'bytes INTEGER NOT NULL DEFAULT 0, sha256 CHAR(64) NULL, uploader VARCHAR(64) NULL, device VARCHAR(255) NULL, ' . $created, ['sha256']],
            'technicians' => [$id . ', user_id CHAR(36) NULL, full_name VARCHAR(150) NOT NULL, registration_no VARCHAR(80) NULL, registration_expiry DATE NULL, '
                . 'accreditation_no VARCHAR(80) NULL, signature_file_id CHAR(36) NULL, status VARCHAR(20) NOT NULL DEFAULT \'active\', ' . $created . ', ' . $updated, ['user_id']],
            'technician_files' => [$id . ', technician_id CHAR(36) NOT NULL, file_id CHAR(36) NOT NULL, label VARCHAR(100) NULL, ' . $created, ['technician_id']],
            'officers' => [$id . ', full_name VARCHAR(150) NOT NULL, designation VARCHAR(150) NULL, status VARCHAR(20) NOT NULL DEFAULT \'active\', ' . $created, []],
            'equipment' => [$id . ', kind VARCHAR(40) NOT NULL, name VARCHAR(150) NOT NULL, serial_no VARCHAR(100) NULL, status VARCHAR(20) NOT NULL DEFAULT \'in_service\', '
                . 'next_audit_due DATE NULL, expiry_date DATE NULL, notes TEXT NULL, ' . $created . ', ' . $updated, ['status']],
            'equipment_log' => [$id . ', equipment_id CHAR(36) NOT NULL, kind VARCHAR(20) NOT NULL, note TEXT NULL, user_id VARCHAR(64) NULL, ' . $created, ['equipment_id']],
            'schedules' => [$id . ', name VARCHAR(150) NOT NULL, treatment_type VARCHAR(20) NOT NULL, dose_rate DECIMAL(10,2) NOT NULL, exposure_hours DECIMAL(8,2) NOT NULL, '
                . 'min_temp_c DECIMAL(5,1) NOT NULL DEFAULT 21, compensation_allowed TINYINT NOT NULL DEFAULT 0, topups_allowed TINYINT NOT NULL DEFAULT 1, '
                . 'retention_class VARCHAR(10) NOT NULL DEFAULT \'standard\', active TINYINT NOT NULL DEFAULT 1, notes TEXT NULL, ' . $created . ', ' . $updated, []],
            'stock_lots' => [$id . ', lot_ref VARCHAR(80) NOT NULL, description VARCHAR(200) NULL, mb_fraction DECIMAL(4,3) NOT NULL DEFAULT 1, ' . $created, []],
            'stock_movements' => [$id . ', lot_id CHAR(36) NOT NULL, kind VARCHAR(10) NOT NULL, grams DECIMAL(12,2) NOT NULL, treatment_id CHAR(36) NULL, reason TEXT NULL, '
                . 'discrepancy TINYINT NOT NULL DEFAULT 0, user_id VARCHAR(64) NULL, ' . $created, ['lot_id', 'treatment_id']],
            'requests' => [$id . ', reference VARCHAR(30) NOT NULL UNIQUE, job_type VARCHAR(30) NOT NULL DEFAULT \'fumigation\', status VARCHAR(20) NOT NULL DEFAULT \'new\', '
                . 'customer_name VARCHAR(200) NOT NULL, customer_email VARCHAR(200) NOT NULL, customer_phone VARCHAR(50) NULL, customer_tin VARCHAR(50) NULL, customer_address TEXT NULL, '
                . 'site_address TEXT NULL, site_lat DECIMAL(9,6) NULL, site_lng DECIMAL(9,6) NULL, requested_date DATE NULL, client_token VARCHAR(64) NULL UNIQUE, '
                . 'ip VARCHAR(45) NULL, notes TEXT NULL, ' . $created . ', ' . $updated, ['status']],
            'consignments' => [$id . ', request_id CHAR(36) NOT NULL, container_no VARCHAR(30) NULL, seal_no VARCHAR(40) NULL, commodity VARCHAR(200) NULL, origin VARCHAR(100) NULL, '
                . 'destination VARCHAR(100) NULL, quantity VARCHAR(80) NULL, bol_file_id CHAR(36) NULL, ' . $created, ['request_id']],
            'treatments' => [$id . ', request_id CHAR(36) NOT NULL, rof_type VARCHAR(20) NOT NULL DEFAULT \'general\', status VARCHAR(20) NOT NULL DEFAULT \'planned\', '
                . 'schedule_id CHAR(36) NULL, technician_id CHAR(36) NULL, officer_id CHAR(36) NULL, scheduled_start DATETIME NULL, site_lat DECIMAL(9,6) NULL, site_lng DECIMAL(9,6) NULL, '
                . 'fumigation_type VARCHAR(20) NOT NULL DEFAULT \'ambient\', enclosure_type VARCHAR(30) NULL, height_m DECIMAL(8,3) NULL, depth_m DECIMAL(8,3) NULL, length_m DECIMAL(8,3) NULL, '
                . 'volume_m3 DECIMAL(10,3) NULL, mb_fraction DECIMAL(4,3) NOT NULL DEFAULT 1, dose_rate_used DECIMAL(10,2) NULL, dose_g DECIMAL(12,2) NULL, applied_g DECIMAL(12,2) NULL, '
                . 'lot_id CHAR(36) NULL, equipment_ids TEXT NULL, forecast_min_c DECIMAL(5,1) NULL, forecast_source TEXT NULL, forecast_retrieved_at DATETIME NULL, forecast_json TEXT NULL, '
                . 'injected_at DATETIME NULL, start_point_at DATETIME NULL, ventilation_at DATETIME NULL, result VARCHAR(10) NULL, version INTEGER NOT NULL DEFAULT 1, locked_at DATETIME NULL, '
                . 'closed_at DATETIME NULL, admin_verified_by VARCHAR(64) NULL, admin_verified_at DATETIME NULL, ' . $created . ', ' . $updated, ['request_id', 'technician_id']],
            'treatment_containers' => [$id . ', treatment_id CHAR(36) NOT NULL, consignment_id CHAR(36) NOT NULL UNIQUE, result VARCHAR(10) NULL, tlv_ppm DECIMAL(8,2) NULL, tlv_at DATETIME NULL', ['treatment_id']],
            'treatment_versions' => [$id . ', treatment_id CHAR(36) NOT NULL, version INTEGER NOT NULL, snapshot TEXT NOT NULL, reason TEXT NULL, user_id VARCHAR(64) NULL, ' . $created
                . ', UNIQUE (treatment_id, version)', []],
            'reading_sets' => [$id . ', treatment_id CHAR(36) NOT NULL, client_uuid CHAR(36) NOT NULL UNIQUE, stage VARCHAR(30) NOT NULL, taken_at DATETIME NOT NULL, initials VARCHAR(10) NOT NULL, '
                . 'standard DECIMAL(10,2) NULL, equilibrium_pct DECIMAL(7,3) NULL, version INTEGER NOT NULL DEFAULT 1, created_by VARCHAR(64) NULL, ' . $created, ['treatment_id']],
            'reading_set_files' => [$id . ', reading_set_id CHAR(36) NOT NULL, file_id CHAR(36) NOT NULL', ['reading_set_id']],
            'readings' => [$id . ', reading_set_id CHAR(36) NOT NULL, container_id CHAR(36) NOT NULL, location VARCHAR(30) NOT NULL, value DECIMAL(10,2) NOT NULL, meets TINYINT NULL', ['reading_set_id']],
            'signatures' => [$id . ', treatment_id CHAR(36) NOT NULL, role VARCHAR(12) NOT NULL, signer_name VARCHAR(150) NOT NULL, signer_designation VARCHAR(150) NULL, user_id VARCHAR(64) NULL, '
                . 'image_file_id CHAR(36) NULL, signed_at DATETIME NOT NULL, device VARCHAR(255) NULL, version INTEGER NOT NULL DEFAULT 1', ['treatment_id']],
            'certificates' => [$id . ', treatment_id CHAR(36) NOT NULL, container_id CHAR(36) NOT NULL UNIQUE, number VARCHAR(30) NOT NULL, content_sha256 CHAR(64) NOT NULL, '
                . 'issued_at DATETIME NULL, emailed_at DATETIME NULL, email_pending TINYINT NOT NULL DEFAULT 0, verified_by VARCHAR(64) NULL, verified_at DATETIME NULL, ' . $created, ['treatment_id']],
            'certificate_numbers' => [$id . ', number VARCHAR(30) NOT NULL UNIQUE, container_id CHAR(36) NULL, allocated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', []],
            'invoice_links' => [$id . ', request_id CHAR(36) NOT NULL UNIQUE, state VARCHAR(12) NOT NULL DEFAULT \'pending\', erpx_invoice_id INTEGER NULL, erpx_invoice_no VARCHAR(60) NULL, '
                . 'attempts INTEGER NOT NULL DEFAULT 0, last_error TEXT NULL, next_attempt_at DATETIME NULL, ' . $created . ', ' . $updated, []],
            'intake_throttle' => [$id . ', ip VARCHAR(45) NOT NULL, ' . $created, ['ip']],
        ];
    }
}
