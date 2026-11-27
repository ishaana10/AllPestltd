<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApTechnicians', 'ApEquipment'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApMastersTest extends TestCase
{
    private ApTestDb $db;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->now = new DateTimeImmutable('2026-12-01 10:00:00', new DateTimeZone('UTC'));
    }

    private function tech(string $name, ?string $expiry, string $status = 'active'): string
    {
        return ApTechnicians::create(['full_name' => $name, 'registration_no' => 'R-' . $name, 'registration_expiry' => $expiry, 'status' => $status]);
    }

    private function item(string $kind, string $serial, array $extra = []): string
    {
        $id = ApId::uuid();
        $this->db->insert('ap_equipment', array_merge(['id' => $id, 'kind' => $kind, 'name' => $kind . ' ' . $serial, 'serial_no' => $serial], $extra));
        return $id;
    }

    public function testExpiredRegistrationCannotSign(): void
    {
        $this->assertFalse(ApTechnicians::canSign($this->tech('old', '2026-11-30'), $this->now));
    }

    public function testRegistrationValidThroughItsExpiryDayCanSign(): void
    {
        $this->assertTrue(ApTechnicians::canSign($this->tech('today', '2026-12-01'), $this->now));
    }

    public function testInactiveOrUnregisteredTechnicianCannotSign(): void
    {
        $this->assertFalse(ApTechnicians::canSign($this->tech('gone', '2027-12-01', 'inactive'), $this->now));
        $this->assertFalse(ApTechnicians::canSign($this->tech('noexp', null), $this->now));
        $this->assertFalse(ApTechnicians::canSign('does-not-exist', $this->now));
    }

    public function testExpiringWithinThirtyDays(): void
    {
        $in29 = $this->tech('in29', '2026-12-30');
        $in31 = $this->tech('in31', '2027-01-01');
        $this->tech('expired', '2026-11-01');
        $ids = array_column(ApTechnicians::expiringWithin(30, $this->now), 'id');
        $this->assertContains($in29, $ids);
        $this->assertNotContains($in31, $ids);
        $this->assertCount(1, $ids);
    }

    public function testOfficersAreASimpleList(): void
    {
        ApTechnicians::addOfficer('J. Naidu', 'Biosecurity Officer');
        $o = ApTechnicians::officers();
        $this->assertCount(1, $o);
        $this->assertSame('Biosecurity Officer', $o[0]['designation']);
    }

    public function testSelectableExcludesDiscardedOverdueAndExpired(): void
    {
        $ok = $this->item('detector', 'OK-1', ['next_audit_due' => '2027-01-15']);
        $okNoDates = $this->item('sheet', 'OK-2');
        $discarded = $this->item('detector', 'D-1', ['status' => 'discarded']);
        $overdue = $this->item('detector', 'O-1', ['next_audit_due' => '2026-11-30']);
        $tube = $this->item('stain_tube', 'T-1', ['expiry_date' => '2026-11-30']);
        $freshTube = $this->item('stain_tube', 'T-2', ['expiry_date' => '2027-06-01']);
        $ids = array_column(ApEquipment::selectable($this->now), 'id');
        foreach ([$ok, $okNoDates, $freshTube] as $id) { $this->assertContains($id, $ids); }
        foreach ([$discarded, $overdue, $tube] as $id) { $this->assertNotContains($id, $ids); }
    }

    public function testDiscardSetsStatusAndKeepsLogRow(): void
    {
        $id = $this->item('detector', 'X-1');
        ApEquipment::logEvent($id, 'discard', 'cracked housing', 'admin1');
        $this->assertSame('discarded', $this->db->fetchOne('SELECT status FROM ap_equipment WHERE id = ?', [$id])['status']);
        $log = $this->db->fetchAll('SELECT * FROM ap_equipment_log WHERE equipment_id = ?', [$id]);
        $this->assertCount(1, $log);
        $this->assertSame('discard', $log[0]['kind']);
        $this->assertSame('cracked housing', $log[0]['note']);
    }

    public function testReplaceRepairAndAuditEvents(): void
    {
        $r = $this->item('detector', 'R-1');
        ApEquipment::logEvent($r, 'replace', 'swapped', 'a');
        $this->assertSame('replaced', $this->db->fetchOne('SELECT status FROM ap_equipment WHERE id = ?', [$r])['status']);
        $p = $this->item('detector', 'P-1');
        ApEquipment::logEvent($p, 'repair', 'sent out', 'a');
        $this->assertSame('in_repair', $this->db->fetchOne('SELECT status FROM ap_equipment WHERE id = ?', [$p])['status']);
        $a = $this->item('detector', 'A-1');
        ApEquipment::logEvent($a, 'audit', 'passed', 'a');
        $this->assertSame('in_service', $this->db->fetchOne('SELECT status FROM ap_equipment WHERE id = ?', [$a])['status']);
    }

    public function testUnknownEventKindIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApEquipment::logEvent($this->item('detector', 'Z-1'), 'explode', '', 'a');
    }

    public function testAuditAdvancesNextAuditDueWhenGiven(): void
    {
        $id = $this->item('detector', 'N-1', ['next_audit_due' => '2026-11-30']);
        ApEquipment::logEvent($id, 'audit', 'calibrated', 'a', '2027-06-01');
        $this->assertSame('2027-06-01', $this->db->fetchOne('SELECT next_audit_due FROM ap_equipment WHERE id = ?', [$id])['next_audit_due']);
        $this->assertContains($id, array_column(ApEquipment::selectable($this->now), 'id'));
    }

    public function testSerialLast4(): void
    {
        $id = $this->item('detector', 'ABC-12345');
        $this->assertSame('2345', ApEquipment::serialLast4($id));
        $short = $this->item('detector', 'A1');
        $this->assertSame('A1', ApEquipment::serialLast4($short));
    }
}
