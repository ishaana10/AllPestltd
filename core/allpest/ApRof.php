<?php
declare(strict_types=1);

require_once __DIR__ . '/ApId.php';
require_once __DIR__ . '/ApDb.php';
require_once __DIR__ . '/ApSettings.php';
require_once __DIR__ . '/ApTreatments.php';

/**
 * The record of fumigation as the methodology v3.0 templates lay it out (sections A to E, general and perishable variants).
 * document() assembles the whole record from the treatment, its request, readings and signatures. saveDetails() takes the section B/C
 * facts the engine does not otherwise capture; temperatures() takes the perishable temperature readings (section D, up to 7 probes).
 */
final class ApRof
{
    public const TARGETS = ['commodity', 'container', 'packaging', 'other'];
    public const ENCLOSURES = ['sheeted' => 'Sheeted enclosure', 'chamber' => 'Fumigation chamber', 'container' => 'Un-sheeted container(s)', 'container_stack' => 'Stack of containers under sheet', 'other' => 'Other'];
    public const PROBES = ['inside_packaging' => 'Inside packaging', 'pulp' => 'Inserted into pulp'];
    private const MAX_PROBES = 7;
    private const TEXT = ['remedial_action', 'target_other', 'enclosure_other', 'consignment_description', 'loc_street', 'loc_suburb', 'loc_country', 'loc_postcode'];
    private const LIMITS = ['remedial_action' => 500, 'target_other' => 200, 'enclosure_other' => 200, 'consignment_description' => 1000, 'loc_street' => 200, 'loc_suburb' => 100, 'loc_country' => 100, 'loc_postcode' => 20];

    /** @param array<string,mixed> $in only the keys present are changed */
    public static function saveDetails(string $treatmentId, array $in, string $userId = ''): array
    {
        $db = ApDb::get();
        $t = $db->fetchOne('SELECT * FROM ap_treatments WHERE id = ?', [$treatmentId]);
        if (!$t) { throw new InvalidArgumentException('Unknown treatment.'); }
        if (!empty($t['locked_at'])) { throw new ApLockedException('This ROF is signed and read-only. Corrections are made as a new version.'); }
        $set = [];
        foreach (self::TEXT as $k) {
            if (!array_key_exists($k, $in)) { continue; }
            $v = trim((string)$in[$k]);
            if (mb_strlen($v) > self::LIMITS[$k]) { throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $k)) . ' is too long (limit ' . self::LIMITS[$k] . ' characters).'); }
            $set[$k] = $v === '' ? null : $v;
        }
        foreach (['suitable', 'heaters_used'] as $k) {
            if (!array_key_exists($k, $in) || $in[$k] === '' || $in[$k] === null) { if (array_key_exists($k, $in)) { $set[$k] = null; } continue; }
            if (!in_array((string)$in[$k], ['0', '1'], true) && !is_bool($in[$k]) && !is_int($in[$k])) { throw new InvalidArgumentException(ucfirst(str_replace('_', ' ', $k)) . ' must be yes or no.'); }
            $set[$k] = $in[$k] ? 1 : 0;
        }
        if (array_key_exists('target_of_fumigation', $in)) {
            $list = is_array($in['target_of_fumigation']) ? $in['target_of_fumigation'] : array_filter(explode(',', (string)$in['target_of_fumigation']));
            $list = array_values(array_unique(array_map('strval', $list)));
            foreach ($list as $x) { if (!in_array($x, self::TARGETS, true)) { throw new InvalidArgumentException('Target of fumigation must be commodity, container, packaging or other.'); } }
            $set['target_of_fumigation'] = $list ? implode(',', $list) : null;
        }
        if (array_key_exists('probe_location', $in)) {
            $v = (string)$in['probe_location'];
            if ($v !== '' && !isset(self::PROBES[$v])) { throw new InvalidArgumentException('Temperature probe location must be inside packaging or inserted into pulp.'); }
            $set['probe_location'] = $v === '' ? null : $v;
        }
        foreach (['load_factor_max' => 'Maximum load factor', 'load_factor_est' => 'Estimated load factor', 'retention_pct' => 'Retention rate'] as $k => $label) {
            if (!array_key_exists($k, $in)) { continue; }
            $set[$k] = self::number($in[$k], $label, 0.0, 100.0);
        }
        if (array_key_exists('ct_product', $in)) { $set['ct_product'] = self::number($in['ct_product'], 'CT product', 0.0, 100000.0); }
        if (array_key_exists('min_enclosure_temp_c', $in)) { $set['min_enclosure_temp_c'] = self::number($in['min_enclosure_temp_c'], 'Minimum enclosure temperature', -40.0, 80.0); }
        $m = array_merge($t, $set);
        if ((string)($m['suitable'] ?? '') === '0' && trim((string)($m['remedial_action'] ?? '')) === '') { throw new InvalidArgumentException('Say what remedial action was taken when the consignment was not suitable.'); }
        if ($m['load_factor_est'] !== null && $m['load_factor_max'] !== null && (float)$m['load_factor_est'] > (float)$m['load_factor_max']) { throw new InvalidArgumentException('The estimated load factor is above the maximum.'); }
        if (in_array('other', explode(',', (string)($m['target_of_fumigation'] ?? '')), true) && trim((string)($m['target_other'] ?? '')) === '') { throw new InvalidArgumentException('Describe the other target of fumigation.'); }
        if ((string)($m['enclosure_type'] ?? '') === 'other' && trim((string)($m['enclosure_other'] ?? '')) === '') { throw new InvalidArgumentException('Describe the other enclosure type.'); }
        $port = null;
        if (array_key_exists('port_of_loading', $in)) {
            $port = trim((string)$in['port_of_loading']);
            if (mb_strlen($port) > 100) { throw new InvalidArgumentException('Port of loading is too long (limit 100 characters).'); }
        }
        if (!$set && $port === null) { return []; }
        if ($port !== null) {
            $db->exec("UPDATE ap_consignments SET port_of_loading = " . ($port === '' ? 'NULL' : $db->getPdo()->quote($port)) . " WHERE id IN (SELECT consignment_id FROM ap_treatment_containers WHERE treatment_id = " . $db->getPdo()->quote($treatmentId) . ")");
            $set['port_of_loading'] = 'applied';
        }
        $set['updated_at'] = gmdate('Y-m-d H:i:s');
        $fields = array_keys($set);
        unset($set['port_of_loading']);
        $db->update('ap_treatments', $set, 'id = ?', [$treatmentId]);
        ApTreatments::audit('treatment.rof_details', $treatmentId, ['fields' => $fields, 'user' => $userId]);
        return $fields;
    }

    /** One row of perishable temperature readings: values by probe number (1 to 7), taken at a time, initialled. */
    public static function temperatures(string $treatmentId, DateTimeImmutable $at, array $values, string $initials, string $userId = ''): string
    {
        $db = ApDb::get();
        $t = $db->fetchOne('SELECT rof_type, locked_at FROM ap_treatments WHERE id = ?', [$treatmentId]);
        if (!$t) { throw new InvalidArgumentException('Unknown treatment.'); }
        if (!empty($t['locked_at'])) { throw new ApLockedException('This ROF is signed and read-only. Corrections are made as a new version.'); }
        if ($t['rof_type'] !== 'perishable') { throw new ApTreatmentError('Temperature readings belong to perishable records only.'); }
        $initials = strtoupper(trim($initials));
        if (!preg_match('/^[A-Z]{1,6}$/', $initials)) { throw new InvalidArgumentException('Enter the fumigator\'s initials (letters only).'); }
        $clean = [];
        foreach ($values as $probe => $v) {
            $n = (int)$probe;
            if ($n < 1 || $n > self::MAX_PROBES) { throw new InvalidArgumentException('Probe numbers run from 1 to ' . self::MAX_PROBES . '.'); }
            if (trim((string)$v) === '') { continue; }
            $clean[$n] = self::number($v, "Probe $n temperature", -40.0, 80.0);
        }
        if (!$clean) { throw new InvalidArgumentException('Enter at least one temperature.'); }
        if ($at->getTimestamp() > time() + 300) { throw new InvalidArgumentException('A reading cannot be dated in the future.'); }
        $id = ApId::uuid();
        ksort($clean);
        $db->insert('ap_temp_readings', ['id' => $id, 'treatment_id' => $treatmentId, 'taken_at' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'initials' => $initials, 'values_json' => json_encode($clean), 'created_by' => $userId]);
        ApTreatments::audit('treatment.temperatures', $treatmentId, ['reading_id' => $id, 'probes' => array_keys($clean)]);
        return $id;
    }

    /** The whole record in paper order. Values are as stored (UTC times), nothing here is calculated beyond what the template shows. */
    public static function document(string $treatmentId): array
    {
        $db = ApDb::get();
        $t = $db->fetchOne('SELECT * FROM ap_treatments WHERE id = ?', [$treatmentId]);
        if (!$t) { throw new InvalidArgumentException('Unknown treatment.'); }
        $r = $db->fetchOne('SELECT * FROM ap_requests WHERE id = ?', [$t['request_id']]) ?: [];
        $s = $db->fetchOne('SELECT * FROM ap_schedules WHERE id = ?', [(string)$t['schedule_id']]) ?: [];
        $tech = $t['technician_id'] ? ($db->fetchOne('SELECT full_name, accreditation_no, registration_no FROM ap_technicians WHERE id = ?', [$t['technician_id']]) ?: []) : [];
        $officer = $t['officer_id'] ? ($db->fetchOne('SELECT full_name, designation FROM ap_officers WHERE id = ?', [$t['officer_id']]) ?: []) : [];
        $containers = $db->fetchAll('SELECT tc.id, tc.result, tc.core_temp_c, tc.tlv_ppm, tc.tlv_at, c.container_no, c.seal_no, c.commodity, c.origin, c.destination, c.quantity, c.port_of_loading FROM ap_treatment_containers tc JOIN ap_consignments c ON c.id = tc.consignment_id WHERE tc.treatment_id = ? ORDER BY c.container_no', [$treatmentId]);
        $serials = [];
        foreach (json_decode((string)($t['equipment_ids'] ?? '[]'), true) ?: [] as $eid) {
            $e = $db->fetchOne('SELECT kind, name, serial_no FROM ap_equipment WHERE id = ?', [$eid]);
            if ($e && $e['kind'] === 'detector' && (string)$e['serial_no'] !== '') { $serials[] = (string)$e['serial_no']; }
        }
        $sets = $db->fetchAll('SELECT id, stage, taken_at, initials, standard, equilibrium_pct FROM ap_reading_sets WHERE treatment_id = ? ORDER BY taken_at, created_at', [$treatmentId]);
        foreach ($sets as &$set) {
            $rows = $db->fetchAll('SELECT container_id, location, value, meets FROM ap_readings WHERE reading_set_id = ? ORDER BY container_id, location', [$set['id']]);
            $set['values'] = $rows;
        }
        unset($set);
        $temps = array_map(fn ($x) => ['taken_at' => $x['taken_at'], 'initials' => $x['initials'], 'values' => json_decode((string)$x['values_json'], true) ?: []],
            $db->fetchAll('SELECT taken_at, initials, values_json FROM ap_temp_readings WHERE treatment_id = ? ORDER BY taken_at', [$treatmentId]));
        $sigs = $db->fetchAll('SELECT role, signer_name, signer_designation, signed_at, version FROM ap_signatures WHERE treatment_id = ? ORDER BY signed_at', [$treatmentId]);
        $mb = (float)($t['mb_fraction'] ?? 1);
        $perishable = $t['rof_type'] === 'perishable';
        return [
            'variant' => $perishable ? 'perishable' : 'general',
            'title' => $perishable ? 'Record of treatment – Methyl bromide fumigation (perishable commodity)' : 'Record of fumigation – Methyl bromide fumigation (non-perishable commodity)',
            'status' => $t['status'], 'locked' => !empty($t['locked_at']),
            'A' => ['full_name' => $tech['full_name'] ?? '', 'accreditation_no' => $tech['accreditation_no'] ?? ($tech['registration_no'] ?? '')],
            'B' => [
                'provider_id' => (string)ApSettings::get('treatment_provider_id', ''), 'client' => trim((string)($r['customer_name'] ?? '') . "\n" . (string)($r['customer_address'] ?? '')),
                'job_no' => $r['reference'] ?? '', 'street' => $t['loc_street'] ?? ($r['site_address'] ?? ''), 'suburb' => $t['loc_suburb'] ?? '', 'country' => $t['loc_country'] ?? '', 'postcode' => $t['loc_postcode'] ?? '',
                'description' => $t['consignment_description'] ?? '', 'port_of_loading' => (string)($containers[0]['port_of_loading'] ?? ''), 'targets' => array_values(array_filter(explode(',', (string)($t['target_of_fumigation'] ?? '')))), 'target_other' => $t['target_other'] ?? '',
                'containers' => $containers,
            ],
            'C' => [
                'schedule' => ['name' => $s['name'] ?? '', 'dose_rate' => $s['dose_rate'] ?? null, 'exposure_hours' => $s['exposure_hours'] ?? null, 'min_temp_c' => $s['min_temp_c'] ?? null],
                'suitable' => $t['suitable'] === null ? null : (int)$t['suitable'], 'remedial_action' => $t['remedial_action'] ?? '',
                'enclosure_type' => $t['enclosure_type'], 'enclosure_label' => self::ENCLOSURES[(string)$t['enclosure_type']] ?? (string)$t['enclosure_type'], 'enclosure_other' => $t['enclosure_other'] ?? '',
                'length_m' => $t['length_m'], 'height_m' => $t['height_m'], 'width_m' => $t['depth_m'], 'volume_m3' => $t['volume_m3'],
                'fumigation_type' => $t['fumigation_type'], 'forecast_min_c' => $t['forecast_min_c'], 'min_enclosure_temp_c' => $t['min_enclosure_temp_c'], 'heaters_used' => $t['heaters_used'] === null ? null : (int)$t['heaters_used'],
                'dose_rate_used' => $t['dose_rate_used'], 'calculated_dose_g' => $t['dose_g'], 'chloropicrin' => $mb < 1, 'chloropicrin_pct' => $mb < 1 ? round((1 - $mb) * 100, 1) : null, 'applied_g' => $t['applied_g'],
                'retention_pct' => $t['retention_pct'], 'ct_product' => $t['ct_product'], 'probe_location' => $t['probe_location'], 'load_factor_max' => $t['load_factor_max'], 'load_factor_est' => $t['load_factor_est'],
            ],
            'D' => [
                'serials' => $serials, 'injected_at' => $t['injected_at'], 'start_point_at' => $t['start_point_at'], 'ventilation_at' => $t['ventilation_at'], 'topup_at' => $t['topup_at'], 'topup_g' => $t['topup_g'],
                'reading_sets' => $sets, 'temperatures' => $temps, 'tlv' => array_map(fn ($c) => ['container_no' => $c['container_no'], 'ppm' => $c['tlv_ppm'], 'at' => $c['tlv_at']], $containers),
            ],
            'E' => ['signatures' => $sigs, 'results' => array_map(fn ($c) => ['container_no' => $c['container_no'], 'result' => $c['result']], $containers), 'officer' => $officer],
        ];
    }

    private static function number(mixed $v, string $label, float $min, float $max): ?float
    {
        if ($v === null || trim((string)$v) === '') { return null; }
        if (!is_numeric($v) || !is_finite((float)$v) || (float)$v < $min || (float)$v > $max) { throw new InvalidArgumentException("$label must be a number between $min and $max."); }
        return (float)$v;
    }
}
