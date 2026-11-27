<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApTechnicians', 'ApEquipment', 'ApSchedules', 'ApStock',
          'ApStandards', 'ApDose', 'ApReadings', 'ApTemperature', 'ApResult', 'ApTreatments', 'ApRof'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApRofTest extends TestCase
{
    private ApTestDb $db;
    private string $tid;
    private string $ptid;
    private array $audit = [];

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        ApRequests::setMailer(function (): void {});
        ApTreatments::setAuditor(function (string $a, string $t, array $d): void { $this->audit[] = $a; });
        $r = ApRequests::submitPublic(['customer_name' => 'PT Pacific', 'customer_email' => 'b@example.com', 'site_address' => 'Queens Wharf', 'client_token' => bin2hex(random_bytes(5)),
            'consignments' => [['container_no' => 'MSKU0000001', 'seal_no' => 'S1']]], [], '1.1.1.1');
        $cid = $this->db->fetchOne('SELECT id FROM ap_consignments WHERE request_id = ?', [$r['request_id']])['id'];
        $tech = ApTechnicians::create(['full_name' => 'T One', 'registration_no' => 'R1', 'registration_expiry' => '2099-12-31', 'accreditation_no' => 'ACC-9']);
        $officer = ApTechnicians::addOfficer('J Naidu', 'Biosecurity Officer');
        $sched = ApSchedules::save(['name' => 'General 48', 'treatment_type' => 'general', 'dose_rate' => 48, 'exposure_hours' => 24, 'min_temp_c' => 21, 'compensation_allowed' => 1, 'topups_allowed' => 1, 'retention_class' => 'standard']);
        $psched = ApSchedules::save(['name' => 'Perishable 2', 'treatment_type' => 'perishable', 'dose_rate' => 32, 'exposure_hours' => 2, 'min_temp_c' => 21, 'compensation_allowed' => 0, 'topups_allowed' => 0, 'retention_class' => 'standard']);
        ApRequests::confirm($r['request_id'], [], 'a');
        $mk = fn (string $type) => ApRequests::createTreatment($r['request_id'], [$cid], ['scheduled_start' => '2026-12-10 06:00:00', 'technician_id' => $tech, 'officer_id' => $officer, 'schedule_id' => $sched, 'rof_type' => $type, 'enclosure_type' => 'container', 'fumigation_type' => 'ambient']);
        $this->tid = $mk('general');
        // a second treatment on the same container is refused by the engine, so the perishable one gets its own request
        $r2 = ApRequests::submitPublic(['customer_name' => 'Fruit Co', 'customer_email' => 'f@example.com', 'site_address' => 'Shed 4', 'client_token' => bin2hex(random_bytes(5)),
            'consignments' => [['container_no' => 'MSKU0000002']]], [], '1.1.1.2');
        $cid2 = $this->db->fetchOne('SELECT id FROM ap_consignments WHERE request_id = ?', [$r2['request_id']])['id'];
        ApRequests::confirm($r2['request_id'], [], 'a');
        $this->ptid = ApRequests::createTreatment($r2['request_id'], [$cid2], ['scheduled_start' => '2026-12-10 06:00:00', 'technician_id' => $tech, 'officer_id' => $officer, 'schedule_id' => $psched, 'rof_type' => 'perishable', 'enclosure_type' => 'container', 'fumigation_type' => 'ambient']);
    }

    protected function tearDown(): void { ApTreatments::setAuditor(null); }

    public function testDetailsAreSavedAndAppearInTheDocument(): void
    {
        $f = ApRof::saveDetails($this->tid, ['suitable' => '1', 'heaters_used' => '0', 'target_of_fumigation' => ['commodity', 'container'], 'loc_street' => ' 1 Wharf Rd ', 'loc_suburb' => 'Suva',
            'loc_country' => 'Fiji', 'loc_postcode' => '0000', 'consignment_description' => 'Steel pipe', 'min_enclosure_temp_c' => '24.5'], 'u1');
        $this->assertContains('suitable', $f);
        $d = ApRof::document($this->tid);
        $this->assertSame('general', $d['variant']);
        $this->assertSame('T One', $d['A']['full_name']);
        $this->assertSame('ACC-9', $d['A']['accreditation_no']);
        $this->assertSame('1 Wharf Rd', $d['B']['street']);
        $this->assertSame(['commodity', 'container'], $d['B']['targets']);
        $this->assertSame(1, $d['C']['suitable']);
        $this->assertSame(0, $d['C']['heaters_used']);
        $this->assertSame('24.5', (string)$d['C']['min_enclosure_temp_c']);
        $this->assertSame('MSKU0000001', $d['B']['containers'][0]['container_no']);
        $this->assertSame(48.0, (float)$d['C']['schedule']['dose_rate']);
        $this->assertContains('treatment.rof_details', $this->audit);
    }

    public function testPortOfLoadingIsStoredOnTheTreatmentsConsignments(): void
    {
        ApRof::saveDetails($this->tid, ['port_of_loading' => ' Auckland ']);
        $this->assertSame('Auckland', ApRof::document($this->tid)['B']['port_of_loading']);
        $this->assertSame('Auckland', $this->db->fetchOne("SELECT port_of_loading AS p FROM ap_consignments WHERE container_no = 'MSKU0000001'")['p']);
        $this->assertSame(null, $this->db->fetchOne("SELECT port_of_loading AS p FROM ap_consignments WHERE container_no = 'MSKU0000002'")['p']);
        ApRof::saveDetails($this->tid, ['port_of_loading' => '']);
        $this->assertSame('', ApRof::document($this->tid)['B']['port_of_loading']);
    }

    public function testNotSuitableNeedsARemedialAction(): void
    {
        try { ApRof::saveDetails($this->tid, ['suitable' => '0']); $this->fail('expected refusal'); } catch (InvalidArgumentException $e) { $this->assertStringContainsString('remedial action', $e->getMessage()); }
        ApRof::saveDetails($this->tid, ['suitable' => '0', 'remedial_action' => 'Unwrapped pallets']);
        $this->assertSame(0, ApRof::document($this->tid)['C']['suitable']);
    }

    public function testOtherTargetAndEnclosureMustBeDescribed(): void
    {
        try { ApRof::saveDetails($this->tid, ['target_of_fumigation' => 'other']); $this->fail('expected refusal'); } catch (InvalidArgumentException $e) { $this->assertStringContainsString('other target', $e->getMessage()); }
        try { ApRof::saveDetails($this->tid, ['target_of_fumigation' => 'pallets']); $this->fail('expected refusal'); } catch (InvalidArgumentException $e) { $this->assertStringContainsString('Target of fumigation', $e->getMessage()); }
    }

    public function testLoadFactorAndRangesAreChecked(): void
    {
        try { ApRof::saveDetails($this->ptid, ['load_factor_max' => '80', 'load_factor_est' => '90']); $this->fail('expected refusal'); } catch (InvalidArgumentException $e) { $this->assertStringContainsString('above the maximum', $e->getMessage()); }
        try { ApRof::saveDetails($this->ptid, ['retention_pct' => '150']); $this->fail('expected refusal'); } catch (InvalidArgumentException $e) { $this->assertStringContainsString('Retention rate', $e->getMessage()); }
        try { ApRof::saveDetails($this->ptid, ['probe_location' => 'roof']); $this->fail('expected refusal'); } catch (InvalidArgumentException $e) { $this->assertStringContainsString('probe location', $e->getMessage()); }
        ApRof::saveDetails($this->ptid, ['load_factor_max' => '80', 'load_factor_est' => '60', 'probe_location' => 'pulp', 'retention_pct' => '50', 'ct_product' => '']);
        $c = ApRof::document($this->ptid)['C'];
        $this->assertSame('pulp', $c['probe_location']);
        $this->assertSame('60.0', number_format((float)$c['load_factor_est'], 1));
    }

    public function testSignedRecordIsReadOnly(): void
    {
        $this->db->update('ap_treatments', ['locked_at' => '2026-12-11 00:00:00'], 'id = ?', [$this->tid]);
        $this->expectException(ApLockedException::class);
        ApRof::saveDetails($this->tid, ['suitable' => '1']);
    }

    public function testTemperatureReadingsArePerishableOnlyInitialledAndOrdered(): void
    {
        $at = new DateTimeImmutable('2026-11-20 08:00:00', new DateTimeZone('UTC'));
        try { ApRof::temperatures($this->tid, $at, [1 => '22'], 'AB'); $this->fail('expected refusal'); } catch (ApTreatmentError $e) { $this->assertStringContainsString('perishable', $e->getMessage()); }
        try { ApRof::temperatures($this->ptid, $at, [1 => '22'], ''); $this->fail('expected refusal'); } catch (InvalidArgumentException $e) { $this->assertStringContainsString('initials', $e->getMessage()); }
        try { ApRof::temperatures($this->ptid, $at, [8 => '22'], 'AB'); $this->fail('expected refusal'); } catch (InvalidArgumentException $e) { $this->assertStringContainsString('1 to 7', $e->getMessage()); }
        try { ApRof::temperatures($this->ptid, $at, [1 => ''], 'AB'); $this->fail('expected refusal'); } catch (InvalidArgumentException $e) { $this->assertStringContainsString('at least one', $e->getMessage()); }
        try { ApRof::temperatures($this->ptid, $at, [1 => 'hot'], 'AB'); $this->fail('expected refusal'); } catch (InvalidArgumentException $e) { $this->assertStringContainsString('Probe 1', $e->getMessage()); }
        ApRof::temperatures($this->ptid, $at->modify('+1 hour'), [2 => '21.5', 1 => '22'], 'ab', 'u1');
        ApRof::temperatures($this->ptid, $at, [1 => '23'], 'AB', 'u1');
        $d = ApRof::document($this->ptid);
        $this->assertSame('perishable', $d['variant']);
        $this->assertCount(2, $d['D']['temperatures']);
        $this->assertSame('2026-11-20 08:00:00', $d['D']['temperatures'][0]['taken_at']);
        $this->assertSame(['1', '2'], array_map('strval', array_keys($d['D']['temperatures'][1]['values'])));
        $this->assertSame('AB', $d['D']['temperatures'][1]['initials']);
    }

    public function testChloropicrinIsDerivedFromTheLotMixNotTypedTwice(): void
    {
        $this->db->update('ap_treatments', ['mb_fraction' => 0.98], 'id = ?', [$this->tid]);
        $c = ApRof::document($this->tid)['C'];
        $this->assertTrue($c['chloropicrin']);
        $this->assertSame(2.0, (float)$c['chloropicrin_pct']);
    }

    public function testScreenAndApiAreWiredAndNodeParsesTheScreen(): void
    {
        $root = dirname(__DIR__, 3);
        $api = (string)file_get_contents($root . '/api/allpest/rof.php');
        foreach (["case 'details':", "case 'temps':", "ApAccess::guard('treatment.view'", "ApAccess::guard('treatment.record'", 'ApLockedException'] as $n) { $this->assertStringContainsString($n, $api); }
        $m = (string)file_get_contents($root . '/modules/allpest_rof/allpest_rof.php');
        foreach (['Section A: Fumigator in charge', 'Section B: Job details', 'Section C: Fumigation details', 'Section D: Concentration', 'Section E: Fumigator declaration', 'Temperature probe location', 'Load factor'] as $n) { $this->assertStringContainsString($n, $m); }
        $this->assertStringContainsString("'allpest_rof'", (string)file_get_contents($root . '/assets/js/nubuilder-next.js'));
        $js = explode('</script>', explode('<script>', $m)[1])[0];
        $f = tempnam(sys_get_temp_dir(), 'rof') . '.js';
        file_put_contents($f, 'new Function(require("fs").readFileSync(' . json_encode($f . '.src') . ', "utf8"));');
        file_put_contents($f . '.src', $js);
        exec('node ' . escapeshellarg($f) . ' 2>&1', $out, $code);
        @unlink($f); @unlink($f . '.src');
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testRecordAndWorkspaceOpenEachOther(): void
    {
        $root = dirname(__DIR__, 3);
        $rof = (string)file_get_contents($root . '/modules/allpest_rof/allpest_rof.php');
        $work = (string)file_get_contents($root . '/modules/allpest_work/allpest_work.php');
        $this->assertStringContainsString("window.AP_NEXT = tid; NuApp.loadModule('allpest_work')", $rof);
        $this->assertStringContainsString('window.AP_ROF_NEXT', $rof);
        $this->assertStringContainsString("NuApp.loadModule('allpest_rof')", $work);
        $this->assertStringContainsString('window.AP_ROF_NEXT = openId', $work);
    }
}
