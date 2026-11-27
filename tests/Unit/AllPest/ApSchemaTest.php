<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
require_once dirname(__DIR__, 3) . '/core/allpest/ApId.php';
require_once dirname(__DIR__, 3) . '/core/allpest/ApSchema.php';

final class ApSchemaTest extends TestCase
{
    private const TABLES = [
        'technicians', 'technician_files', 'officers', 'equipment', 'equipment_log', 'schedules',
        'stock_lots', 'stock_movements', 'requests', 'consignments', 'treatments', 'treatment_containers',
        'treatment_versions', 'reading_sets', 'reading_set_files', 'readings', 'files', 'signatures',
        'certificates', 'certificate_numbers', 'invoice_links', 'settings', 'intake_throttle',
    ];

    private ApTestDb $db;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
    }

    public function testEnsureCanRunTwice(): void
    {
        ApSchema::ensure($this->db);
        $this->assertTrue(true);
    }

    public function testEveryTableExists(): void
    {
        foreach (self::TABLES as $t) {
            $row = $this->db->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", ['ap_' . $t]);
            $this->assertNotNull($row, "ap_$t should exist");
        }
    }

    public function testUuidIsVersion4(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', ApId::uuid());
        $this->assertNotSame(ApId::uuid(), ApId::uuid());
    }

    public function testRequestJobTypeDefaultsToFumigation(): void
    {
        $id = ApId::uuid();
        $this->db->insert('ap_requests', ['id' => $id, 'reference' => 'AP-202612-0001', 'customer_name' => 'X', 'customer_email' => 'x@example.com']);
        $this->assertSame('fumigation', $this->db->fetchOne('SELECT job_type FROM ap_requests WHERE id = ?', [$id])['job_type']);
        $this->assertSame('new', $this->db->fetchOne('SELECT status FROM ap_requests WHERE id = ?', [$id])['status']);
    }

    public function testDuplicateCertificateNumberIsRefused(): void
    {
        $this->db->insert('ap_certificate_numbers', ['id' => ApId::uuid(), 'number' => 'AP-2026-00001']);
        $this->expectException(PDOException::class);
        $this->db->insert('ap_certificate_numbers', ['id' => ApId::uuid(), 'number' => 'AP-2026-00001']);
    }

    public function testOnlyOneCertificatePerContainer(): void
    {
        $c = ApId::uuid();
        $row = fn () => ['id' => ApId::uuid(), 'treatment_id' => ApId::uuid(), 'container_id' => $c, 'number' => ApId::uuid(), 'content_sha256' => str_repeat('a', 64)];
        $this->db->insert('ap_certificates', $row());
        $this->expectException(PDOException::class);
        $this->db->insert('ap_certificates', $row());
    }

    public function testReadingSetClientUuidIsUnique(): void
    {
        $u = ApId::uuid();
        $mk = fn () => ['id' => ApId::uuid(), 'treatment_id' => ApId::uuid(), 'client_uuid' => $u, 'stage' => 'exposure_end', 'taken_at' => '2026-12-02 06:00:00', 'initials' => 'JP'];
        $this->db->insert('ap_reading_sets', $mk());
        $this->expectException(PDOException::class);
        $this->db->insert('ap_reading_sets', $mk());
    }

    public function testOneInvoiceLinkPerRequest(): void
    {
        $r = ApId::uuid();
        $this->db->insert('ap_invoice_links', ['id' => ApId::uuid(), 'request_id' => $r]);
        $this->expectException(PDOException::class);
        $this->db->insert('ap_invoice_links', ['id' => ApId::uuid(), 'request_id' => $r]);
    }

    public function testAContainerBelongsToOneTreatmentOnly(): void
    {
        $c = ApId::uuid();
        $this->db->insert('ap_treatment_containers', ['id' => ApId::uuid(), 'treatment_id' => ApId::uuid(), 'consignment_id' => $c]);
        $this->expectException(PDOException::class);
        $this->db->insert('ap_treatment_containers', ['id' => ApId::uuid(), 'treatment_id' => ApId::uuid(), 'consignment_id' => $c]);
    }
}
