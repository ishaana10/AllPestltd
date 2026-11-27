<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApTechnicians', 'ApEquipment', 'ApSchedules', 'ApStock',
          'ApStandards', 'ApDose', 'ApReadings', 'ApTemperature', 'ApResult', 'ApTreatments', 'ApCertificateRenderer', 'ApCertificates', 'ApUploads', 'ApBranding'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApCertificatesTest extends TestCase
{
    private ApTestDb $db;
    private string $tid;
    /** @var string[] */
    private array $tcs;
    private array $mails = [];
    private bool $mailFails = false;
    private array $audit = [];

    private function utc(string $s): DateTimeImmutable { return new DateTimeImmutable($s, new DateTimeZone('UTC')); }

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        ApRequests::setMailer(function (): void {});
        $this->mails = [];
        $this->mailFails = false;
        $this->audit = [];
        ApTreatments::setAuditor(function (string $a, string $tid, array $d): void { $this->audit[] = $a; });
        ApCertificates::setMailer(function (string $to, string $subject, string $body, array $attachments): void {
            if ($this->mailFails) { throw new RuntimeException('smtp down'); }
            $this->mails[] = compact('to', 'subject', 'body', 'attachments');
        });

        $r = ApRequests::submitPublic(['customer_name' => 'Pacific Timber', 'customer_email' => 'buyer@example.com', 'customer_address' => 'Lautoka', 'site_address' => 'Queens Wharf, Lautoka',
            'client_token' => bin2hex(random_bytes(5)), 'consignments' => [
                ['container_no' => 'MSKU0000001', 'seal_no' => 'S1', 'commodity' => 'Logs', 'origin' => 'NZ', 'destination' => 'FJ', 'quantity' => '20 t'],
                ['container_no' => 'MSKU0000002', 'seal_no' => 'S2', 'commodity' => 'Logs', 'origin' => 'NZ', 'destination' => 'FJ', 'quantity' => '20 t'],
                ['container_no' => 'MSKU0000003', 'seal_no' => 'S3', 'commodity' => 'Logs', 'origin' => 'NZ', 'destination' => 'FJ', 'quantity' => '20 t']]], [], '1.1.1.1');
        $cons = array_column($this->db->fetchAll('SELECT id FROM ap_consignments WHERE request_id = ? ORDER BY container_no', [$r['request_id']]), 'id');
        $tech = ApTechnicians::create(['user_id' => 'user-1', 'full_name' => 'T One', 'registration_no' => 'R1', 'registration_expiry' => '2099-12-31']);
        $officer = ApTechnicians::addOfficer('J Naidu', 'Biosecurity Officer');
        $sched = ApSchedules::save(['name' => 'General 48 g/m3, 24 h', 'treatment_type' => 'general', 'dose_rate' => 48, 'exposure_hours' => 24, 'min_temp_c' => 21, 'retention_class' => 'standard']);
        ApRequests::confirm($r['request_id'], [], 'a');
        $this->tid = ApRequests::createTreatment($r['request_id'], $cons, ['scheduled_start' => '2026-12-10 06:00:00', 'technician_id' => $tech, 'officer_id' => $officer,
            'schedule_id' => $sched, 'rof_type' => 'general', 'enclosure_type' => 'container_stack', 'fumigation_type' => 'ambient']);
        $eq = ApId::uuid();
        $this->db->insert('ap_equipment', ['id' => $eq, 'kind' => 'detector', 'name' => 'Detector 1', 'serial_no' => 'ABC-12345']);
        $this->db->exec("UPDATE ap_treatments SET status = 'closed', result = 'partial', injected_at = '2026-12-10 06:00:00', start_point_at = '2026-12-10 06:30:00',
            ventilation_at = '2026-12-11 07:00:00', height_m = 3, depth_m = 2.5, length_m = 10, volume_m3 = 75, dose_rate_used = 48, applied_g = 3700, forecast_min_c = 25.5,
            equipment_ids = '[\"$eq\"]' WHERE id = '{$this->tid}'");
        $this->tcs = array_column($this->db->fetchAll('SELECT tc.id FROM ap_treatment_containers tc JOIN ap_consignments c ON c.id = tc.consignment_id WHERE tc.treatment_id = ? ORDER BY c.container_no', [$this->tid]), 'id');
        foreach ([[$this->tcs[0], 'pass', 3.0], [$this->tcs[1], 'pass', 4.0], [$this->tcs[2], 'fail', 6.0]] as [$id, $res, $tlv]) {
            $this->db->exec("UPDATE ap_treatment_containers SET result = '$res', tlv_ppm = $tlv, tlv_at = '2026-12-11 09:00:00' WHERE id = '$id'");
        }
        $this->sign('fumigator', 'T One', 'Fumigator-in-charge');
        $this->sign('officer', 'J Naidu', 'Biosecurity Officer');
    }

    protected function tearDown(): void
    {
        ApTreatments::setAuditor(null);
        ApRequests::setMailer(null);
        ApCertificates::setMailer(null);
    }

    private function sign(string $role, string $name, string $des, int $version = 1): void
    {
        $this->db->insert('ap_signatures', ['id' => ApId::uuid(), 'treatment_id' => $this->tid, 'role' => $role, 'signer_name' => $name, 'signer_designation' => $des,
            'user_id' => 'user-1', 'signed_at' => '2026-12-11 12:00:00', 'version' => $version]);
    }

    private function certCount(): int { return (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_certificates')['n']; }

    // ---- releasable -----------------------------------------------------------------------------------------------

    public function testPassWithBothSignaturesIsReleasable(): void
    {
        $this->assertSame(['ok' => true, 'missing' => []], ApCertificates::releasable($this->tcs[0], false));
    }

    public function testMissingOfficerSignatureIsListed(): void
    {
        $this->db->exec("DELETE FROM ap_signatures WHERE role = 'officer'");
        $this->assertSame(['ok' => false, 'missing' => ['officer_signature']], ApCertificates::releasable($this->tcs[0], false));
    }

    public function testMissingFumigatorSignatureIsListed(): void
    {
        $this->db->exec("DELETE FROM ap_signatures WHERE role = 'fumigator'");
        $this->assertSame(['ok' => false, 'missing' => ['fumigator_signature']], ApCertificates::releasable($this->tcs[1], false));
    }

    public function testFailedContainerIsNeverReleasable(): void
    {
        $r = ApCertificates::releasable($this->tcs[2], false);
        $this->assertFalse($r['ok']);
        $this->assertContains('pass', $r['missing']);
    }

    public function testAdminVerificationWhenRequired(): void
    {
        $this->assertSame(['admin_verification'], ApCertificates::releasable($this->tcs[0], true)['missing']);
        ApCertificates::verify($this->tcs[0], 'admin1');
        $this->assertSame(['ok' => true, 'missing' => []], ApCertificates::releasable($this->tcs[0], true));
        $this->assertContains('certificate.verified', $this->audit);
        $this->assertSame(['ok' => true, 'missing' => []], ApCertificates::releasable($this->tcs[0], false));
    }

    public function testFailedContainerCannotBeVerified(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApCertificates::verify($this->tcs[2], 'admin1');
    }

    public function testSignaturesFromAnEarlierVersionDoNotCount(): void
    {
        $this->db->exec("UPDATE ap_treatments SET version = 2 WHERE id = '{$this->tid}'");
        $this->assertSame(['fumigator_signature', 'officer_signature'], ApCertificates::releasable($this->tcs[0], false)['missing']);
    }

    // ---- issuing --------------------------------------------------------------------------------------------------

    public function testOneCertificatePerPassingContainerAndNoneForTheFailed(): void
    {
        $r = ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $this->assertCount(2, $r['issued']);
        $this->assertSame(2, $this->certCount());
        $this->assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_certificates WHERE container_id = ?', [$this->tcs[2]])['n']);
        $this->assertArrayHasKey($this->tcs[2], $r['skipped']);
        $this->assertContains('pass', $r['skipped'][$this->tcs[2]]);
    }

    public function testIssuingTwiceCreatesNothingNewAndSendsNothingNew(): void
    {
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $again = ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:05:00'));
        $this->assertSame([], $again['issued']);
        $this->assertSame(2, $this->certCount());
        $this->assertSame(2, (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_certificate_numbers')['n']);
        $this->assertCount(1, $this->mails);
    }

    public function testNumbersFollowTheFormatAndIncrease(): void
    {
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $nums = array_column($this->db->fetchAll('SELECT number FROM ap_certificates ORDER BY number'), 'number');
        $this->assertSame(['AP-2026-00001', 'AP-2026-00002'], $nums);
    }

    public function testAnAlreadyUsedNumberIsNeverReused(): void
    {
        $this->db->insert('ap_certificate_numbers', ['id' => ApId::uuid(), 'number' => 'AP-2026-00007', 'allocated_at' => '2026-12-01 00:00:00']);
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $nums = array_column($this->db->fetchAll('SELECT number FROM ap_certificates ORDER BY number'), 'number');
        $this->assertSame(['AP-2026-00008', 'AP-2026-00009'], $nums);
    }

    public function testNumberingRestartsEachYear(): void
    {
        $this->db->insert('ap_certificate_numbers', ['id' => ApId::uuid(), 'number' => 'AP-2026-00041', 'allocated_at' => '2026-12-01 00:00:00']);
        $r = ApCertificates::issueEligible($this->tid, $this->utc('2026-12-31 13:00:00')); // 01:00 on 1 Jan in Fiji
        $nums = array_column($this->db->fetchAll('SELECT number FROM ap_certificates ORDER BY number'), 'number');
        $this->assertSame(['AP-2027-00001', 'AP-2027-00002'], $nums);
    }

    public function testStoredHashEqualsTheHashOfTheRebuiltContent(): void
    {
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        foreach ($this->db->fetchAll('SELECT container_id, content_sha256 FROM ap_certificates') as $c) {
            $this->assertSame($c['content_sha256'], ApCertificates::recomputeHash($c['container_id']));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $c['content_sha256']);
        }
    }

    public function testDifferentContainersHaveDifferentHashes(): void
    {
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $h = array_column($this->db->fetchAll('SELECT content_sha256 FROM ap_certificates'), 'content_sha256');
        $this->assertCount(2, array_unique($h));
    }

    public function testCertificateContentCarriesTheFactSheetFields(): void
    {
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $d = ApCertificates::data($this->tcs[0]);
        $this->assertSame('AP-2026-00001', $d['number']);
        $this->assertSame('MSKU0000001', $d['consignment']['container_no']);
        $this->assertSame('S1', $d['consignment']['seal_no']);
        $this->assertSame('Pacific Timber', $d['client']['name']);
        $this->assertSame('Queens Wharf, Lautoka', $d['place']);
        $this->assertSame('48.00', $d['schedule']['dose_rate']);
        $this->assertSame('48.00', $d['applied']['dose_rate_used']);
        $this->assertSame('3700.00', $d['applied']['applied_g']);
        $this->assertSame('3.00', $d['tlv_ppm']);
        $this->assertSame('J Naidu', $d['signatures']['officer']['name']);
        $this->assertSame('2026-12-10 18:30', $d['times']['start_point_at'], 'times are shown in Fiji time (UTC+12)');
        $this->assertSame('24.50', $d['applied']['exposure_hours']);
        $this->assertSame('2345', $d['equipment'][0]['serial_last4']);
    }

    public function testIssuedDateIsShownAsDayMonthYearWithoutChangingTheStoredData(): void
    {
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $d = ApCertificates::data($this->tcs[0]);
        $this->assertTrue((bool)preg_match('/^2026-12-\d\d/', $d['issued_at']), 'stored data stays ISO so old hashes still match');
        $m = new ReflectionMethod(ApCertificateRenderer::class, 'dmy'); $m->setAccessible(true);
        $this->assertSame('12/12/2026', $m->invoke(null, '2026-12-12 01:00'));
    }

    public function testCertificateCarriesTheTemplateFields(): void
    {
        $this->db->update('ap_treatments', ['target_of_fumigation' => 'commodity,packaging', 'enclosure_other' => '', 'loc_street' => '1 Wharf Rd', 'loc_suburb' => 'Lautoka', 'loc_country' => 'Fiji', 'loc_postcode' => '0000'], 'id = ?', [$this->tid]);
        $this->db->update('ap_consignments', ['port_of_loading' => 'Auckland'], 'container_no = ?', ['MSKU0000001']);
        ApSettings::set('certificate_additional_declarations', 'Treated at the stated place.');
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $d = ApCertificates::data($this->tcs[0]);
        $this->assertSame(['commodity', 'packaging'], $d['targets']);
        $this->assertSame('Auckland', $d['consignment']['port_of_loading']);
        $this->assertSame('1 Wharf Rd', $d['place_parts']['street']);
        $this->assertSame('Lautoka', $d['place_parts']['suburb']);
        $this->assertSame('Treated at the stated place.', $d['additional_declarations']);
        $this->assertTrue(array_key_exists('accreditation_no', $d));
        $this->assertStringStartsWith('%PDF', ApCertificateRenderer::render($d));
    }

    public function testCustomerGetsOneEmailWithAPdfPerCertificate(): void
    {
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $this->assertCount(1, $this->mails);
        $m = $this->mails[0];
        $this->assertSame('buyer@example.com', $m['to']);
        $this->assertCount(2, $m['attachments']);
        foreach ($m['attachments'] as $a) {
            $this->assertSame('application/pdf', $a['mimetype']);
            $this->assertStringStartsWith('%PDF', $a['data']);
            $this->assertMatchesRegularExpression('/^Certificate-AP-2026-0000[12]\.pdf$/', $a['filename']);
        }
        $this->assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_certificates WHERE email_pending = 1')['n']);
        $this->assertNotNull($this->db->fetchOne('SELECT emailed_at FROM ap_certificates LIMIT 1')['emailed_at']);
    }

    public function testEmailFailureLeavesTheCertificateIssuedAndPending(): void
    {
        $this->mailFails = true;
        $r = ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $this->assertCount(2, $r['issued']);
        $this->assertSame(2, (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_certificates WHERE email_pending = 1')['n']);
        $this->assertNull($this->db->fetchOne('SELECT emailed_at FROM ap_certificates LIMIT 1')['emailed_at']);
    }

    public function testPendingEmailsAreRetriedWithTheSameNumbers(): void
    {
        $this->mailFails = true;
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $before = array_column($this->db->fetchAll('SELECT number FROM ap_certificates ORDER BY number'), 'number');
        $this->mailFails = false;
        $this->assertSame(2, ApCertificates::sendPending());
        $this->assertSame($before, array_column($this->db->fetchAll('SELECT number FROM ap_certificates ORDER BY number'), 'number'));
        $this->assertSame(0, (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_certificates WHERE email_pending = 1')['n']);
        $this->assertCount(1, $this->mails);
        $this->assertSame(0, ApCertificates::sendPending());
    }

    public function testChangedDataIsNeverSentAsTheIssuedCertificate(): void
    {
        $this->mailFails = true;
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $this->mailFails = false;
        $this->db->exec("UPDATE ap_treatments SET applied_g = applied_g + 100");          // data altered after issue (e.g. a later correction)
        $this->assertSame(0, ApCertificates::sendPending());
        $this->assertCount(0, $this->mails);
        $this->assertSame(2, (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_certificates WHERE email_pending = 1')['n']);
    }

    public function testNothingIsIssuedUntilBothSignaturesExist(): void
    {
        $this->db->exec("DELETE FROM ap_signatures WHERE role = 'officer'");
        $r = ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $this->assertSame([], $r['issued']);
        $this->assertSame(0, $this->certCount());
        $this->assertSame([], $this->mails);
    }

    public function testAdminVerificationSettingHoldsCertificatesUntilVerified(): void
    {
        ApSettings::set('certificate_admin_verify', '1');
        $this->assertSame([], ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'))['issued']);
        ApCertificates::verify($this->tcs[0], 'admin1');
        $r = ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:10:00'));
        $this->assertCount(1, $r['issued']);
        $this->assertSame('admin1', $this->db->fetchOne('SELECT verified_by FROM ap_certificates')['verified_by']);
    }

    public function testIssuingIsAudited(): void
    {
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $this->assertContains('certificate.issued', $this->audit);
    }

    // ---- renderer -------------------------------------------------------------------------------------------------

    public function testRendererProducesAPdf(): void
    {
        ApCertificates::issueEligible($this->tid, $this->utc('2026-12-11 13:00:00'));
        $pdf = ApCertificateRenderer::render(ApCertificates::data($this->tcs[0]));
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(1000, strlen($pdf));
    }

    public function testRendererEscapesHtmlInCustomerFields(): void
    {
        $d = ApCertificates::data($this->tcs[0], 'AP-2026-99999', $this->utc('2026-12-11 13:00:00'));
        $d['client']['name'] = '<script>alert(1)</script> & Co';
        $pdf = ApCertificateRenderer::render($d);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function testBrandingIsRenderOnlyAndLogoIsPrinted(): void
    {
        $d = ApCertificates::data($this->tcs[0], 'AP-2026-99998', $this->utc('2026-12-11 13:00:00'));
        $this->assertTrue(array_key_exists('_branding', $d));
        $before = ApCertificates::hash($d);
        $dir = sys_get_temp_dir() . '/ap_cb_' . bin2hex(random_bytes(3)); mkdir($dir);
        ApUploads::configure($this->db, $dir);
        $im = imagecreatetruecolor(60, 30); $p = tempnam(sys_get_temp_dir(), 'lg'); imagepng($im, $p);
        $r = ApUploads::store(['name' => 'l.png', 'type' => 'image/png', 'size' => filesize($p), 'tmp_name' => $p, 'error' => 0], 'other', 'u', 't');
        ApBranding::setLogo($r['file_id']);
        ApSettings::set('company_phone', '+679 666 0000');
        $d2 = ApCertificates::data($this->tcs[0], 'AP-2026-99998', $this->utc('2026-12-11 13:00:00'));
        $this->assertSame($before, ApCertificates::hash($d2), 'logo and contact details must not change the certificate hash');
        $this->assertTrue(is_file((string)$d2['_branding']['logo_path']));
        $this->assertStringContainsString('/Subtype /Image', ApCertificateRenderer::render($d2));
        foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
        @rmdir($dir);
    }
}
