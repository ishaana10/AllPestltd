<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApTechnicians', 'ApStock', 'ApRetention', 'ApReminders'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApRetentionTest extends TestCase
{
    private ApTestDb $db;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->now = new DateTimeImmutable('2030-06-15 00:00:00', new DateTimeZone('UTC'));
        ApReminders::setMailer(null);
    }

    public function testCannotPurgeOneDayShortOfFiveYears(): void
    {
        $closed = new DateTimeImmutable('2025-06-16 00:00:00', new DateTimeZone('UTC'));
        $this->assertFalse(ApRetention::canPurge($closed, $this->now));
    }

    public function testCanPurgeExactlyAtFiveYears(): void
    {
        $closed = new DateTimeImmutable('2025-06-15 00:00:00', new DateTimeZone('UTC'));
        $this->assertTrue(ApRetention::canPurge($closed, $this->now));
    }

    public function testYearsBelowFiveAreTreatedAsFive(): void
    {
        $closed = new DateTimeImmutable('2028-06-15 00:00:00', new DateTimeZone('UTC'));
        $this->assertFalse(ApRetention::canPurge($closed, $this->now, 1));
        $this->assertFalse(ApRetention::canPurge($closed, $this->now, 0));
        $this->assertFalse(ApRetention::canPurge($closed, $this->now, -3));
    }

    public function testLongerRetentionIsHonoured(): void
    {
        $closed = new DateTimeImmutable('2024-06-15 00:00:00', new DateTimeZone('UTC'));
        $this->assertTrue(ApRetention::canPurge($closed, $this->now, 5));
        $this->assertFalse(ApRetention::canPurge($closed, $this->now, 7));
    }

    private function seed(): void
    {
        $t = '2030-01-01 00:00:00';
        $this->db->insert('ap_technicians', ['id' => 'T-SOON', 'full_name' => 'Soon', 'registration_expiry' => '2030-07-01', 'status' => 'active', 'created_at' => $t]);
        $this->db->insert('ap_technicians', ['id' => 'T-FAR', 'full_name' => 'Far', 'registration_expiry' => '2031-07-01', 'status' => 'active', 'created_at' => $t]);
        $this->db->insert('ap_equipment', ['id' => 'E-DUE', 'kind' => 'detector', 'name' => 'Detector 1', 'next_audit_due' => '2030-06-20', 'created_at' => $t]);
        $this->db->insert('ap_equipment', ['id' => 'E-OK', 'kind' => 'detector', 'name' => 'Detector 2', 'next_audit_due' => '2031-01-01', 'created_at' => $t]);
        $this->db->insert('ap_equipment', ['id' => 'E-GONE', 'kind' => 'detector', 'name' => 'Detector 3', 'next_audit_due' => '2030-06-01', 'status' => 'discarded', 'created_at' => $t]);
        $this->db->insert('ap_stock_lots', ['id' => 'L-LOW', 'lot_ref' => 'LOT-LOW', 'created_at' => $t]);
        $this->db->insert('ap_stock_movements', ['id' => 'M1', 'lot_id' => 'L-LOW', 'kind' => 'receive', 'grams' => 3000, 'created_at' => $t]);
        $this->db->insert('ap_stock_lots', ['id' => 'L-OK', 'lot_ref' => 'LOT-OK', 'created_at' => $t]);
        $this->db->insert('ap_stock_movements', ['id' => 'M2', 'lot_id' => 'L-OK', 'kind' => 'receive', 'grams' => 20000, 'created_at' => $t]);
        $this->db->insert('ap_requests', ['id' => 'R1', 'reference' => 'AP-1', 'customer_name' => 'C', 'customer_email' => 'c@x.com', 'created_at' => $t]);
        // closed 30h ago with only the fumigator signed; closed 2h ago unsigned; closed 30h ago fully signed
        $this->db->insert('ap_treatments', ['id' => 'TR-OLD', 'request_id' => 'R1', 'status' => 'closed', 'closed_at' => '2030-06-13 18:00:00', 'version' => 1, 'created_at' => $t]);
        $this->db->insert('ap_signatures', ['id' => 'S1', 'treatment_id' => 'TR-OLD', 'role' => 'fumigator', 'signer_name' => 'F', 'signed_at' => '2030-06-13 19:00:00', 'version' => 1]);
        $this->db->insert('ap_treatments', ['id' => 'TR-NEW', 'request_id' => 'R1', 'status' => 'closed', 'closed_at' => '2030-06-14 22:00:00', 'version' => 1, 'created_at' => $t]);
        $this->db->insert('ap_treatments', ['id' => 'TR-DONE', 'request_id' => 'R1', 'status' => 'closed', 'closed_at' => '2030-06-13 18:00:00', 'version' => 1, 'created_at' => $t]);
        $this->db->insert('ap_signatures', ['id' => 'S2', 'treatment_id' => 'TR-DONE', 'role' => 'fumigator', 'signer_name' => 'F', 'signed_at' => '2030-06-13 19:00:00', 'version' => 1]);
        $this->db->insert('ap_signatures', ['id' => 'S3', 'treatment_id' => 'TR-DONE', 'role' => 'officer', 'signer_name' => 'O', 'signed_at' => '2030-06-13 20:00:00', 'version' => 1]);
    }

    public function testRemindersIncludeEachKindAndExcludeOutOfWindowItems(): void
    {
        $this->seed();
        $due = ApReminders::due($this->now);
        $byKind = [];
        foreach ($due as $d) {
            $this->assertSame(['kind', 'subject', 'due'], array_keys($d));
            $byKind[$d['kind']][] = $d['subject'];
        }
        $this->assertSame(['registration', 'equipment', 'stock', 'signature'], array_values(array_intersect(['registration', 'equipment', 'stock', 'signature'], array_keys($byKind))));
        $this->assertCount(1, $byKind['registration']);
        $this->assertStringContainsString('Soon', $byKind['registration'][0]);
        $this->assertCount(1, $byKind['equipment']);
        $this->assertStringContainsString('Detector 1', $byKind['equipment'][0]);
        $this->assertCount(1, $byKind['stock']);
        $this->assertStringContainsString('LOT-LOW', $byKind['stock'][0]);
        $this->assertCount(1, $byKind['signature']);
        $this->assertStringContainsString('TR-OLD', $byKind['signature'][0]);
        $this->assertStringContainsString('officer', $byKind['signature'][0]);
    }

    public function testNothingDueMeansNoReminders(): void
    {
        $this->assertSame([], ApReminders::due($this->now));
    }

    public function testExpiredRegistrationIsStillReminded(): void
    {
        $this->db->insert('ap_technicians', ['id' => 'T-OLD', 'full_name' => 'Expired', 'registration_expiry' => '2030-06-01', 'status' => 'active', 'created_at' => '2030-01-01 00:00:00']);
        $this->assertCount(1, ApReminders::due($this->now));
    }

    public function testDailyEmailGoesOnceAPerDayToTheStaffAddress(): void
    {
        $this->seed();
        ApSettings::set('staff_email', 'admin@allpest.test');
        $sent = [];
        ApReminders::setMailer(function (string $to, string $subject, string $body) use (&$sent): void { $sent[] = [$to, $subject, $body]; });
        $this->assertTrue(ApReminders::sendDaily($this->now));
        $this->assertFalse(ApReminders::sendDaily($this->now->modify('+3 hours')));
        $this->assertCount(1, $sent);
        $this->assertSame('admin@allpest.test', $sent[0][0]);
        $this->assertStringContainsString('Detector 1', $sent[0][2]);
        $this->assertTrue(ApReminders::sendDaily($this->now->modify('+1 day')));
    }

    public function testNoEmailWhenNothingIsDueOrNoAddress(): void
    {
        $sent = 0;
        ApReminders::setMailer(function () use (&$sent): void { $sent++; });
        ApSettings::set('staff_email', 'admin@allpest.test');
        $this->assertFalse(ApReminders::sendDaily($this->now));
        $this->seed();
        ApSettings::set('staff_email', '');
        $this->assertFalse(ApReminders::sendDaily($this->now));
        $this->assertSame(0, $sent);
    }
}
