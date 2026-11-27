<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
require_once dirname(__DIR__, 3) . '/core/Audit.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApTechnicians', 'ApEquipment', 'ApSchedules', 'ApStock',
          'ApStandards', 'ApDose', 'ApReadings', 'ApTemperature', 'ApResult', 'ApTreatments', 'ApSigning', 'ApCertificateRenderer', 'ApCertificates',
          'ApErpxClient', 'ApInvoices', 'ApAudit', 'ApAuditPack', 'ApReminders', 'ApRetention'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

/** The whole job: public request -> treatment with one failing container -> signatures -> certificates -> invoice -> audit pack. */
final class ApEndToEndTest extends TestCase
{
    private ApTestDb $db;
    private string $dir;
    private array $mails = [];
    private array $erpxCalls = [];
    private array $invoicesByRef = [];

    private function utc(string $s): DateTimeImmutable { return new DateTimeImmutable($s, new DateTimeZone('UTC')); }

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->dir = sys_get_temp_dir() . '/ap_e2e_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        ApUploads::configure($this->db, $this->dir);
        ApRequests::setMailer(function (): void {});
        ApTreatments::setClock(fn () => $this->utc('2026-12-12 00:00:00'));
        ApTreatments::setAuditor(null); // audit through the real chain
        ApInvoices::setClock(fn () => $this->utc('2026-12-12 00:00:00'));
        ApCertificates::setMailer(function (string $to, string $subject, string $body, array $attachments): void { $this->mails[] = compact('to', 'subject', 'attachments'); });
        $ref = new ReflectionClass(NuAudit::class);
        $audit = $ref->newInstanceWithoutConstructor();
        foreach (['db' => $this->db] as $prop => $val) { $p = $ref->getProperty($prop); $p->setAccessible(true); $p->setValue($audit, $val); }
        $s = $ref->getProperty('schemaChecked'); $s->setAccessible(true); $s->setValue(null, false);
        ApAudit::useAudit($audit);
        foreach (['erpx.base_url' => 'https://erp.test', 'erpx.api_key' => 'k', 'erpx.product.general' => 'FUM-GEN', 'erpx.tax_rate' => '15', 'invoice.mode' => 'reviewed'] as $k => $v) { ApSettings::set($k, $v); }
        ApInvoices::setClient(new ApErpxClient('https://erp.test', 'k', $this->erpx()));
        $_SESSION['nu_user_id'] = 'user-1';
        $_SESSION['nu_username'] = 'tech1';
    }

    protected function tearDown(): void
    {
        ApTreatments::setClock(null);
        ApRequests::setMailer(null);
        ApCertificates::setMailer(null);
        ApInvoices::setClient(null);
        ApInvoices::setClock(null);
        ApAudit::useAudit(null);
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
        @rmdir($this->dir);
        unset($_SESSION['nu_user_id'], $_SESSION['nu_username']);
    }

    private function erpx(): callable
    {
        return function (string $method, string $url, array $headers, ?string $body): array {
            $b = $body !== null ? json_decode($body, true) : [];
            $this->erpxCalls[] = [$method, $url, $b];
            if (str_contains($url, 'parties.php')) { return ['status' => 201, 'body' => json_encode(['status' => 'success', 'id' => 7, 'party_code' => 'CUST-1', 'created' => true])]; }
            if (str_contains($url, 'products.php')) { return ['status' => 200, 'body' => json_encode(['data' => [['selling_price' => '120.00']]])]; }
            if ($method === 'POST') { $this->invoicesByRef[$b['external_ref']] = $b + ['status_now' => $b['status']]; return ['status' => 201, 'body' => json_encode(['invoice_id' => 55, 'invoice_no' => 'INV-55'])]; }
            if ($method === 'PATCH') { foreach ($this->invoicesByRef as &$i) { $i['status_now'] = 'unpaid'; } return ['status' => 200, 'body' => json_encode(['status' => 'success'])]; }
            return ['status' => 404, 'body' => '{}'];
        };
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(300, 100);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagesetthickness($im, 3); imageline($im, 10, 80, 280, 20, imagecolorallocatealpha($im, 0, 0, 0, 0));
        ob_start(); imagepng($im); return 'data:image/png;base64,' . base64_encode((string)ob_get_clean());
    }

    public function testFiveContainerRequestWithOneFailure(): void
    {
        // 1. public request with five containers
        $cons = array_map(fn ($i) => ['container_no' => "MSKU000000$i", 'commodity' => 'Logs'], [1, 2, 3, 4, 5]);
        $r = ApRequests::submitPublic(['customer_name' => 'Pacific Timber', 'customer_email' => 'Buyer@Example.com', 'site_address' => 'Queens Wharf', 'client_token' => bin2hex(random_bytes(5)), 'consignments' => $cons], [], '1.1.1.1');
        $req = $r['request_id'];
        $consIds = array_column($this->db->fetchAll('SELECT id FROM ap_consignments WHERE request_id = ? ORDER BY container_no', [$req]), 'id');

        // 2. confirm and schedule one treatment
        $tech = ApTechnicians::create(['user_id' => 'user-1', 'full_name' => 'T One', 'registration_no' => 'R1', 'registration_expiry' => '2099-12-31']);
        $officer = ApTechnicians::addOfficer('J Naidu', 'Biosecurity Officer');
        $sched = ApSchedules::save(['name' => 'General 48', 'treatment_type' => 'general', 'dose_rate' => 48, 'exposure_hours' => 24, 'min_temp_c' => 21, 'compensation_allowed' => 1, 'topups_allowed' => 1, 'retention_class' => 'standard']);
        ApRequests::confirm($req, ['site_lat' => -17.6, 'site_lng' => 177.45], 'admin1');
        $tid = ApRequests::createTreatment($req, $consIds, ['scheduled_start' => '2026-12-10 06:00:00', 'site_lat' => -17.6, 'site_lng' => 177.45, 'technician_id' => $tech,
            'officer_id' => $officer, 'schedule_id' => $sched, 'rof_type' => 'general', 'enclosure_type' => 'container', 'fumigation_type' => 'ambient']);
        $tcs = array_column($this->db->fetchAll('SELECT tc.id FROM ap_treatment_containers tc JOIN ap_consignments c ON c.id = tc.consignment_id WHERE tc.treatment_id = ? ORDER BY c.container_no', [$tid]), 'id');
        $this->assertCount(5, $tcs);

        // 3. pre-check, dosing
        $eq = ApId::uuid();
        $this->db->insert('ap_equipment', ['id' => $eq, 'kind' => 'detector', 'name' => 'Det 1', 'serial_no' => 'ABC-12345']);
        $lot = ApStock::createLot('MB-1', '', 0.98);
        ApStock::receive($lot, 100000, 'u');
        $pre = ApTreatments::preCheck($tid, ['height_m' => 3, 'depth_m' => 2.5, 'length_m' => 10, 'equipment_ids' => [$eq], 'chloropicrin' => true, 'increment' => 100,
            'forecast' => ['manual_min_c' => 25.0, 'source_note' => 'BoM']], $this->utc('2026-12-09 10:00:00'));
        $this->assertSame([], $pre['blocked']);
        ApTreatments::recordDosing($tid, $this->utc('2026-12-10 06:00:00'), 3700.0, $lot);

        // 4. a real photo on disk for the reading sets
        $photo = ApId::uuid();
        $bytes = 'jpeg-bytes-for-the-instrument-photo';
        file_put_contents($this->dir . "/$photo.jpg", $bytes);
        $this->db->insert('ap_files', ['id' => $photo, 'purpose' => 'reading_photo', 'stored_name' => "$photo.jpg", 'mime' => 'image/jpeg', 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)]);
        $vals = function (array $perContainer) use ($tcs): array { $o = []; foreach ($tcs as $i => $id) { $v = $perContainer[$i] ?? $perContainer['default']; $o[$id] = ['top' => $v, 'middle' => $v, 'bottom' => $v]; } return $o; };

        // 5. start point, then the 24 h set: container 3 at 5 g/m3 is below the standard (14.4) and under the top-up floor (9.4)
        $sp = ApTreatments::recordReadingSet($tid, ApId::uuid(), 'start_point', $this->utc('2026-12-10 06:30:00'), $vals(['default' => '45']), [$photo], 'TO');
        $this->assertTrue($sp['startPointReached']);
        $end = ApTreatments::recordReadingSet($tid, ApId::uuid(), 'end_exposure', $this->utc('2026-12-11 06:30:00'), $vals(['default' => '15', 2 => '5']), [$photo], 'TO');
        $this->assertFalse($end['allMeet']);
        $this->assertFalse($end['topUp']['possible']);

        // 6. ventilation, TLV for all, close
        ApTreatments::recordVentilation($tid, $this->utc('2026-12-11 07:00:00'));
        foreach ($tcs as $id) { ApTreatments::recordTlv($tid, $id, 3.0, $this->utc('2026-12-11 09:00:00')); }
        $res = ApTreatments::close($tid);
        $this->assertSame('partial', $res['overall']);
        $this->assertSame('fail', $this->db->fetchOne('SELECT result FROM ap_treatment_containers WHERE id = ?', [$tcs[2]])['result']);
        $this->assertSame('completed', $this->db->fetchOne('SELECT status FROM ap_requests WHERE id = ?', [$req])['status']);

        // 7. invoice was raised on close: five lines (the failed container is billed too), as a draft
        $link = $this->db->fetchOne('SELECT * FROM ap_invoice_links WHERE request_id = ?', [$req]);
        $this->assertSame('draft', $link['state']);
        $ref = $this->db->fetchOne('SELECT reference FROM ap_requests WHERE id = ?', [$req])['reference'];
        $inv = $this->invoicesByRef[$ref];
        $this->assertCount(5, $inv['items']);
        $this->assertSame('draft', $inv['status']);
        $this->assertEquals(690.0, (float)$inv['total']);

        // 8. no certificates before both signatures
        $none = ApCertificates::issueEligible($tid, $this->utc('2026-12-12 00:00:00'));
        $this->assertSame([], $none['issued']);

        // 9. sign as fumigator and as officer
        $signing = new ApSigning(fn (string $user, string $pw, ?string $mfa): bool => $user === 'user-1' && $pw === 'right');
        $this->assertTrue($signing->fumigatorSign($tid, 'user-1', 'right', null, $this->utc('2026-12-11 12:00:00')));
        $this->assertTrue($signing->officerSign($tid, 'J Naidu', 'Biosecurity Officer', $this->png(), 'user-1', $this->utc('2026-12-11 12:05:00')));
        $this->assertTrue($signing->isFullySigned($tid));

        // 10. four certificates, unique numbers, none for container 3; emailed once
        $out = ApCertificates::issueEligible($tid, $this->utc('2026-12-12 00:00:00'));
        $this->assertCount(4, $out['issued']);
        $this->assertCount(4, array_unique($out['issued']));
        $this->assertNull($this->db->fetchOne('SELECT id FROM ap_certificates WHERE container_id = ?', [$tcs[2]]));
        $this->assertCount(1, $this->mails);
        $this->assertCount(4, $this->mails[0]['attachments']);
        $again = ApCertificates::issueEligible($tid, $this->utc('2026-12-12 01:00:00'));
        $this->assertSame([], $again['issued'], 'a repeat creates no second certificate');

        // 11. release the draft: ERPX is told once, state becomes released and the invoice is unpaid
        ApInvoices::release($req, 'admin1');
        ApInvoices::release($req, 'admin2');
        $this->assertCount(1, array_filter($this->erpxCalls, fn ($c) => $c[0] === 'PATCH'));
        $this->assertSame('released', $this->db->fetchOne('SELECT state FROM ap_invoice_links WHERE request_id = ?', [$req])['state']);
        $this->assertSame('unpaid', $this->invoicesByRef[$ref]['status_now']);

        // 12. audit pack lists everything, integrity ok
        $zip = new ZipArchive();
        $path = ApAuditPack::build($tid);
        $this->assertTrue($zip->open($path) === true);
        $m = json_decode((string)$zip->getFromName('manifest.json'), true);
        $this->assertSame('ok', $m['integrity']['status']);
        $this->assertSame(hash('sha256', $bytes), $m['photos'][0]['sha256']);
        $this->assertCount(4, json_decode((string)$zip->getFromName('certificates.json'), true));
        $this->assertCount(2, json_decode((string)$zip->getFromName('readings.json'), true));
        $actions = array_column(json_decode((string)$zip->getFromName('activity_log.json'), true), 'audit_action');
        foreach (['treatment.prechecked', 'treatment.dosed', 'treatment.readings', 'treatment.closed', 'treatment.signed.fumigator', 'treatment.signed.officer', 'certificate.issued'] as $a) {
            $this->assertContains($a, $actions);
        }
        $zip->close();
        @unlink($path);
    }
}
