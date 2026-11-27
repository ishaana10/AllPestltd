<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests', 'ApTechnicians', 'ApEquipment', 'ApSchedules', 'ApStock',
          'ApStandards', 'ApDose', 'ApReadings', 'ApTemperature', 'ApResult', 'ApTreatments', 'ApSigning'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApSigningTest extends TestCase
{
    private ApTestDb $db;
    private string $dir;
    private string $tid;
    private string $tech;
    private array $audit = [];
    private array $verifyCalls = [];
    private ApSigning $sign;

    private function utc(string $s): DateTimeImmutable { return new DateTimeImmutable($s, new DateTimeZone('UTC')); }

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->dir = sys_get_temp_dir() . '/ap_sg_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        ApUploads::configure($this->db, $this->dir);
        ApRequests::setMailer(function (): void {});
        ApTreatments::setClock(fn () => $this->utc('2026-12-12 00:00:00'));
        $this->audit = [];
        ApTreatments::setAuditor(function (string $a, string $tid, array $d): void { $this->audit[] = $a; });
        $this->verifyCalls = [];
        $this->sign = new ApSigning(function (string $user, string $pw, ?string $mfa): bool {
            $this->verifyCalls[] = [$user, $pw, $mfa];
            return $pw === 'right' && ($mfa === null || $mfa === '123456');
        });

        $r = ApRequests::submitPublic(['customer_name' => 'PT', 'customer_email' => 'b@example.com', 'site_address' => 'W', 'client_token' => bin2hex(random_bytes(5)),
            'consignments' => [['container_no' => 'MSKU0000001'], ['container_no' => 'MSKU0000002']]], [], '1.1.1.1');
        $cons = array_column($this->db->fetchAll('SELECT id FROM ap_consignments WHERE request_id = ?', [$r['request_id']]), 'id');
        $this->tech = ApTechnicians::create(['user_id' => 'user-1', 'full_name' => 'T One', 'registration_no' => 'R1', 'registration_expiry' => '2099-12-31']);
        $officer = ApTechnicians::addOfficer('J Naidu', 'Biosecurity Officer');
        $sched = ApSchedules::save(['name' => 'G', 'treatment_type' => 'general', 'dose_rate' => 48, 'exposure_hours' => 24, 'min_temp_c' => 21, 'retention_class' => 'standard']);
        ApRequests::confirm($r['request_id'], [], 'a');
        $this->tid = ApRequests::createTreatment($r['request_id'], $cons, ['scheduled_start' => '2026-12-10 06:00:00', 'technician_id' => $this->tech, 'officer_id' => $officer,
            'schedule_id' => $sched, 'rof_type' => 'general', 'enclosure_type' => 'container_stack', 'fumigation_type' => 'ambient']);
        $this->closeIt();
    }

    protected function tearDown(): void
    {
        ApTreatments::setClock(null);
        ApTreatments::setAuditor(null);
        ApRequests::setMailer(null);
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
        @rmdir($this->dir);
    }

    private function closeIt(): void
    {
        $this->db->exec("UPDATE ap_treatments SET status = 'closed', result = 'pass', height_m = 3, depth_m = 2.5, length_m = 10, volume_m3 = 75 WHERE id = '{$this->tid}'");
    }

    private function png(int $strokePixels = -1, bool $blank = false): string
    {
        $im = imagecreatetruecolor(300, 100);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $black = imagecolorallocatealpha($im, 0, 0, 0, 0);
        if (!$blank) {
            if ($strokePixels < 0) { imagesetthickness($im, 3); imageline($im, 10, 80, 280, 20, $black); }
            else { for ($i = 0; $i < $strokePixels; $i++) { imagesetpixel($im, 10 + $i, 10, $black); } }
        }
        ob_start(); imagepng($im); $b = (string)ob_get_clean();
        return 'data:image/png;base64,' . base64_encode($b);
    }

    private function fumigatorSigns(string $user = 'user-1', string $pw = 'right', ?string $mfa = null): bool
    {
        return $this->sign->fumigatorSign($this->tid, $user, $pw, $mfa, $this->utc('2026-12-11 12:00:00'));
    }

    private function officerSigns(string $name = 'J Naidu', string $des = 'Biosecurity Officer', ?string $png = null, string $user = 'user-1'): bool
    {
        return $this->sign->officerSign($this->tid, $name, $des, $png ?? $this->png(), $user, $this->utc('2026-12-11 12:05:00'));
    }

    private function sigCount(): int { return (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_signatures')['n']; }

    // ---- fumigator ------------------------------------------------------------------------------------------------

    public function testWrongPasswordRefusesAndLeavesTheRofUnlocked(): void
    {
        try { $this->fumigatorSigns('user-1', 'wrong'); $this->fail('expected refusal'); } catch (ApSigningRefused $e) { $this->assertTrue(true); }
        $this->assertSame(0, $this->sigCount());
        $this->assertFalse($this->sign->isLocked($this->tid));
    }

    public function testAnotherTechnicianCannotSign(): void
    {
        ApTechnicians::create(['user_id' => 'user-2', 'full_name' => 'T Two', 'registration_no' => 'R2', 'registration_expiry' => '2099-12-31']);
        $this->expectException(ApSigningRefused::class);
        $this->fumigatorSigns('user-2', 'right');
    }

    public function testExpiredRegistrationCannotSign(): void
    {
        $this->db->exec("UPDATE ap_technicians SET registration_expiry = '2026-12-01'");
        $this->expectException(ApSigningRefused::class);
        $this->fumigatorSigns();
    }

    public function testCannotSignBeforeTheTreatmentIsClosed(): void
    {
        $this->db->exec("UPDATE ap_treatments SET status = 'ventilating' WHERE id = '{$this->tid}'");
        $this->expectException(ApSigningRefused::class);
        $this->fumigatorSigns();
    }

    public function testFumigatorSignatureIsStoredLocksTheRofAndPassesTheMfaCodeThrough(): void
    {
        $this->assertTrue($this->fumigatorSigns('user-1', 'right', '123456'));
        $this->assertSame([['user-1', 'right', '123456']], $this->verifyCalls);
        $row = $this->db->fetchOne("SELECT * FROM ap_signatures WHERE role = 'fumigator'");
        $this->assertSame('T One', $row['signer_name']);
        $this->assertSame('user-1', $row['user_id']);
        $this->assertSame('2026-12-11 12:00:00', $row['signed_at']);
        $this->assertSame(1, (int)$row['version']);
        $this->assertTrue($this->sign->isLocked($this->tid));
        $this->assertContains('treatment.signed.fumigator', $this->audit);
    }

    public function testDoubleClickSignsOnce(): void
    {
        $this->assertTrue($this->fumigatorSigns());
        $this->assertFalse($this->fumigatorSigns());
        $this->assertSame(1, $this->sigCount());
    }

    public function testRofIsReadOnlyAfterTheFirstSignature(): void
    {
        $this->fumigatorSigns();
        $this->expectException(ApLockedException::class);
        ApTreatments::recordVentilation($this->tid, $this->utc('2026-12-11 07:00:00'));
    }

    // ---- officer --------------------------------------------------------------------------------------------------

    public function testBlankOrNearlyBlankSignatureImageIsRefused(): void
    {
        foreach ([$this->png(0, true), $this->png(29), 'data:image/png;base64,' . base64_encode('not a png'), 'data:text/plain;base64,aGk=', 'garbage'] as $bad) {
            try { $this->officerSigns('J Naidu', 'Officer', $bad); $this->fail('expected refusal'); } catch (ApSigningRefused $e) { $this->assertTrue(true); }
        }
        $this->assertSame(0, $this->sigCount());
        $this->assertFalse($this->sign->isLocked($this->tid));
    }

    public function testSignatureWithThirtyPixelsIsAccepted(): void
    {
        $this->assertTrue($this->officerSigns('J Naidu', 'Officer', $this->png(30)));
    }

    public function testOfficerNeedsNameAndDesignation(): void
    {
        foreach ([['', 'Officer'], ['J Naidu', ''], ['  ', ' ']] as [$n, $d]) {
            try { $this->officerSigns($n, $d); $this->fail('expected refusal'); } catch (ApSigningRefused $e) { $this->assertTrue(true); }
        }
        $this->assertSame(0, $this->sigCount());
    }

    public function testOnlyTheTreatmentsTechnicianCanCaptureTheOfficerSignature(): void
    {
        $this->expectException(ApSigningRefused::class);
        $this->officerSigns('J Naidu', 'Officer', null, 'someone-else');
    }

    public function testOfficerSignatureStoresASmallPngAndWhoWhenWhere(): void
    {
        $this->assertTrue($this->officerSigns());
        $row = $this->db->fetchOne("SELECT * FROM ap_signatures WHERE role = 'officer'");
        $this->assertSame('J Naidu', $row['signer_name']);
        $this->assertSame('Biosecurity Officer', $row['signer_designation']);
        $this->assertSame('user-1', $row['user_id']);
        $this->assertSame('2026-12-11 12:05:00', $row['signed_at']);
        $this->assertNotEmpty($row['device']);
        $path = ApUploads::path((string)$row['image_file_id']);
        $this->assertSame(IMAGETYPE_PNG, getimagesize($path)[2]);
        $this->assertLessThan(60000, filesize($path));
        $f = $this->db->fetchOne('SELECT purpose, mime, sha256 FROM ap_files WHERE id = ?', [$row['image_file_id']]);
        $this->assertSame('signature', $f['purpose']);
        $this->assertSame('image/png', $f['mime']);
        $this->assertSame(hash_file('sha256', $path), $f['sha256']);
        $this->assertTrue($this->sign->isLocked($this->tid));
        $this->assertContains('treatment.signed.officer', $this->audit);
    }

    public function testOfficerDoubleSubmitKeepsTheFirstSignature(): void
    {
        $this->assertTrue($this->officerSigns());
        $this->assertFalse($this->officerSigns());
        $this->assertSame(1, $this->sigCount());
    }

    public function testFullySignedNeedsBoth(): void
    {
        $this->assertFalse($this->sign->isFullySigned($this->tid));
        $this->fumigatorSigns();
        $this->assertFalse($this->sign->isFullySigned($this->tid));
        $this->officerSigns();
        $this->assertTrue($this->sign->isFullySigned($this->tid));
    }

    // ---- corrections ----------------------------------------------------------------------------------------------

    public function testCorrectionNeedsAReasonAndASignedRof(): void
    {
        try { $this->sign->correct($this->tid, ['enclosure_type' => 'container_stack'], 'typo', 'user-1'); $this->fail('unsigned ROF is edited directly'); } catch (ApTreatmentError $e) { $this->assertTrue(true); }
        $this->fumigatorSigns();
        foreach (['', '   '] as $blank) {
            try { $this->sign->correct($this->tid, ['enclosure_type' => 'container_stack'], $blank, 'user-1'); $this->fail('reason required'); } catch (InvalidArgumentException $e) { $this->assertTrue(true); }
        }
        $this->assertSame(1, (int)$this->db->fetchOne('SELECT version FROM ap_treatments WHERE id = ?', [$this->tid])['version']);
    }

    public function testCorrectionCreatesVersionTwoKeepsVersionOneAndClearsSignatures(): void
    {
        $this->fumigatorSigns();
        $this->officerSigns();
        $this->assertTrue($this->sign->isFullySigned($this->tid));
        $v = $this->sign->correct($this->tid, ['length_m' => 11.0, 'volume_m3' => 82.5], 'Length was mis-measured', 'user-1');
        $this->assertSame(2, $v);
        $now = $this->db->fetchOne('SELECT version, length_m, volume_m3 FROM ap_treatments WHERE id = ?', [$this->tid]);
        $this->assertSame(2, (int)$now['version']);
        $this->assertEquals(11.0, (float)$now['length_m']);
        $old = $this->sign->version($this->tid, 1);
        $this->assertEquals(10.0, (float)$old['treatment']['length_m']);
        $this->assertEquals(75.0, (float)$old['treatment']['volume_m3']);
        $this->assertSame('Length was mis-measured', $this->db->fetchOne('SELECT reason FROM ap_treatment_versions WHERE treatment_id = ? AND version = 1', [$this->tid])['reason']);
        $this->assertFalse($this->sign->isFullySigned($this->tid));
        $this->assertSame([], $this->sign->signatures($this->tid));
        $this->assertSame(2, $this->sigCount(), 'the original signatures are kept as history');
        $this->assertContains('treatment.corrected', $this->audit);
    }

    public function testAfterACorrectionBothMustSignAgainAndOnlyTheNewSignaturesCount(): void
    {
        $this->fumigatorSigns();
        $this->officerSigns();
        $this->sign->correct($this->tid, ['enclosure_type' => 'container_stack'], 'wrong enclosure', 'user-1');
        $this->assertTrue($this->fumigatorSigns());
        $this->assertFalse($this->sign->isFullySigned($this->tid));
        $this->assertTrue($this->officerSigns());
        $this->assertTrue($this->sign->isFullySigned($this->tid));
        $sigs = $this->sign->signatures($this->tid);
        $this->assertSame(2, (int)$sigs['fumigator']['version']);
        $this->assertSame(4, $this->sigCount());
    }

    public function testCorrectionRejectsFieldsOutsideTheWhitelist(): void
    {
        $this->fumigatorSigns();
        foreach ([['status' => 'planned'], ['locked_at' => null], ['id' => 'x'], ['result' => 'pass']] as $bad) {
            try { $this->sign->correct($this->tid, $bad, 'reason', 'user-1'); $this->fail('expected rejection ' . json_encode($bad)); } catch (InvalidArgumentException $e) { $this->assertTrue(true); }
        }
        $this->assertSame(1, (int)$this->db->fetchOne('SELECT version FROM ap_treatments WHERE id = ?', [$this->tid])['version']);
    }

    public function testCorrectionRejectsInvalidValuesAndLeavesTheRecordUntouched(): void
    {
        $this->fumigatorSigns();
        foreach ([['volume_m3' => '-5'], ['dose_rate_used' => '0'], ['scheduled_start' => 'x'], ['equipment_ids' => 'junk'], ['height_m' => 'abc'],
                  ['mb_fraction' => '1.5'], ['site_lat' => '95'], ['fumigation_type' => 'wild'], ['officer_id' => 'nobody']] as $bad) {
            try { $this->sign->correct($this->tid, $bad, 'reason', 'user-1'); $this->fail('expected rejection ' . json_encode($bad)); } catch (InvalidArgumentException $e) { $this->assertTrue(true); }
        }
        $this->assertSame(1, (int)$this->db->fetchOne('SELECT version FROM ap_treatments WHERE id = ?', [$this->tid])['version']);
    }

    public function testEmptyCorrectionIsRejected(): void
    {
        $this->fumigatorSigns();
        $this->expectException(InvalidArgumentException::class);
        $this->sign->correct($this->tid, [], 'reason', 'user-1');
    }

    public function testIssueMessageHandlesSkippedAsAContainerMap(): void
    {
        $js = (string)file_get_contents(dirname(__DIR__, 3) . '/assets/js/allpest/signing.js');
        $this->assertStringNotContainsString("(r.skipped || []).length", $js, 'skipped is an object keyed by container, so .length is undefined');
        $this->assertStringContainsString('Object.keys(sk)', $js);
    }
}
