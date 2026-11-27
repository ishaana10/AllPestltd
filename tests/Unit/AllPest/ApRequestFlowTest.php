<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApTechnicians', 'ApSchedules'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApRequestFlowTest extends TestCase
{
    private ApTestDb $db;
    private string $reqId;
    /** @var string[] */
    private array $cons;
    private string $tech;
    private string $officer;
    private string $sched;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        ApRequests::setMailer(function (): void {});
        $input = [
            'customer_name' => 'Pacific Timber', 'customer_email' => 'b@example.com', 'site_address' => 'Queens Wharf', 'client_token' => bin2hex(random_bytes(6)),
            'consignments' => array_map(fn ($i) => ['container_no' => "MSKU000000$i"], [1, 2, 3, 4]),
        ];
        $r = ApRequests::submitPublic($input, [], '1.1.1.1');
        $this->reqId = $r['request_id'];
        $this->cons = array_column($this->db->fetchAll('SELECT id FROM ap_consignments WHERE request_id = ? ORDER BY container_no', [$this->reqId]), 'id');
        $this->tech = ApTechnicians::create(['full_name' => 'T One', 'registration_no' => 'R1', 'registration_expiry' => '2099-12-31']);
        $this->officer = ApTechnicians::addOfficer('J Naidu', 'Biosecurity Officer');
        $this->sched = ApSchedules::save(['name' => 'General 48', 'treatment_type' => 'general', 'dose_rate' => 48, 'exposure_hours' => 24, 'min_temp_c' => 21, 'retention_class' => 'standard']);
    }

    private function plan(array $over = []): array
    {
        return array_merge([
            'scheduled_start' => '2099-01-05 06:00:00', 'site_lat' => -17.6, 'site_lng' => 177.45, 'technician_id' => $this->tech, 'officer_id' => $this->officer,
            'schedule_id' => $this->sched, 'rof_type' => 'general', 'enclosure_type' => 'container_stack', 'fumigation_type' => 'ambient',
        ], $over);
    }

    private function status(): string
    {
        return (string)$this->db->fetchOne('SELECT status FROM ap_requests WHERE id = ?', [$this->reqId])['status'];
    }

    public function testConfirmThenScheduleMovesStatusesInOrder(): void
    {
        $this->assertSame('new', $this->status());
        ApRequests::confirm($this->reqId, ['site_lat' => -17.6, 'site_lng' => 177.45], 'admin1');
        $this->assertSame('confirmed', $this->status());
        ApRequests::createTreatment($this->reqId, [$this->cons[0], $this->cons[1]], $this->plan());
        $this->assertSame('scheduled', $this->status());
    }

    public function testSchedulingBeforeConfirmIsRejected(): void
    {
        $this->expectException(ApRequestStateError::class);
        ApRequests::createTreatment($this->reqId, [$this->cons[0]], $this->plan());
    }

    public function testConfirmTwiceIsAnIllegalJump(): void
    {
        ApRequests::confirm($this->reqId, [], 'admin1');
        $this->expectException(ApRequestStateError::class);
        ApRequests::confirm($this->reqId, [], 'admin1');
    }

    public function testAdvanceFollowsTheChainAndRejectsJumps(): void
    {
        ApRequests::confirm($this->reqId, [], 'a');
        ApRequests::createTreatment($this->reqId, [$this->cons[0]], $this->plan());
        try { ApRequests::advance($this->reqId, 'completed'); $this->fail('scheduled -> completed must throw'); } catch (ApRequestStateError $e) { $this->assertTrue(true); }
        ApRequests::advance($this->reqId, 'in_progress');
        ApRequests::advance($this->reqId, 'completed');
        $this->assertSame('completed', $this->status());
        try { ApRequests::advance($this->reqId, 'new'); $this->fail('no way back'); } catch (ApRequestStateError $e) { $this->assertTrue(true); }
    }

    public function testExpiredTechnicianCannotBeScheduled(): void
    {
        $old = ApTechnicians::create(['full_name' => 'Old', 'registration_no' => 'R0', 'registration_expiry' => '2020-01-01']);
        ApRequests::confirm($this->reqId, [], 'a');
        try {
            ApRequests::createTreatment($this->reqId, [$this->cons[0]], $this->plan(['technician_id' => $old, 'scheduled_start' => '2026-12-05 06:00:00']));
            $this->fail('expected rejection');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_treatments')['n']);
            $this->assertSame('confirmed', $this->status());
        }
    }

    public function testRegistrationMustBeValidOnTheTreatmentDayNotJustToday(): void
    {
        $soon = ApTechnicians::create(['full_name' => 'Soon', 'registration_no' => 'R2', 'registration_expiry' => '2026-12-31']);
        ApRequests::confirm($this->reqId, [], 'a');
        $this->expectException(InvalidArgumentException::class);
        ApRequests::createTreatment($this->reqId, [$this->cons[0]], $this->plan(['technician_id' => $soon, 'scheduled_start' => '2027-02-01 06:00:00']));
    }

    public function testScheduleOfAnotherTypeIsRejected(): void
    {
        $per = ApSchedules::save(['name' => 'Perishable 32', 'treatment_type' => 'perishable', 'dose_rate' => 32, 'exposure_hours' => 2, 'min_temp_c' => 10, 'retention_class' => 'eighty']);
        ApRequests::confirm($this->reqId, [], 'a');
        $this->expectException(InvalidArgumentException::class);
        ApRequests::createTreatment($this->reqId, [$this->cons[0]], $this->plan(['schedule_id' => $per, 'rof_type' => 'general']));
    }

    public function testUnsheetedContainersGetOneRecordEachAndCannotShareOne(): void
    {
        ApRequests::confirm($this->reqId, [], 'admin');
        try {
            ApRequests::createTreatment($this->reqId, [$this->cons[0], $this->cons[1]], $this->plan(['enclosure_type' => 'container']));
            $this->fail('two un-sheeted containers must not share one record of fumigation');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('one record', strtolower($e->getMessage()));
        }
        $ids = ApRequests::createTreatments($this->reqId, $this->cons, $this->plan(['enclosure_type' => 'container']));
        $this->assertCount(4, $ids);
        foreach ($ids as $tid) {
            $this->assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) n FROM ap_treatment_containers WHERE treatment_id = ?', [$tid])['n']);
        }
        $this->assertSame('scheduled', $this->db->fetchOne('SELECT status FROM ap_requests WHERE id = ?', [$this->reqId])['status']);
    }

    public function testSheetedContainersStayTogetherInOneRecord(): void
    {
        ApRequests::confirm($this->reqId, [], 'admin');
        $ids = ApRequests::createTreatments($this->reqId, $this->cons, $this->plan(['enclosure_type' => 'container_stack']));
        $this->assertCount(1, $ids);
        $this->assertSame(4, (int)$this->db->fetchOne('SELECT COUNT(*) n FROM ap_treatment_containers WHERE treatment_id = ?', [$ids[0]])['n']);
    }

    public function testSplitIntoTwoTreatmentsKeepsEachContainerInExactlyOne(): void
    {
        ApRequests::confirm($this->reqId, [], 'a');
        $t1 = ApRequests::createTreatment($this->reqId, [$this->cons[0], $this->cons[1]], $this->plan());
        $t2 = ApRequests::createTreatment($this->reqId, [$this->cons[2], $this->cons[3]], $this->plan(['scheduled_start' => '2099-01-06 06:00:00']));
        $this->assertNotSame($t1, $t2);
        $rows = $this->db->fetchAll('SELECT consignment_id, treatment_id FROM ap_treatment_containers');
        $this->assertCount(4, $rows);
        $this->assertCount(4, array_unique(array_column($rows, 'consignment_id')));
        $this->assertSame('scheduled', $this->status());
    }

    public function testContainerCannotBeInTwoTreatmentsAndFailureLeavesNoPartialTreatment(): void
    {
        ApRequests::confirm($this->reqId, [], 'a');
        ApRequests::createTreatment($this->reqId, [$this->cons[0]], $this->plan());
        try {
            ApRequests::createTreatment($this->reqId, [$this->cons[1], $this->cons[0]], $this->plan());
            $this->fail('expected rejection');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_treatments')['n']);
            $this->assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_treatment_containers')['n']);
        }
    }

    public function testContainerFromAnotherRequestIsRejected(): void
    {
        $other = ApRequests::submitPublic(['customer_name' => 'X', 'customer_email' => 'x@example.com', 'site_address' => 's', 'client_token' => 'tok-other', 'consignments' => [['container_no' => 'ZZZU0000001']]], [], '2.2.2.2');
        $foreign = (string)$this->db->fetchOne('SELECT id FROM ap_consignments WHERE request_id = ?', [$other['request_id']])['id'];
        ApRequests::confirm($this->reqId, [], 'a');
        $this->expectException(InvalidArgumentException::class);
        ApRequests::createTreatment($this->reqId, [$foreign], $this->plan());
    }

    public function testEmptyContainerListAndBadFumigationTypeRejected(): void
    {
        ApRequests::confirm($this->reqId, [], 'a');
        foreach ([[[], []], [[$this->cons[0]], ['fumigation_type' => 'magic']], [[$this->cons[0]], ['rof_type' => 'pests']]] as [$ids, $over]) {
            try { ApRequests::createTreatment($this->reqId, $ids, $this->plan($over)); $this->fail('expected rejection'); } catch (InvalidArgumentException $e) { $this->assertTrue(true); }
        }
    }

    public function testTreatmentRecordsPlanAndStartsPlanned(): void
    {
        ApRequests::confirm($this->reqId, [], 'a');
        $id = ApRequests::createTreatment($this->reqId, [$this->cons[0]], $this->plan());
        $t = $this->db->fetchOne('SELECT * FROM ap_treatments WHERE id = ?', [$id]);
        $this->assertSame('planned', $t['status']);
        $this->assertSame('general', $t['rof_type']);
        $this->assertSame($this->tech, $t['technician_id']);
        $this->assertSame($this->officer, $t['officer_id']);
        $this->assertSame('2099-01-05 06:00:00', $t['scheduled_start']);
        $this->assertSame('ambient', $t['fumigation_type']);
        $this->assertSame(1, (int)$t['version']);
    }

    public function testOtherJobTypesAreAcceptedByTheBackboneButCannotBeScheduledAsFumigation(): void
    {
        $this->db->exec("UPDATE ap_requests SET job_type = 'pest_control' WHERE id = '{$this->reqId}'");
        ApRequests::confirm($this->reqId, [], 'a');
        $this->assertSame('confirmed', $this->status());
        $this->expectException(InvalidArgumentException::class);
        ApRequests::createTreatment($this->reqId, [$this->cons[0]], $this->plan());
    }

    public function testRequestDetailListsEachTreatmentWithItsContainers(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/api/allpest/requests.php');
        $this->assertStringContainsString("\$tr['containers']", $src);
        $board = (string)file_get_contents(dirname(__DIR__, 3) . '/modules/allpest_board/allpest_board.php');
        $this->assertStringContainsString('t.containers', $board);
    }
}
