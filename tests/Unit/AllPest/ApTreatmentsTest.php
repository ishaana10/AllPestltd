<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApTechnicians', 'ApEquipment', 'ApSchedules', 'ApStock',
          'ApStandards', 'ApDose', 'ApReadings', 'ApTemperature', 'ApResult', 'ApTreatments'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApTreatmentsTest extends TestCase
{
    private ApTestDb $db;
    private string $reqId;
    private string $tid;
    /** @var string[] treatment container ids */
    private array $tcs;
    private string $eq;
    private string $lot;
    private string $tech;
    private string $fileId;
    private array $audit = [];

    private function utc(string $s): DateTimeImmutable { return new DateTimeImmutable($s, new DateTimeZone('UTC')); }

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        ApRequests::setMailer(function (): void {});
        ApTreatments::setClock(fn () => $this->utc('2026-12-12 00:00:00'));
        $this->audit = [];
        ApTreatments::setAuditor(function (string $action, string $tid, array $d): void { $this->audit[] = [$action, $tid]; });

        $cons = array_map(fn ($i) => ['container_no' => "MSKU000000$i"], [1, 2, 3, 4, 5]);
        $r = ApRequests::submitPublic(['customer_name' => 'PT', 'customer_email' => 'b@example.com', 'site_address' => 'Wharf', 'client_token' => bin2hex(random_bytes(5)), 'consignments' => $cons], [], '1.1.1.1');
        $this->reqId = $r['request_id'];
        $consIds = array_column($this->db->fetchAll('SELECT id FROM ap_consignments WHERE request_id = ? ORDER BY container_no', [$this->reqId]), 'id');
        $this->tech = ApTechnicians::create(['full_name' => 'T One', 'registration_no' => 'R1', 'registration_expiry' => '2099-12-31']);
        $officer = ApTechnicians::addOfficer('J Naidu', 'Biosecurity Officer');
        $sched = ApSchedules::save(['name' => 'General 48', 'treatment_type' => 'general', 'dose_rate' => 48, 'exposure_hours' => 24, 'min_temp_c' => 21, 'compensation_allowed' => 1, 'topups_allowed' => 1, 'retention_class' => 'standard']);
        ApRequests::confirm($this->reqId, [], 'a');
        $this->tid = ApRequests::createTreatment($this->reqId, $consIds, [
            'scheduled_start' => '2026-12-10 06:00:00', 'site_lat' => -17.6, 'site_lng' => 177.45, 'technician_id' => $this->tech, 'officer_id' => $officer,
            'schedule_id' => $sched, 'rof_type' => 'general', 'enclosure_type' => 'container', 'fumigation_type' => 'ambient',
        ]);
        $this->tcs = array_column($this->db->fetchAll('SELECT tc.id FROM ap_treatment_containers tc JOIN ap_consignments c ON c.id = tc.consignment_id WHERE tc.treatment_id = ? ORDER BY c.container_no', [$this->tid]), 'id');
        $this->eq = ApId::uuid();
        $this->db->insert('ap_equipment', ['id' => $this->eq, 'kind' => 'detector', 'name' => 'Det 1', 'serial_no' => 'ABC-12345']);
        $this->lot = ApStock::createLot('MB-1', '', 0.98);
        ApStock::receive($this->lot, 100000, 'u');
        $this->fileId = ApId::uuid();
        $this->db->insert('ap_files', ['id' => $this->fileId, 'purpose' => 'reading_photo', 'bytes' => 10]);
    }

    protected function tearDown(): void
    {
        ApTreatments::setClock(null);
        ApTreatments::setAuditor(null);
        ApRequests::setMailer(null);
    }

    private function in(array $over = []): array
    {
        return array_merge([
            'height_m' => 3, 'depth_m' => 2.5, 'length_m' => 10, 'equipment_ids' => [$this->eq], 'chloropicrin' => true, 'increment' => 100,
            'forecast' => ['manual_min_c' => 25.0, 'source_note' => 'BoM phone call'],
        ], $over);
    }

    private function precheck(array $over = []): array
    {
        return ApTreatments::preCheck($this->tid, $this->in($over), $this->utc('2026-12-09 10:00:00'));
    }

    /** Dose and reach a started exposure (start point 06:30, injection 06:00). */
    private function started(): void
    {
        $this->precheck();
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        $r = $this->set('sp1', 'start_point', '2026-12-10 06:30:00', $this->all('45', '45', '45'));
        $this->assertTrue($r['startPointReached']);
    }

    /** @return array<string,array<string,string>> every container gets the same three readings */
    private function all(string $a, string $b, string $c): array
    {
        $out = [];
        foreach ($this->tcs as $id) { $out[$id] = ['top' => $a, 'middle' => $b, 'bottom' => $c]; }
        return $out;
    }

    private function set(string $uuid, string $stage, string $at, array $values, ?array $files = null, string $initials = 'TO'): array
    {
        return ApTreatments::recordReadingSet($this->tid, $uuid, $stage, $this->utc($at), $values, $files ?? [$this->fileId], $initials);
    }

    private function n(string $table): int { return (int)$this->db->fetchOne("SELECT COUNT(*) AS n FROM $table")['n']; }

    // ---- preCheck -------------------------------------------------------------------------------------------------

    public function testPreCheckRoundsDoseUpToTheIncrement(): void
    {
        $r = $this->precheck();
        $this->assertEqualsWithDelta(75.0, $r['volume_m3'], 1e-9);
        $this->assertEqualsWithDelta(48.0, $r['dose_rate_used'], 1e-9);
        $this->assertEqualsWithDelta(3673.47, $r['dose_g'], 0.01);
        $this->assertEquals(3700.0, $r['rounded_dose_g']);
        $this->assertSame([], $r['blocked']);
        $t = $this->db->fetchOne('SELECT status, volume_m3, forecast_min_c FROM ap_treatments WHERE id = ?', [$this->tid]);
        $this->assertSame('prechecked', $t['status']);
        $this->assertEquals(25.0, (float)$t['forecast_min_c']);
    }

    public function testColdForecastAddsCompensationWhenSchedulePermits(): void
    {
        $r = $this->precheck(['forecast' => ['manual_min_c' => 12.0, 'source_note' => 'x']]);
        $this->assertEqualsWithDelta(64.0, $r['dose_rate_used'], 1e-9); // 21 - 12 = 9 C -> two 5 C steps -> +16
        $this->assertEquals(4900.0, $r['rounded_dose_g']);
    }

    public function testAmbientAtTenDegreesIsBlockedButControlledIsNot(): void
    {
        $r = $this->precheck(['forecast' => ['manual_min_c' => 10.0, 'source_note' => 'x']]);
        $this->assertNotEmpty($r['blocked']);
        $this->assertStringContainsString('10', implode(' ', $r['blocked']));
        $this->assertSame('planned', $this->db->fetchOne('SELECT status FROM ap_treatments WHERE id = ?', [$this->tid])['status']);
        $this->db->exec("UPDATE ap_treatments SET fumigation_type = 'controlled' WHERE id = '{$this->tid}'");
        $this->assertSame([], $this->precheck(['forecast' => ['manual_min_c' => 10.0, 'source_note' => 'x']])['blocked']);
    }

    public function testForecastFromTheClientTakesTheLowestInTheExposureWindow(): void
    {
        $hourly = [];
        for ($h = 0; $h < 72; $h++) {
            $t = (new DateTimeImmutable('2026-12-09 00:00:00', new DateTimeZone('Pacific/Fiji')))->modify("+$h hours");
            $hourly[] = ['time' => $t->format('Y-m-d\TH:i'), 'temp' => 24.0 + ($h === 48 ? -3.5 : 0)];
        }
        $r = $this->precheck(['forecast' => ['source' => 'open-meteo', 'retrieved_at' => '2026-12-09 08:00:00', 'hourly' => $hourly]]);
        $this->assertEquals(20.5, $r['forecast_min_c']);
        $this->assertEqualsWithDelta(48.0 + 8.0, $r['dose_rate_used'], 1e-9);
    }

    public function testStaleForecastIsBlocked(): void
    {
        $r = $this->precheck(['forecast' => ['manual_min_c' => 25.0, 'source_note' => 'old', 'retrieved_at' => '2026-12-07 08:00:00']]);
        $this->assertStringContainsString('forecast', strtolower(implode(' ', $r['blocked'])));
    }

    public function testExpiredTechnicianAndUnselectableEquipmentAreBlocked(): void
    {
        $this->db->exec("UPDATE ap_technicians SET registration_expiry = '2020-01-01'");
        $this->db->exec("UPDATE ap_equipment SET status = 'discarded'");
        $r = $this->precheck();
        $this->assertGreaterThanOrEqual(2, count($r['blocked']));
    }

    public function testImpossibleDimensionsAndForecastAreRejected(): void
    {
        foreach ([['height_m' => 0], ['depth_m' => -1], ['length_m' => 'abc'], ['height_m' => 10, 'depth_m' => 10, 'length_m' => 11], ['forecast' => ['manual_min_c' => -60.0, 'source_note' => 'x']], ['increment' => 0]] as $bad) {
            try { $this->precheck($bad); $this->fail('expected rejection for ' . json_encode($bad)); } catch (InvalidArgumentException $e) { $this->assertTrue(true); }
        }
        $this->assertSame('planned', $this->db->fetchOne('SELECT status FROM ap_treatments WHERE id = ?', [$this->tid])['status']);
    }

    // ---- dosing ---------------------------------------------------------------------------------------------------

    public function testDosingRequiresAPassedPreCheck(): void
    {
        $this->expectException(ApTreatmentError::class);
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
    }

    public function testDosingConsumesStockAndStartsTheRequest(): void
    {
        $this->precheck();
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        $this->assertEquals(100000 - 3700.0, ApStock::balance($this->lot));
        $t = $this->db->fetchOne('SELECT status, injected_at, applied_g FROM ap_treatments WHERE id = ?', [$this->tid]);
        $this->assertSame('dosed', $t['status']);
        $this->assertSame('2026-12-10 06:00:00', $t['injected_at']);
        $this->assertSame('in_progress', $this->db->fetchOne('SELECT status FROM ap_requests WHERE id = ?', [$this->reqId])['status']);
        $this->assertContains('treatment.dosed', array_column($this->audit, 0));
    }

    public function testPerishableNeedsCoreTemperaturesBeforeDosing(): void
    {
        $per = ApSchedules::save(['name' => 'Per', 'treatment_type' => 'perishable', 'dose_rate' => 48, 'exposure_hours' => 2, 'min_temp_c' => 10, 'retention_class' => 'eighty']);
        $this->db->exec("UPDATE ap_treatments SET rof_type = 'perishable', schedule_id = '$per'");
        $this->precheck();
        try {
            ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
            $this->fail('expected refusal');
        } catch (ApTreatmentError $e) {
            $this->assertStringContainsString('core', strtolower($e->getMessage()));
        }
        foreach ($this->tcs as $id) { ApTreatments::recordCoreTemp($this->tid, $id, 12.0); }
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        $this->assertSame('dosed', $this->db->fetchOne('SELECT status FROM ap_treatments WHERE id = ?', [$this->tid])['status']);
    }

    public function testPerishableCoreTemperatureBelowScheduleMinimumRefusesDosing(): void
    {
        $per = ApSchedules::save(['name' => 'Per', 'treatment_type' => 'perishable', 'dose_rate' => 48, 'exposure_hours' => 2, 'min_temp_c' => 10, 'retention_class' => 'eighty']);
        $this->db->exec("UPDATE ap_treatments SET rof_type = 'perishable', schedule_id = '$per'");
        $this->precheck();
        foreach ($this->tcs as $id) { ApTreatments::recordCoreTemp($this->tid, $id, 8.0); }
        $this->expectException(ApTreatmentError::class);
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
    }

    // ---- reading sets ---------------------------------------------------------------------------------------------

    public function testBadReadingEntriesAreRejectedAndNothingIsStored(): void
    {
        $this->precheck();
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        foreach (['>4', '4 ppm', '', '-1', '4,3'] as $i => $bad) {
            try {
                $this->set("bad$i", 'start_point', '2026-12-10 06:30:00', $this->all('45', '45', $bad));
                $this->fail("expected rejection of '$bad'");
            } catch (InvalidArgumentException $e) {
                $this->assertSame(0, $this->n('ap_reading_sets'));
                $this->assertSame(0, $this->n('ap_readings'));
            }
        }
    }

    public function testReadingSetWithoutAPhotoIsRejected(): void
    {
        $this->precheck();
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        $this->expectException(InvalidArgumentException::class);
        $this->set('nophoto', 'start_point', '2026-12-10 06:30:00', $this->all('45', '45', '45'), []);
    }

    public function testUnknownPhotoIdIsRejected(): void
    {
        $this->precheck();
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        $this->expectException(InvalidArgumentException::class);
        $this->set('badphoto', 'start_point', '2026-12-10 06:30:00', $this->all('45', '45', '45'), [ApId::uuid()]);
    }

    public function testResubmittingTheSameClientUuidReturnsTheStoredResultWithoutDuplicates(): void
    {
        $this->precheck();
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        $a = $this->set('same-uuid', 'start_point', '2026-12-10 06:30:00', $this->all('45', '45', '45'));
        $b = $this->set('same-uuid', 'start_point', '2026-12-10 06:30:00', $this->all('45', '45', '45'));
        $this->assertSame(1, $this->n('ap_reading_sets'));
        $this->assertSame(15, $this->n('ap_readings'));
        $this->assertTrue($b['replayed']);
        $this->assertSame($a['startPointReached'], $b['startPointReached']);
        $this->assertSame($a['standard'], $b['standard']);
    }

    public function testReadingBeforeInjectionOrInTheFutureIsRejected(): void
    {
        $this->precheck();
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        foreach (['2026-12-10 05:59:00', '2026-12-13 00:00:00'] as $i => $at) {
            try { $this->set("t$i", 'start_point', $at, $this->all('45', '45', '45')); $this->fail("expected rejection at $at"); } catch (InvalidArgumentException $e) { $this->assertTrue(true); }
        }
        $this->assertSame(0, $this->n('ap_reading_sets'));
    }

    public function testStartPointBeforeFifteenMinutesIsRejected(): void
    {
        $this->precheck();
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        $this->expectException(InvalidArgumentException::class);
        $this->set('early', 'start_point', '2026-12-10 06:10:00', $this->all('45', '45', '45'));
    }

    public function testEveryContainerNeedsEnoughLocations(): void
    {
        $this->precheck();
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        $v = $this->all('45', '45', '45');
        $v[$this->tcs[2]] = ['top' => '45'];                 // only one location for a container in a stack
        $this->expectException(InvalidArgumentException::class);
        $this->set('few', 'start_point', '2026-12-10 06:30:00', $v);
    }

    public function testMissingContainerIsRejected(): void
    {
        $this->precheck();
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        $v = $this->all('45', '45', '45');
        unset($v[$this->tcs[4]]);
        $this->expectException(InvalidArgumentException::class);
        $this->set('missing', 'start_point', '2026-12-10 06:30:00', $v);
    }

    public function testStartPointReachedOnlyWhenAllMeetAndEquilibriumIsWithinFifteenPercent(): void
    {
        $this->precheck();
        ApTreatments::recordDosing($this->tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $this->lot);
        // 30 min after injection: 85 % of 48 = 40.8
        $low = $this->set('a', 'start_point', '2026-12-10 06:30:00', $this->all('45', '45', '40'));
        $this->assertEqualsWithDelta(40.8, $low['standard'], 0.001);
        $this->assertFalse($low['startPointReached']);
        $this->assertGreaterThan(0.0, $low['additionalDoseG']);
        $uneven = $this->set('b', 'start_point', '2026-12-10 06:31:00', $this->all('60', '50', '45')); // all meet, spread 33 %
        $this->assertFalse($uneven['startPointReached']);
        $this->assertTrue($uneven['allMeet']);
        $this->assertFalse($uneven['equilibriumOk']);
        $this->assertNull($this->db->fetchOne('SELECT start_point_at FROM ap_treatments WHERE id = ?', [$this->tid])['start_point_at']);
        $ok = $this->set('c', 'start_point', '2026-12-10 06:32:00', $this->all('45', '45', '44'));
        $this->assertTrue($ok['startPointReached']);
        $t = $this->db->fetchOne('SELECT status, start_point_at FROM ap_treatments WHERE id = ?', [$this->tid]);
        $this->assertSame('exposure', $t['status']);
        $this->assertSame('2026-12-10 06:32:00', $t['start_point_at']);
    }

    public function testExposureReadingUsesTheTableStandardAndFlagsLocations(): void
    {
        $this->started();                                    // start point 06:30; initial dose 48
        $r = $this->set('x24', 'end_exposure', '2026-12-11 06:30:00', $this->all('15', '15', '14'));
        $this->assertEqualsWithDelta(14.4, $r['standard'], 0.001);
        $this->assertFalse($r['allMeet']);
        $this->assertCount(5, $r['belowLocations']);
        $this->assertTrue($r['topUp']['possible']);          // 14 >= 9.4
    }

    public function testEndOfExposureTopUpAmountAndOnlyOnce(): void
    {
        $this->started();
        $r = $this->set('x24', 'end_exposure', '2026-12-11 06:30:00', $this->all('13', '12', '12.5'));
        $this->assertTrue($r['topUp']['allowed']);
        $this->assertEquals(4.0, $r['topUp']['extensionHours']);
        $this->assertEqualsWithDelta(75 * (19.4 - 12.0) / 0.98, $r['topUp']['grams'], 0.01);
        ApTreatments::recordTopUp($this->tid, $this->utc('2026-12-11 06:45:00'), 570.0, $this->lot);
        $t = $this->db->fetchOne('SELECT topup_at, topup_g, topup_extension_h FROM ap_treatments WHERE id = ?', [$this->tid]);
        $this->assertSame('2026-12-11 06:45:00', $t['topup_at']);
        $this->assertEquals(570.0, (float)$t['topup_g']);
        $this->assertEquals(100000 - 3700.0 - 570.0, ApStock::balance($this->lot));
        $again = $this->set('x24b', 'end_exposure', '2026-12-11 10:45:00', $this->all('13', '12', '12.5'));
        $this->assertFalse($again['topUp']['allowed']);
        try { ApTreatments::recordTopUp($this->tid, $this->utc('2026-12-11 11:00:00'), 100.0, $this->lot); $this->fail('second top-up must be refused'); } catch (ApTreatmentError $e) { $this->assertTrue(true); }
    }

    public function testTopUpRemovesTheEquilibriumRequirementAtTheFinalReading(): void
    {
        $this->started();
        $this->set('x24', 'end_exposure', '2026-12-11 06:30:00', $this->all('13', '12', '12.5'));
        ApTreatments::recordTopUp($this->tid, $this->utc('2026-12-11 06:45:00'), 570.0, $this->lot);
        $r = $this->set('x28', 'end_exposure', '2026-12-11 10:45:00', $this->all('20', '16', '15'));
        $this->assertFalse($r['equilibriumRequired']);
        $this->assertTrue($r['allMeet']);                    // 28 h standard is 29.15 % of 48 = 13.99
    }

    // ---- ventilation, TLV, close ----------------------------------------------------------------------------------

    private function finish(array $finalValues, float $tlv = 4.0, array $tlvOverride = []): array
    {
        $this->started();
        $this->set('x24', 'end_exposure', '2026-12-11 06:30:00', $finalValues);
        ApTreatments::recordVentilation($this->tid, $this->utc('2026-12-11 07:00:00'));
        foreach ($this->tcs as $id) { ApTreatments::recordTlv($this->tid, $id, $tlvOverride[$id] ?? $tlv, $this->utc('2026-12-11 09:00:00')); }
        return ApTreatments::close($this->tid);
    }

    public function testAllContainersPassClosesAsPass(): void
    {
        $r = $this->finish($this->all('16', '15', '15'));
        $this->assertSame('pass', $r['overall']);
        $this->assertSame('closed', $this->db->fetchOne('SELECT status FROM ap_treatments WHERE id = ?', [$this->tid])['status']);
        $this->assertSame(5, (int)$this->db->fetchOne("SELECT COUNT(*) AS n FROM ap_treatment_containers WHERE result = 'pass'")['n']);
        $this->assertSame('completed', $this->db->fetchOne('SELECT status FROM ap_requests WHERE id = ?', [$this->reqId])['status']);
    }

    public function testTlvOverFivePpmFailsThatContainer(): void
    {
        $r = $this->finish($this->all('16', '15', '15'), 4.0, [$this->tcs[1] => 5.1]);
        $this->assertSame('fail', $r['containers'][$this->tcs[1]]);
        $this->assertSame('partial', $r['overall']);
    }

    public function testTlvExactlyFivePpmPasses(): void
    {
        $r = $this->finish($this->all('16', '15', '15'), 5.0);
        $this->assertSame('pass', $r['overall']);
    }

    public function testOneFailingContainerWithNoTopUpPossibleClosesAsPartial(): void
    {
        $v = $this->all('16', '15', '15');
        $v[$this->tcs[2]] = ['top' => '5', 'middle' => '5', 'bottom' => '5'];    // below the 9.4 minimum for a top-up
        $r = $this->finish($v);
        $this->assertSame('partial', $r['overall']);
        $this->assertSame('fail', $r['containers'][$this->tcs[2]]);
        $this->assertSame('pass', $r['containers'][$this->tcs[0]]);
    }

    public function testShortfallThatCouldStillHaveBeenToppedUpFailsEverything(): void
    {
        $v = $this->all('16', '15', '15');
        $v[$this->tcs[2]] = ['top' => '12', 'middle' => '12', 'bottom' => '12'];  // below 14.4 but >= 9.4: a top-up was possible
        $r = $this->finish($v);
        $this->assertSame('fail', $r['overall']);
    }

    public function testCloseNeedsTlvForEveryContainerAndAFinalReading(): void
    {
        $this->started();
        try { ApTreatments::close($this->tid); $this->fail('no ventilation yet'); } catch (ApTreatmentError $e) { $this->assertTrue(true); }
        $this->set('x24', 'end_exposure', '2026-12-11 06:30:00', $this->all('16', '15', '15'));
        ApTreatments::recordVentilation($this->tid, $this->utc('2026-12-11 07:00:00'));
        ApTreatments::recordTlv($this->tid, $this->tcs[0], 3.0, $this->utc('2026-12-11 09:00:00'));
        $this->expectException(ApTreatmentError::class);
        ApTreatments::close($this->tid);
    }

    public function testVentilationBeforeTheExposureHasRunIsRefused(): void
    {
        $this->started();
        $this->expectException(ApTreatmentError::class);
        ApTreatments::recordVentilation($this->tid, $this->utc('2026-12-10 20:00:00'));
    }

    public function testClosingTwiceDoesNotDuplicateOrChangeTheResult(): void
    {
        $this->finish($this->all('16', '15', '15'));
        $this->expectException(ApTreatmentError::class);
        ApTreatments::close($this->tid);
    }

    // ---- lock -----------------------------------------------------------------------------------------------------

    public function testLockedTreatmentRejectsWrites(): void
    {
        $this->started();
        $this->db->exec("UPDATE ap_treatments SET locked_at = '2026-12-11 08:00:00' WHERE id = '{$this->tid}'");
        try { $this->set('late', 'end_exposure', '2026-12-11 06:30:00', $this->all('16', '15', '15')); $this->fail('locked'); } catch (ApLockedException $e) { $this->assertTrue(true); }
        try { ApTreatments::recordVentilation($this->tid, $this->utc('2026-12-11 07:00:00')); $this->fail('locked'); } catch (ApLockedException $e) { $this->assertTrue(true); }
        try { ApTreatments::recordTopUp($this->tid, $this->utc('2026-12-11 06:45:00'), 100.0, $this->lot); $this->fail('locked'); } catch (ApLockedException $e) { $this->assertTrue(true); }
    }

    public function testEveryWriteIsAudited(): void
    {
        $this->started();
        $this->set('x24', 'end_exposure', '2026-12-11 06:30:00', $this->all('16', '15', '15'));
        ApTreatments::recordVentilation($this->tid, $this->utc('2026-12-11 07:00:00'));
        $actions = array_column($this->audit, 0);
        foreach (['treatment.prechecked', 'treatment.dosed', 'treatment.readings', 'treatment.ventilation'] as $a) { $this->assertContains($a, $actions); }
        foreach ($this->audit as [$a, $tid]) { $this->assertSame($this->tid, $tid); }
    }
}
