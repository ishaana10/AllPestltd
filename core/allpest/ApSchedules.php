<?php
declare(strict_types=1);

require_once __DIR__ . '/ApId.php';
require_once __DIR__ . '/ApDb.php';

/** Treatment schedules (dose rate, exposure, minimum temperature, flags). Entered by the admin from the methodology's schedules. */
final class ApSchedules
{
    /** @param array<string,mixed> $row optional 'id' updates an existing schedule. @return string schedule id */
    public static function save(array $row): string
    {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') { throw new InvalidArgumentException('Schedule name is required'); }
        $type = (string)($row['treatment_type'] ?? '');
        if (!in_array($type, ['general', 'perishable'], true)) { throw new InvalidArgumentException('Type must be general or perishable'); }
        $rate = $row['dose_rate'] ?? null;
        if (!is_numeric($rate) || (float)$rate <= 0) { throw new InvalidArgumentException('Dose rate must be greater than 0'); }
        $hours = $row['exposure_hours'] ?? null;
        if (!is_numeric($hours) || (float)$hours <= 0) { throw new InvalidArgumentException('Exposure hours must be greater than 0'); }
        $min = $row['min_temp_c'] ?? null;
        if (!is_numeric($min)) { throw new InvalidArgumentException('Minimum temperature is required'); }
        $class = (string)($row['retention_class'] ?? 'standard');
        if (!in_array($class, ['standard', 'eighty'], true)) { throw new InvalidArgumentException('Retention class must be standard or eighty'); }

        $data = [
            'name' => $name, 'treatment_type' => $type, 'dose_rate' => (float)$rate, 'exposure_hours' => (float)$hours, 'min_temp_c' => (float)$min,
            'compensation_allowed' => empty($row['compensation_allowed']) ? 0 : 1, 'topups_allowed' => empty($row['topups_allowed']) ? 0 : 1,
            'retention_class' => $class, 'active' => array_key_exists('active', $row) && empty($row['active']) ? 0 : 1, 'notes' => $row['notes'] ?? null,
        ];
        $db = ApDb::get();
        if (!empty($row['id'])) {
            $data['updated_at'] = gmdate('Y-m-d H:i:s');
            $db->update('ap_schedules', $data, 'id = ?', [(string)$row['id']]);
            return (string)$row['id'];
        }
        $data['id'] = ApId::uuid();
        $data['created_at'] = gmdate('Y-m-d H:i:s');
        $db->insert('ap_schedules', $data);
        return $data['id'];
    }

    /** @return array<int,array<string,mixed>> */
    public static function all(): array
    {
        return ApDb::get()->fetchAll('SELECT * FROM ap_schedules ORDER BY treatment_type, name');
    }
}
