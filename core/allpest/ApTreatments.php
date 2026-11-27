<?php
declare(strict_types=1);

require_once __DIR__ . '/ApId.php';
require_once __DIR__ . '/ApDb.php';
require_once __DIR__ . '/ApStandards.php';
require_once __DIR__ . '/ApDose.php';
require_once __DIR__ . '/ApReadings.php';
require_once __DIR__ . '/ApTemperature.php';
require_once __DIR__ . '/ApResult.php';
require_once __DIR__ . '/ApTechnicians.php';
require_once __DIR__ . '/ApEquipment.php';
require_once __DIR__ . '/ApStock.php';
require_once __DIR__ . '/ApRequests.php';

/** An action the treatment's current state does not allow (out of order, already done, missing prerequisite). */
class ApTreatmentError extends RuntimeException
{
}

/** The ROF is signed and therefore read-only; corrections are new versions (see ApSigning::correct). */
class ApLockedException extends RuntimeException
{
}

/**
 * The treatment (ROF) service: orchestrates the rules engine, stock, equipment and technician checks.
 * Flow: planned -> prechecked -> dosed -> exposure -> ventilating -> closed.
 * Every write is audited (action, treatment id, details) and is refused once the treatment is locked.
 */
final class ApTreatments
{
    public const MAX_VOLUME_M3 = 1000.0;
    public const CLOCK_SKEW_MINUTES = 5;
    private const REFERENCE_TEMP_C = 21.0;
    private const CHLOROPICRIN_MB_FRACTION = 0.98;
    private const STAGES = ['start_point', 'exposure', 'end_exposure'];

    /** @var callable|null */
    private static $clock = null;
    /** @var callable|null fn(string $action, string $treatmentId, array $details): void */
    private static $auditor = null;

    public static function setClock(?callable $clock): void { self::$clock = $clock; }
    public static function setAuditor(?callable $auditor): void { self::$auditor = $auditor; }

    // ---- pre-treatment check -------------------------------------------------------------------------------------

    /**
     * Volume, dose and the go / no-go checks. Saves the working on the treatment; the treatment is only marked
     * 'prechecked' (ready to dose) when nothing blocks it.
     * @param array<string,mixed> $in height_m, depth_m, length_m, equipment_ids[], chloropicrin (bool), increment (g), whole_gram (bool),
     *        forecast: ['hourly'=>[...], 'source'=>string, 'retrieved_at'=>string] from ApForecastClient, or ['manual_min_c'=>float, 'source_note'=>string, 'retrieved_at'=>?string]
     * @return array{volume_m3:float,dose_rate_used:float,dose_g:float,rounded_dose_g:float,forecast_min_c:float,blocked:string[]}
     */
    public static function preCheck(string $treatmentId, array $in, DateTimeImmutable $now): array
    {
        $db = ApDb::get();
        $t = self::load($treatmentId);
        self::assertEditable($t);
        if (!in_array($t['status'], ['planned', 'prechecked'], true)) {
            throw new ApTreatmentError('The pre-treatment check can only be redone before dosing starts.');
        }
        $utc = new DateTimeZone('UTC');
        $dim = fn (string $k): float => self::positiveNumber($in[$k] ?? null, ucfirst(str_replace('_m', '', $k)));
        $volume = ApDose::volume($dim('height_m'), $dim('depth_m'), $dim('length_m'));
        if ($volume > self::MAX_VOLUME_M3) {
            throw new InvalidArgumentException(sprintf('A volume of %.1f m3 is not realistic for one enclosure (limit %d m3). Check the dimensions.', $volume, (int)self::MAX_VOLUME_M3));
        }
        $increment = self::positiveNumber($in['increment'] ?? null, 'Increment');
        $sched = self::schedule($t);
        $start = new DateTimeImmutable((string)$t['scheduled_start'], $utc);

        [$minC, $source, $retrievedAt, $forecastDoc] = self::resolveForecast((array)($in['forecast'] ?? []), $start, (float)$sched['exposure_hours'], $now);
        $mbFraction = !empty($in['chloropicrin']) ? self::CHLOROPICRIN_MB_FRACTION : 1.0;
        $rate = ApDose::compensatedDoseRate((float)$sched['dose_rate'], $minC, self::REFERENCE_TEMP_C, (int)$sched['compensation_allowed'] === 1);
        $dose = ApDose::doseGrams($volume, $rate, $mbFraction);
        $rounded = ApDose::roundUpToIncrement($dose, $increment);

        $blocked = [];
        if ($t['fumigation_type'] === 'ambient' && !ApTemperature::ambientAllowed($minC)) {
            $blocked[] = sprintf('Ambient fumigation is not allowed when the forecast minimum is 10 °C or below (forecast %.1f °C).', $minC);
        }
        if (!ApTemperature::forecastIsFresh($retrievedAt, $start)) {
            $blocked[] = 'The forecast is out of date: it must be taken no earlier than the day before the treatment. Refresh it.';
        }
        if (!ApTechnicians::canSign((string)$t['technician_id'], $start->setTimezone(new DateTimeZone(ApTemperature::TZ)))) {
            $blocked[] = 'The fumigator-in-charge is not active or their registration is expired.';
        }
        $equipmentIds = array_values(array_unique(array_map('strval', (array)($in['equipment_ids'] ?? []))));
        if (!$equipmentIds) {
            $blocked[] = 'Select the equipment used (gas detector).';
        } else {
            $ok = array_column(ApEquipment::selectable($now), 'id');
            foreach ($equipmentIds as $eid) {
                if (!in_array($eid, $ok, true)) { $blocked[] = 'Equipment ' . $eid . ' is not available (discarded, in repair, overdue for audit or expired).'; }
            }
        }

        $forecastDoc['working'] = ['volume_m3' => $volume, 'dose_rate' => (float)$sched['dose_rate'], 'dose_rate_used' => $rate, 'mb_fraction' => $mbFraction, 'dose_g' => $dose, 'increment_g' => $increment, 'rounded_dose_g' => $rounded];
        $db->update('ap_treatments', [
            'height_m' => (float)$in['height_m'], 'depth_m' => (float)$in['depth_m'], 'length_m' => (float)$in['length_m'], 'volume_m3' => $volume,
            'mb_fraction' => $mbFraction, 'dose_rate_used' => $rate, 'dose_g' => $rounded, 'increment_g' => $increment, 'whole_gram' => empty($in['whole_gram']) ? 0 : 1,
            'equipment_ids' => json_encode($equipmentIds), 'forecast_min_c' => $minC, 'forecast_source' => $source,
            'forecast_retrieved_at' => $retrievedAt->setTimezone($utc)->format('Y-m-d H:i:s'), 'forecast_json' => json_encode($forecastDoc),
            'status' => $blocked ? 'planned' : 'prechecked', 'updated_at' => gmdate('Y-m-d H:i:s'),
        ], 'id = ?', [$treatmentId]);
        self::audit('treatment.prechecked', $treatmentId, ['volume_m3' => $volume, 'dose_rate_used' => $rate, 'rounded_dose_g' => $rounded, 'forecast_min_c' => $minC, 'blocked' => $blocked]);
        return ['volume_m3' => $volume, 'dose_rate_used' => $rate, 'dose_g' => $dose, 'rounded_dose_g' => $rounded, 'forecast_min_c' => $minC, 'blocked' => $blocked];
    }

    /** Perishables: core temperature of the commodity in a container, recorded before dosing. */
    public static function recordCoreTemp(string $treatmentId, string $containerId, float $tempC): void
    {
        $t = self::load($treatmentId);
        self::assertEditable($t);
        self::requireContainer($treatmentId, $containerId);
        self::plausibleTemp($tempC, 'Core temperature');
        ApDb::get()->update('ap_treatment_containers', ['core_temp_c' => $tempC], 'id = ?', [$containerId]);
        self::audit('treatment.core_temp', $treatmentId, ['container_id' => $containerId, 'core_temp_c' => $tempC]);
    }

    // ---- dosing --------------------------------------------------------------------------------------------------

    public static function recordDosing(string $treatmentId, DateTimeImmutable $injectedAt, float $gramsApplied, string $lotId): void
    {
        $db = ApDb::get();
        $t = self::load($treatmentId);
        self::assertEditable($t);
        if ($t['status'] !== 'prechecked') {
            throw new ApTreatmentError('Complete the pre-treatment check (with nothing blocking) before dosing.');
        }
        if (!is_finite($gramsApplied) || $gramsApplied <= 0) { throw new InvalidArgumentException('Grams applied must be greater than 0.'); }
        if (!$db->fetchOne('SELECT id FROM ap_stock_lots WHERE id = ?', [$lotId])) { throw new InvalidArgumentException('Unknown gas lot.'); }
        self::notInFuture($injectedAt, 'The injection time');
        if ($t['rof_type'] === 'perishable') {
            $min = (float)self::schedule($t)['min_temp_c'];
            foreach ($db->fetchAll('SELECT core_temp_c FROM ap_treatment_containers WHERE treatment_id = ?', [$treatmentId]) as $c) {
                if ($c['core_temp_c'] === null) { throw new ApTreatmentError('Record the core temperature of every container before dosing a perishable treatment.'); }
                if (!ApTemperature::coreTempOk((float)$c['core_temp_c'], $min)) {
                    throw new ApTreatmentError(sprintf('A core temperature of %.1f °C is below the schedule minimum of %.1f °C.', (float)$c['core_temp_c'], $min));
                }
            }
        }
        $db->beginTransaction();
        try {
            ApStock::consume($lotId, $treatmentId, $gramsApplied, (string)$t['technician_id']);
            $db->update('ap_treatments', ['injected_at' => self::utcString($injectedAt), 'applied_g' => $gramsApplied, 'lot_id' => $lotId, 'status' => 'dosed', 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$treatmentId]);
            $req = $db->fetchOne('SELECT status FROM ap_requests WHERE id = ?', [$t['request_id']]);
            if ($req && $req['status'] === 'scheduled') { ApRequests::advance((string)$t['request_id'], 'in_progress'); }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        self::audit('treatment.dosed', $treatmentId, ['injected_at' => self::utcString($injectedAt), 'grams_applied' => $gramsApplied, 'lot_id' => $lotId]);
    }

    // ---- readings ------------------------------------------------------------------------------------------------

    /**
     * Record one round of readings for every container. Idempotent on $clientUuid: a resubmission (for example after the
     * tablet lost signal) returns the stored result and creates nothing.
     * @param array<string,array<string,string|int|float>> $valuesByContainerLocation treatment-container id => location label => reading exactly as shown
     * @param string[] $fileIds photos of the instrument readings (at least one)
     * @return array<string,mixed> verdicts; 'replayed' is true when the set already existed
     */
    public static function recordReadingSet(string $treatmentId, string $clientUuid, string $stage, DateTimeImmutable $at, array $valuesByContainerLocation, array $fileIds, string $initials): array
    {
        $db = ApDb::get();
        $existing = $db->fetchOne('SELECT treatment_id, result_json FROM ap_reading_sets WHERE client_uuid = ?', [$clientUuid]);
        if ($existing) {
            return self::replay($existing, $treatmentId);
        }
        $t = self::load($treatmentId);
        self::assertEditable($t);
        if (!in_array($stage, self::STAGES, true)) { throw new InvalidArgumentException('Unknown reading stage.'); }
        $initials = trim($initials);
        if ($initials === '' || mb_strlen($initials) > 10) { throw new InvalidArgumentException('Enter your initials (up to 10 characters).'); }
        if ($stage === 'start_point' ? $t['status'] !== 'dosed' : $t['status'] !== 'exposure') {
            throw new ApTreatmentError($stage === 'start_point' ? 'Start-point readings are taken after dosing and before the exposure begins.' : 'Exposure readings are taken once the start point has been reached.');
        }
        $fileIds = array_values(array_unique(array_map('strval', $fileIds)));
        if (!$fileIds) { throw new InvalidArgumentException('Attach a photo of the instrument readings.'); }
        foreach ($fileIds as $fid) {
            if (!$db->fetchOne('SELECT id FROM ap_files WHERE id = ?', [$fid])) { throw new InvalidArgumentException('A photo was not uploaded correctly. Upload it again.'); }
        }
        $utc = new DateTimeZone('UTC');
        $injected = new DateTimeImmutable((string)$t['injected_at'], $utc);
        if ($at < $injected) { throw new InvalidArgumentException('Readings cannot be earlier than the injection time.'); }
        self::notInFuture($at, 'The reading time');

        // Parse everything before any write: one bad entry rejects the whole set and stores nothing.
        $tcIds = array_column($db->fetchAll('SELECT id FROM ap_treatment_containers WHERE treatment_id = ? ORDER BY id', [$treatmentId]), 'id');
        foreach (array_keys($valuesByContainerLocation) as $cid) {
            if (!in_array((string)$cid, $tcIds, true)) { throw new InvalidArgumentException('A container in the readings is not part of this treatment.'); }
        }
        $volume = (float)$t['volume_m3'];
        $required = ApReadings::requiredLocations((string)$t['rof_type'], $volume, 1, count($tcIds) > 1);
        $parsed = [];
        foreach ($tcIds as $cid) {
            $locs = $valuesByContainerLocation[$cid] ?? null;
            if (!is_array($locs) || !$locs) { throw new InvalidArgumentException('Enter readings for every container.'); }
            if (count($locs) < $required) { throw new InvalidArgumentException("Each container needs at least $required monitoring locations (this one has " . count($locs) . ').'); }
            foreach ($locs as $label => $raw) {
                if (trim((string)$label) === '') { throw new InvalidArgumentException('Every reading needs a location name.'); }
                $parsed[$cid][(string)$label] = ApReadings::parseReading((string)$raw);
            }
        }

        $sched = self::schedule($t);
        $rate = (float)$t['dose_rate_used'];
        $mb = (float)$t['mb_fraction'];
        if ($stage === 'start_point') {
            $minutes = ($at->getTimestamp() - $injected->getTimestamp()) / 60;
            $standard = ApStandards::startPointStandard($rate, $minutes, $sched['retention_class'] === 'eighty');
            if ($standard === null) { throw new InvalidArgumentException('Start-point readings can be taken from 15 minutes after injection.'); }
            $hours = null;
        } else {
            $startPoint = new DateTimeImmutable((string)$t['start_point_at'], $utc);
            if ($at < $startPoint) { throw new InvalidArgumentException('Readings cannot be earlier than the start point.'); }
            $hours = ($at->getTimestamp() - $startPoint->getTimestamp()) / 3600;
            $standard = ApStandards::standardAt($rate, $hours, (int)$t['whole_gram'] === 1);
        }
        try { $band = ApStandards::topUpBand($rate, $standard); } catch (InvalidArgumentException $e) { $band = null; }
        $flat = [];
        foreach ($parsed as $cid => $locs) { foreach ($locs as $label => $v) { $flat[$cid . '|' . $label] = $v; } }
        $eval = ApReadings::evaluateSet($flat, $standard, $band ? $band['min'] : INF);
        try {
            $eqPct = ApReadings::equilibriumPercent(array_values($flat));
        } catch (InvalidArgumentException $e) {
            $eqPct = null; // a zero reading: equilibrium cannot be calculated and is not met
        }
        $eqOk = $eqPct !== null && ApReadings::isEquilibrium(array_values($flat));
        $topUpDone = $t['topup_at'] !== null;
        $eqRequired = $stage === 'start_point' || !$topUpDone;

        $containers = [];
        $containerMeets = [];
        foreach ($parsed as $cid => $locs) {
            $meets = true;
            foreach ($locs as $label => $v) {
                $m = $v >= $standard - 1e-9;
                $meets = $meets && $m;
                $containers[$cid][$label] = ['value' => $v, 'meets' => $m];
            }
            $containerMeets[$cid] = $meets;
        }
        $verdict = [
            'stage' => $stage, 'standard' => $standard, 'hoursSinceStartPoint' => $hours, 'allMeet' => $eval['allMeet'], 'belowLocations' => $eval['belowLocations'],
            'lowest' => $eval['lowest'], 'equilibriumPct' => $eqPct, 'equilibriumOk' => $eqOk, 'equilibriumRequired' => $eqRequired,
            'containers' => $containers, 'containerMeets' => $containerMeets,
        ];
        if ($stage === 'start_point') {
            $verdict['startPointReached'] = $eval['allMeet'] && $eqOk;
            $verdict['additionalDoseG'] = null;
            if (!$eval['allMeet']) {
                try { $verdict['additionalDoseG'] = ApDose::additionalDoseBeforeStart($volume, $rate, $eval['lowest'], $mb); } catch (InvalidArgumentException $e) { $verdict['additionalDoseG'] = null; }
            }
        } else {
            $permit = ApResult::endOfExposureTopUp((int)$sched['topups_allowed'] === 1 && $band !== null, $topUpDone, $eval['lowest'], $standard, $band ? $band['min'] : INF);
            $allowed = $stage === 'end_exposure' && $permit['allowed'];
            $grams = 0.0;
            if ($allowed) { $grams = ApDose::topUpGrams($volume, $band['max'], $eval['lowest'], $mb); }
            $verdict['topUp'] = ['possible' => $eval['topUpPossible'], 'allowed' => $allowed, 'extensionHours' => $allowed ? $permit['extensionHours'] : 0.0, 'grams' => $grams];
        }

        $setId = ApId::uuid();
        $db->beginTransaction();
        try {
            $db->insert('ap_reading_sets', [
                'id' => $setId, 'treatment_id' => $treatmentId, 'client_uuid' => $clientUuid, 'stage' => $stage, 'taken_at' => self::utcString($at), 'initials' => $initials,
                'standard' => $standard, 'equilibrium_pct' => $eqPct, 'result_json' => json_encode($verdict), 'created_by' => (string)$t['technician_id'], 'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            foreach ($containers as $cid => $locs) {
                foreach ($locs as $label => $r) {
                    $db->insert('ap_readings', ['id' => ApId::uuid(), 'reading_set_id' => $setId, 'container_id' => $cid, 'location' => $label, 'value' => $r['value'], 'meets' => $r['meets'] ? 1 : 0]);
                }
            }
            foreach ($fileIds as $fid) {
                $db->insert('ap_reading_set_files', ['id' => ApId::uuid(), 'reading_set_id' => $setId, 'file_id' => $fid]);
            }
            if ($stage === 'start_point' && $verdict['startPointReached']) {
                $db->update('ap_treatments', ['start_point_at' => self::utcString($at), 'status' => 'exposure', 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$treatmentId]);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            $raced = $db->fetchOne('SELECT treatment_id, result_json FROM ap_reading_sets WHERE client_uuid = ?', [$clientUuid]);
            if ($raced) { return self::replay($raced, $treatmentId); } // two submissions of the same set arrived together
            throw $e;
        }
        self::audit('treatment.readings', $treatmentId, ['reading_set_id' => $setId, 'client_uuid' => $clientUuid, 'stage' => $stage, 'standard' => $standard, 'all_meet' => $eval['allMeet'], 'equilibrium_pct' => $eqPct]);
        return $verdict + ['reading_set_id' => $setId, 'replayed' => false];
    }

    /** The single permitted end-of-exposure top-up: records time and amount, consumes the gas, extends the exposure. */
    public static function recordTopUp(string $treatmentId, DateTimeImmutable $at, float $grams, string $lotId): void
    {
        $db = ApDb::get();
        $t = self::load($treatmentId);
        self::assertEditable($t);
        if ($t['status'] !== 'exposure') { throw new ApTreatmentError('A top-up can only be recorded during the exposure.'); }
        if ($t['topup_at'] !== null) { throw new ApTreatmentError('A top-up has already been done; only one is permitted.'); }
        if (!is_finite($grams) || $grams <= 0) { throw new InvalidArgumentException('Grams must be greater than 0.'); }
        if (!$db->fetchOne('SELECT id FROM ap_stock_lots WHERE id = ?', [$lotId])) { throw new InvalidArgumentException('Unknown gas lot.'); }
        self::notInFuture($at, 'The top-up time');
        $last = self::lastEndSet($treatmentId);
        if (!$last || empty($last['topUp']['allowed'])) {
            throw new ApTreatmentError('No end-of-exposure reading allows a top-up (the schedule must permit it and the lowest reading must be within the top-up band).');
        }
        $end = self::exposureEnd($t);
        if ($at < $end) { throw new InvalidArgumentException('A top-up is done at the end of the exposure period, not before.'); }
        $db->beginTransaction();
        try {
            ApStock::consume($lotId, $treatmentId, $grams, (string)$t['technician_id']);
            $db->update('ap_treatments', ['topup_at' => self::utcString($at), 'topup_g' => $grams, 'topup_extension_h' => (float)$last['topUp']['extensionHours'], 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$treatmentId]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        self::audit('treatment.topup', $treatmentId, ['at' => self::utcString($at), 'grams' => $grams, 'lot_id' => $lotId, 'extension_hours' => (float)$last['topUp']['extensionHours']]);
    }

    // ---- ventilation, TLV, close ---------------------------------------------------------------------------------

    public static function recordVentilation(string $treatmentId, DateTimeImmutable $startedAt): void
    {
        $t = self::load($treatmentId);
        self::assertEditable($t);
        if ($t['status'] !== 'exposure') { throw new ApTreatmentError('Ventilation follows the exposure period.'); }
        if ($startedAt < self::exposureEnd($t)) { throw new ApTreatmentError('Ventilation cannot start before the exposure period (including any extension) has finished.'); }
        self::notInFuture($startedAt, 'The ventilation time');
        ApDb::get()->update('ap_treatments', ['ventilation_at' => self::utcString($startedAt), 'status' => 'ventilating', 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$treatmentId]);
        self::audit('treatment.ventilation', $treatmentId, ['started_at' => self::utcString($startedAt)]);
    }

    /** TLV (5 ppm) clearance reading for one container after ventilation; may be re-recorded as aeration continues (each entry is audited). */
    public static function recordTlv(string $treatmentId, string $containerId, float $ppm, DateTimeImmutable $at): void
    {
        $t = self::load($treatmentId);
        self::assertEditable($t);
        if ($t['status'] !== 'ventilating') { throw new ApTreatmentError('TLV readings are taken during ventilation.'); }
        self::requireContainer($treatmentId, $containerId);
        if (!is_finite($ppm) || $ppm < 0) { throw new InvalidArgumentException('A TLV reading cannot be negative.'); }
        if ($at < new DateTimeImmutable((string)$t['ventilation_at'], new DateTimeZone('UTC'))) { throw new InvalidArgumentException('A TLV reading cannot be earlier than the start of ventilation.'); }
        self::notInFuture($at, 'The TLV reading time');
        ApDb::get()->update('ap_treatment_containers', ['tlv_ppm' => $ppm, 'tlv_at' => self::utcString($at)], 'id = ?', [$containerId]);
        self::audit('treatment.tlv', $treatmentId, ['container_id' => $containerId, 'ppm' => $ppm, 'at' => self::utcString($at)]);
    }

    /**
     * Decide each container's result: it passes when every location met the final standard and its TLV reading is 5 ppm or less.
     * @return array{overall:string,containers:array<string,string>}
     */
    public static function close(string $treatmentId): array
    {
        $db = ApDb::get();
        $t = self::load($treatmentId);
        self::assertEditable($t);
        if ($t['status'] !== 'ventilating') { throw new ApTreatmentError('A treatment is closed after ventilation, once and only once.'); }
        $tcs = $db->fetchAll('SELECT id, tlv_ppm FROM ap_treatment_containers WHERE treatment_id = ? ORDER BY id', [$treatmentId]);
        foreach ($tcs as $c) {
            if ($c['tlv_ppm'] === null) { throw new ApTreatmentError('Record a TLV reading for every container before closing.'); }
        }
        $last = self::lastEndSet($treatmentId);
        if (!$last) { throw new ApTreatmentError('Record the end-of-exposure readings before closing.'); }

        $sched = self::schedule($t);
        $standardFailure = false;
        $inputs = [];
        foreach ($tcs as $c) {
            $meetsStandard = !empty($last['containerMeets'][$c['id']]);
            $standardFailure = $standardFailure || !$meetsStandard;
            $inputs[] = ['id' => (string)$c['id'], 'meetsStandard' => $meetsStandard && ApReadings::tlvOk((float)$c['tlv_ppm'])];
        }
        // A top-up could still have rescued the shortfall only when the shortfall is in the readings (not the TLV).
        $topUpStillPossible = $standardFailure && $t['topup_at'] === null && (int)$sched['topups_allowed'] === 1 && !empty($last['topUp']['possible']);
        $targetInside = in_array($t['enclosure_type'], ['container', 'container_stack'], true);
        $res = ApResult::evaluate($inputs, $topUpStillPossible, $targetInside);

        $db->beginTransaction();
        try {
            foreach ($res['containers'] as $cid => $r) {
                $db->update('ap_treatment_containers', ['result' => $r], 'id = ?', [$cid]);
            }
            $db->update('ap_treatments', ['result' => $res['overall'], 'status' => 'closed', 'closed_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')], 'id = ?', [$treatmentId]);
            $open = $db->fetchOne("SELECT COUNT(*) AS n FROM ap_treatments WHERE request_id = ? AND status <> 'closed'", [$t['request_id']]);
            $left = $db->fetchOne('SELECT COUNT(*) AS n FROM ap_consignments c WHERE c.request_id = ? AND NOT EXISTS (SELECT 1 FROM ap_treatment_containers tc WHERE tc.consignment_id = c.id)', [$t['request_id']]);
            $req = $db->fetchOne('SELECT status FROM ap_requests WHERE id = ?', [$t['request_id']]);
            if ((int)$open['n'] === 0 && (int)$left['n'] === 0 && $req && $req['status'] === 'in_progress') { ApRequests::advance((string)$t['request_id'], 'completed'); }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }
        self::audit('treatment.closed', $treatmentId, ['overall' => $res['overall'], 'containers' => $res['containers']]);
        return $res;
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    /** @return array{0:float,1:string,2:DateTimeImmutable,3:array<string,mixed>} lowest temperature, source text, retrieved-at, stored document */
    private static function resolveForecast(array $f, DateTimeImmutable $start, float $exposureHours, DateTimeImmutable $now): array
    {
        $utc = new DateTimeZone('UTC');
        if (array_key_exists('manual_min_c', $f)) {
            if (!is_numeric($f['manual_min_c'])) { throw new InvalidArgumentException('Enter the forecast minimum temperature as a number.'); }
            $note = trim((string)($f['source_note'] ?? ''));
            if ($note === '') { throw new InvalidArgumentException('A manual forecast needs a note saying where it came from.'); }
            $min = (float)$f['manual_min_c'];
            self::plausibleTemp($min, 'Forecast temperature');
            $retrieved = !empty($f['retrieved_at']) ? new DateTimeImmutable((string)$f['retrieved_at'], $utc) : $now;
            return [$min, 'manual: ' . $note, $retrieved, ['source' => 'manual', 'note' => $note, 'min_c' => $min, 'retrieved_at' => $retrieved->setTimezone($utc)->format('Y-m-d H:i:s')]];
        }
        $hourly = $f['hourly'] ?? null;
        if (!is_array($hourly) || !$hourly || empty($f['retrieved_at'])) {
            throw new InvalidArgumentException('A forecast is required: fetch it, or enter a minimum temperature manually with a note.');
        }
        $w = ApTemperature::exposureWindow($start, $exposureHours);
        $min = ApTemperature::lowestForecast($hourly, $w['from'], $w['to']);
        $retrieved = new DateTimeImmutable((string)$f['retrieved_at'], $utc);
        $source = (string)($f['source'] ?? 'forecast');
        return [$min, $source, $retrieved, ['source' => $source, 'min_c' => $min, 'retrieved_at' => $retrieved->format('Y-m-d H:i:s'),
                'window' => ['from' => $w['from']->format('c'), 'to' => $w['to']->format('c')], 'hourly' => $hourly]];
    }

    /** @return array<string,mixed> */
    private static function lastEndSet(string $treatmentId): ?array
    {
        $r = ApDb::get()->fetchOne("SELECT result_json FROM ap_reading_sets WHERE treatment_id = ? AND stage = 'end_exposure' ORDER BY taken_at DESC, created_at DESC", [$treatmentId]);
        return $r ? json_decode((string)$r['result_json'], true) : null;
    }

    private static function exposureEnd(array $t): DateTimeImmutable
    {
        $hours = (float)self::schedule($t)['exposure_hours'] + (float)($t['topup_extension_h'] ?? 0);
        return (new DateTimeImmutable((string)$t['start_point_at'], new DateTimeZone('UTC')))->modify('+' . (int)round($hours * 60) . ' minutes');
    }

    private static function replay(array $row, string $treatmentId): array
    {
        if ($row['treatment_id'] !== $treatmentId) { throw new InvalidArgumentException('That submission id belongs to a different treatment.'); }
        return (json_decode((string)$row['result_json'], true) ?: []) + ['replayed' => true];
    }

    private static function load(string $id): array
    {
        $t = ApDb::get()->fetchOne('SELECT * FROM ap_treatments WHERE id = ?', [$id]);
        if (!$t) { throw new InvalidArgumentException('Unknown treatment.'); }
        return $t;
    }

    private static function schedule(array $t): array
    {
        $s = ApDb::get()->fetchOne('SELECT * FROM ap_schedules WHERE id = ?', [(string)$t['schedule_id']]);
        if (!$s) { throw new ApTreatmentError('The treatment has no schedule.'); }
        return $s;
    }

    private static function requireContainer(string $treatmentId, string $containerId): void
    {
        if (!ApDb::get()->fetchOne('SELECT id FROM ap_treatment_containers WHERE id = ? AND treatment_id = ?', [$containerId, $treatmentId])) {
            throw new InvalidArgumentException('That container is not part of this treatment.');
        }
    }

    private static function assertEditable(array $t): void
    {
        if (!empty($t['locked_at'])) { throw new ApLockedException('This ROF is signed and read-only. Corrections are made as a new version.'); }
    }

    private static function positiveNumber($v, string $what): float
    {
        if (!is_numeric($v) || !is_finite((float)$v) || (float)$v <= 0) { throw new InvalidArgumentException($what . ' must be a number greater than 0.'); }
        return (float)$v;
    }

    private static function plausibleTemp(float $c, string $what): void
    {
        if (!is_finite($c) || $c < ApTemperature::MIN_PLAUSIBLE_C || $c > ApTemperature::MAX_PLAUSIBLE_C) {
            throw new InvalidArgumentException(sprintf('%s of %s °C is not plausible.', $what, (string)$c));
        }
    }

    private static function now(): DateTimeImmutable
    {
        return self::$clock !== null ? (self::$clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function notInFuture(DateTimeImmutable $when, string $what): void
    {
        if ($when > self::now()->modify('+' . self::CLOCK_SKEW_MINUTES . ' minutes')) {
            throw new InvalidArgumentException($what . ' is in the future. Check the date and time on the tablet.');
        }
    }

    private static function utcString(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Audit hook shared with ApSigning: an injected auditor (tests) or ApAudit::event once it exists. */
    public static function audit(string $action, string $treatmentId, array $details): void
    {
        if (self::$auditor !== null) {
            (self::$auditor)($action, $treatmentId, $details);
        } elseif (class_exists('ApAudit')) {
            ApAudit::event($action, $treatmentId, $details);
        }
    }
}
