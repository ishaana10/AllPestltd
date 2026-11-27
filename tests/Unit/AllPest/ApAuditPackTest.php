<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
require_once dirname(__DIR__, 3) . '/core/Audit.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApUploads', 'ApTreatments', 'ApAudit', 'ApAuditPack'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApAuditPackTest extends TestCase
{
    private ApTestDb $db;
    private string $dir;
    private NuAudit $audit;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->dir = sys_get_temp_dir() . '/appack_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        ApUploads::configure($this->db, $this->dir);
        $ref = new ReflectionClass(NuAudit::class);
        $this->audit = $ref->newInstanceWithoutConstructor();
        $p = $ref->getProperty('db');
        $p->setAccessible(true);
        $p->setValue($this->audit, $this->db);
        $s = $ref->getProperty('schemaChecked');
        $s->setAccessible(true);
        $s->setValue(null, false);
        ApAudit::useAudit($this->audit);
        ApTreatments::setAuditor(null);
        $_SESSION['nu_user_id'] = 'u1';
        $_SESSION['nu_username'] = 'tech1';
        $_SERVER['HTTP_USER_AGENT'] = 'TabletX';
    }

    protected function tearDown(): void
    {
        ApAudit::useAudit(null);
        foreach (array_merge(glob($this->dir . '/*') ?: [], glob($this->dir . '/.*') ?: []) as $f) { if (is_file($f)) { @unlink($f); } }
        @rmdir($this->dir);
        unset($_SESSION['nu_user_id'], $_SESSION['nu_username'], $_SERVER['HTTP_USER_AGENT']);
    }

    /** @return array{tid:string,photo:string,sha:string,cid:string} */
    private function fixture(string $ref, string $photoBytes): array
    {
        $now = '2099-01-05 06:00:00';
        $req = ApId::uuid();
        $this->db->insert('ap_requests', ['id' => $req, 'reference' => $ref, 'customer_name' => 'C', 'customer_email' => 'c@x.com', 'created_at' => $now]);
        $tech = ApId::uuid();
        $this->db->insert('ap_technicians', ['id' => $tech, 'full_name' => "Tech $ref", 'registration_no' => "R-$ref", 'created_at' => $now]);
        $off = ApId::uuid();
        $this->db->insert('ap_officers', ['id' => $off, 'full_name' => "Officer $ref", 'created_at' => $now]);
        $eq = ApId::uuid();
        $this->db->insert('ap_equipment', ['id' => $eq, 'kind' => 'detector', 'name' => "Detector $ref", 'serial_no' => "SN$ref", 'created_at' => $now]);
        $lot = ApId::uuid();
        $this->db->insert('ap_stock_lots', ['id' => $lot, 'lot_ref' => "LOT-$ref", 'created_at' => $now]);
        $tid = ApId::uuid();
        $this->db->insert('ap_treatments', ['id' => $tid, 'request_id' => $req, 'technician_id' => $tech, 'officer_id' => $off, 'status' => 'closed', 'equipment_ids' => json_encode([$eq]),
            'lot_id' => $lot, 'applied_g' => 500, 'dose_g' => 480, 'forecast_json' => json_encode(['min_c' => 23.5, 'source' => "src-$ref"]), 'forecast_min_c' => 23.5, 'version' => 2, 'created_at' => $now]);
        $con = ApId::uuid();
        $this->db->insert('ap_consignments', ['id' => $con, 'request_id' => $req, 'container_no' => "MSKU-$ref", 'created_at' => $now]);
        $cid = ApId::uuid();
        $this->db->insert('ap_treatment_containers', ['id' => $cid, 'treatment_id' => $tid, 'consignment_id' => $con, 'result' => 'pass']);
        $this->db->insert('ap_treatment_versions', ['id' => ApId::uuid(), 'treatment_id' => $tid, 'version' => 1, 'snapshot' => json_encode(['dose_g' => 470]), 'created_at' => $now]);
        $this->db->insert('ap_treatment_versions', ['id' => ApId::uuid(), 'treatment_id' => $tid, 'version' => 2, 'snapshot' => json_encode(['dose_g' => 480]), 'reason' => 'typo', 'created_at' => $now]);
        $rs = ApId::uuid();
        $this->db->insert('ap_reading_sets', ['id' => $rs, 'treatment_id' => $tid, 'client_uuid' => ApId::uuid(), 'stage' => 'start_point', 'taken_at' => $now, 'initials' => 'T', 'standard' => 48.0, 'created_at' => $now]);
        $this->db->insert('ap_readings', ['id' => ApId::uuid(), 'reading_set_id' => $rs, 'container_id' => $cid, 'location' => 'top', 'value' => 50.0, 'meets' => 1]);
        $photo = ApId::uuid();
        $stored = $photo . '.jpg';
        file_put_contents($this->dir . '/' . $stored, $photoBytes);
        $sha = hash('sha256', $photoBytes);
        $this->db->insert('ap_files', ['id' => $photo, 'purpose' => 'reading', 'original_name' => 'r.jpg', 'stored_name' => $stored, 'mime' => 'image/jpeg', 'bytes' => strlen($photoBytes), 'sha256' => $sha, 'created_at' => $now]);
        $this->db->insert('ap_reading_set_files', ['id' => ApId::uuid(), 'reading_set_id' => $rs, 'file_id' => $photo]);
        $this->db->insert('ap_signatures', ['id' => ApId::uuid(), 'treatment_id' => $tid, 'role' => 'fumigator', 'signer_name' => "Tech $ref", 'signed_at' => $now, 'version' => 1]);
        $this->db->insert('ap_signatures', ['id' => ApId::uuid(), 'treatment_id' => $tid, 'role' => 'fumigator', 'signer_name' => "Tech $ref", 'signed_at' => $now, 'version' => 2]);
        $this->db->insert('ap_certificates', ['id' => ApId::uuid(), 'treatment_id' => $tid, 'container_id' => $cid, 'number' => "AP-C-$ref", 'content_sha256' => str_repeat('a', 64), 'issued_at' => $now, 'created_at' => $now]);
        $this->db->insert('ap_stock_movements', ['id' => ApId::uuid(), 'lot_id' => $lot, 'kind' => 'consume', 'grams' => -500, 'treatment_id' => $tid, 'created_at' => $now]);
        ApAudit::event('treatment.dosed', $tid, ['applied_g' => 500]);
        ApAudit::event('treatment.closed', $tid, ['overall' => 'pass']);
        return ['tid' => $tid, 'photo' => $photo, 'sha' => $sha, 'cid' => $cid];
    }

    /** @return array<string,string> path => contents */
    private function unzip(string $zipPath): array
    {
        $z = new ZipArchive();
        $this->assertTrue($z->open($zipPath) === true);
        $out = [];
        for ($i = 0; $i < $z->numFiles; $i++) { $out[(string)$z->getNameIndex($i)] = (string)$z->getFromIndex($i); }
        $z->close();
        return $out;
    }

    public function testEventCarriesTreatmentIdUserAndDevice(): void
    {
        ApAudit::event('treatment.dosed', 'T-1', ['applied_g' => 5]);
        $row = $this->db->fetchOne("SELECT * FROM nu_audit_log WHERE audit_record_id = 'T-1'");
        $this->assertNotNull($row);
        $this->assertSame('treatment.dosed', $row['audit_action']);
        $this->assertSame('u1', $row['audit_user_id']);
        $d = json_decode((string)$row['audit_new_data'], true);
        $this->assertSame('T-1', $d['treatment_id']);
        $this->assertSame('TabletX', $d['device']);
        $this->assertSame('tech1', $d['user']);
    }

    public function testTreatmentWritesReachTheAuditLogByDefault(): void
    {
        ApTreatments::audit('treatment.tlv', 'T-9', ['ppm' => 0.2]);
        $this->assertSame(1, (int)$this->db->fetchOne("SELECT COUNT(*) AS n FROM nu_audit_log WHERE audit_record_id = 'T-9'")['n']);
    }

    public function testManifestListsEveryPhotoWithItsSha256AndAllVersions(): void
    {
        $a = $this->fixture('A', 'photo-bytes-A');
        $files = $this->unzip(ApAuditPack::build($a['tid']));
        $m = json_decode($files['manifest.json'], true);
        $this->assertSame($a['tid'], $m['treatment_id']);
        $shas = array_column($m['photos'], 'sha256');
        $this->assertContains($a['sha'], $shas);
        $this->assertSame('ok', $m['integrity']['status']);
        $rof = json_decode($files['rof.json'], true);
        $this->assertSame([1, 2], array_column($rof['versions'], 'version'));
        $this->assertSame(hash('sha256', 'photo-bytes-A'), hash('sha256', $files['photos/' . $a['photo'] . '.jpg']));
        foreach (['readings.json', 'forecast.json', 'equipment.json', 'technician.json', 'gas.json', 'signatures.json', 'certificates.json', 'activity_log.json'] as $f) {
            $this->assertArrayHasKey($f, $files);
        }
        $certs = json_decode($files['certificates.json'], true);
        $this->assertSame('AP-C-A', $certs[0]['number']);
        $this->assertCount(2, json_decode($files['activity_log.json'], true));
    }

    public function testPackForOneTreatmentContainsNothingFromAnother(): void
    {
        $a = $this->fixture('A', 'photo-bytes-A');
        $b = $this->fixture('B', 'photo-bytes-B');
        $files = $this->unzip(ApAuditPack::build($a['tid']));
        $all = implode("\n", array_keys($files)) . "\n" . implode("\n", $files);
        foreach ([$b['tid'], $b['photo'], $b['cid'], 'AP-C-B', 'MSKU-B', 'Tech B', 'LOT-B', 'SNB', 'photo-bytes-B'] as $needle) {
            $this->assertStringNotContainsString($needle, $all, "leaked: $needle");
        }
        $this->assertStringContainsString('MSKU-A', $all);
    }

    public function testTamperedAuditRowMakesIntegrityFail(): void
    {
        $a = $this->fixture('A', 'photo-bytes-A');
        $this->db->exec("UPDATE nu_audit_log SET audit_new_data = '{\"applied_g\":1}' WHERE audit_action = 'treatment.dosed'");
        $m = json_decode($this->unzip(ApAuditPack::build($a['tid']))['manifest.json'], true);
        $this->assertSame('failed', $m['integrity']['status']);
    }

    public function testTempFileIsRemovedAfterStreaming(): void
    {
        $a = $this->fixture('A', 'x');
        $path = ApAuditPack::build($a['tid']);
        $this->assertTrue(is_file($path));
        ob_start();
        ApAuditPack::send($path, 'pack.zip', false);
        ob_end_clean();
        $this->assertFalse(is_file($path));
    }
}
