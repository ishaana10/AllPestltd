<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApRequests'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApIntakeTest extends TestCase
{
    private ApTestDb $db;
    private string $dir;
    /** @var array<int,array{0:string,1:string,2:string}> */
    private array $mail = [];
    /** @var string[] */
    private array $tmp = [];

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->dir = sys_get_temp_dir() . '/ap_in_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        ApUploads::configure($this->db, $this->dir);
        ApSettings::set('staff_email', 'ops@allpest.test');
        $this->mail = [];
        ApRequests::setMailer(function (string $to, string $subject, string $body): void { $this->mail[] = [$to, $subject, $body]; });
    }

    protected function tearDown(): void
    {
        foreach (array_merge($this->tmp, glob($this->dir . '/{,.}*', GLOB_BRACE) ?: []) as $f) { if (is_file($f)) { @unlink($f); } }
        @rmdir($this->dir);
        ApRequests::setMailer(null);
    }

    private function consignments(int $n): array
    {
        $c = [];
        for ($i = 1; $i <= $n; $i++) {
            $c[] = ['container_no' => sprintf('MSKU%07d', $i), 'seal_no' => "S$i", 'commodity' => 'Logs', 'origin' => 'NZ', 'destination' => 'FJ', 'quantity' => '20 t'];
        }
        return $c;
    }

    private function input(array $over = [], int $containers = 1): array
    {
        return array_merge([
            'customer_name' => 'Pacific Timber Ltd', 'customer_email' => 'buyer@example.com', 'customer_phone' => '+679 999 0000',
            'customer_tin' => '12-34567-8-9', 'customer_address' => 'Lautoka', 'site_address' => 'Queens Wharf, Lautoka',
            'requested_date' => '2026-12-10', 'client_token' => bin2hex(random_bytes(8)), 'website' => '',
            'consignments' => $this->consignments($containers),
        ], $over);
    }

    private function jpegFile(): array
    {
        $im = imagecreatetruecolor(300, 200);
        imagefilledrectangle($im, 10, 10, 200, 100, 0xAA3355);
        ob_start(); imagejpeg($im); $b = (string)ob_get_clean();
        $p = tempnam(sys_get_temp_dir(), 'apb'); file_put_contents($p, $b); $this->tmp[] = $p;
        return ['name' => 'bol.jpg', 'tmp_name' => $p, 'error' => 0, 'size' => strlen($b)];
    }

    private function textFile(): array
    {
        $p = tempnam(sys_get_temp_dir(), 'apb'); file_put_contents($p, 'not an image'); $this->tmp[] = $p;
        return ['name' => 'bol.jpg', 'tmp_name' => $p, 'error' => 0, 'size' => 12];
    }

    private function count(string $table): int
    {
        return (int)$this->db->fetchOne("SELECT COUNT(*) AS n FROM $table")['n'];
    }

    public function testValidFiveContainerRequestStoresFiveConsignments(): void
    {
        $r = ApRequests::submitPublic($this->input([], 5), [0 => $this->jpegFile()], '1.1.1.1');
        $this->assertMatchesRegularExpression('/^AP-\d{6}-\d{4}$/', $r['reference']);
        $this->assertSame(5, $this->count('ap_consignments'));
        $req = $this->db->fetchOne('SELECT * FROM ap_requests WHERE id = ?', [$r['request_id']]);
        $this->assertSame('new', $req['status']);
        $this->assertSame('fumigation', $req['job_type']);
        $this->assertSame(gmdate('Ym'), substr(str_replace('AP-', '', $r['reference']), 0, 6));
        $bol = $this->db->fetchOne('SELECT bol_file_id FROM ap_consignments WHERE container_no = ?', ['MSKU0000001']);
        $this->assertNotNull($bol['bol_file_id']);
    }

    public function testReferencesIncrementWithinTheMonth(): void
    {
        $a = ApRequests::submitPublic($this->input(), [], '1.1.1.1');
        $b = ApRequests::submitPublic($this->input(), [], '1.1.1.2');
        $this->assertNotSame($a['reference'], $b['reference']);
        $this->assertSame(1, (int)substr($b['reference'], -4) - (int)substr($a['reference'], -4));
    }

    public function testFilledHoneypotIsRejectedAndStoresNothing(): void
    {
        try {
            ApRequests::submitPublic($this->input(['website' => 'http://spam']), [], '1.1.1.1');
            $this->fail('expected rejection');
        } catch (ApIntakeRejected $e) {
            $this->assertSame(0, $this->count('ap_requests'));
        }
    }

    public function testSixthSubmissionFromOneIpWithinAnHourIsRejected(): void
    {
        for ($i = 0; $i < 5; $i++) { ApRequests::submitPublic($this->input(), [], '9.9.9.9'); }
        $this->expectException(ApIntakeRejected::class);
        ApRequests::submitPublic($this->input(), [], '9.9.9.9');
    }

    public function testOtherIpIsNotThrottledAndOldAttemptsExpire(): void
    {
        for ($i = 0; $i < 5; $i++) { ApRequests::submitPublic($this->input(), [], '9.9.9.9'); }
        ApRequests::submitPublic($this->input(), [], '8.8.8.8');
        $this->db->exec("UPDATE ap_intake_throttle SET created_at = '2000-01-01 00:00:00' WHERE ip = '9.9.9.9'");
        $r = ApRequests::submitPublic($this->input(), [], '9.9.9.9');
        $this->assertNotEmpty($r['reference']);
    }

    public function testFiftyOneConsignmentsRejected(): void
    {
        $this->expectException(ApIntakeRejected::class);
        ApRequests::submitPublic($this->input([], 51), [], '1.1.1.1');
    }

    public function testFiftyConsignmentsAccepted(): void
    {
        ApRequests::submitPublic($this->input([], 50), [], '1.1.1.1');
        $this->assertSame(50, $this->count('ap_consignments'));
    }

    public function testNoConsignmentsRejected(): void
    {
        $this->expectException(ApIntakeRejected::class);
        ApRequests::submitPublic($this->input(['consignments' => []]), [], '1.1.1.1');
    }

    public function testMoreThanTenFilesRejected(): void
    {
        $files = [];
        for ($i = 0; $i < 11; $i++) { $files[$i] = $this->jpegFile(); }
        $this->expectException(ApIntakeRejected::class);
        ApRequests::submitPublic($this->input([], 11), $files, '1.1.1.1');
    }

    public function testNonImageBolRejectedAndNoRowsRemain(): void
    {
        try {
            ApRequests::submitPublic($this->input([], 2), [0 => $this->jpegFile(), 1 => $this->textFile()], '1.1.1.1');
            $this->fail('expected rejection');
        } catch (ApIntakeRejected $e) {
            $this->assertSame(0, $this->count('ap_requests'));
            $this->assertSame(0, $this->count('ap_consignments'));
            $this->assertSame(0, $this->count('ap_files'));
            $this->assertSame([], array_values(array_filter(glob($this->dir . '/*') ?: [], 'is_file')));
        }
    }

    public function testInvalidEmailAndMissingNameRejected(): void
    {
        foreach ([['customer_email' => 'not-an-email'], ['customer_name' => '  '], ['site_address' => '']] as $bad) {
            try {
                ApRequests::submitPublic($this->input($bad), [], '1.1.1.1');
                $this->fail('expected rejection for ' . json_encode($bad));
            } catch (ApIntakeRejected $e) {
                $this->assertSame(0, $this->count('ap_requests'));
            }
        }
    }

    public function testSameClientTokenDoesNotDuplicate(): void
    {
        $in = $this->input([], 2);
        $a = ApRequests::submitPublic($in, [], '1.1.1.1');
        $b = ApRequests::submitPublic($in, [], '1.1.1.1');
        $this->assertSame($a['request_id'], $b['request_id']);
        $this->assertSame(1, $this->count('ap_requests'));
        $this->assertSame(2, $this->count('ap_consignments'));
        $this->assertCount(2, $this->mail, 'emails are sent once, not on the replay');
    }

    public function testStaffAndCustomerEmailsAreSent(): void
    {
        $r = ApRequests::submitPublic($this->input(), [], '1.1.1.1');
        $to = array_column($this->mail, 0);
        $this->assertContains('ops@allpest.test', $to);
        $this->assertContains('buyer@example.com', $to);
        foreach ($this->mail as $m) { $this->assertStringContainsString($r['reference'], $m[1] . $m[2]); }
    }

    public function testMailFailureDoesNotLoseTheRequest(): void
    {
        ApRequests::setMailer(function (): void { throw new RuntimeException('smtp down'); });
        $r = ApRequests::submitPublic($this->input(), [], '1.1.1.1');
        $this->assertSame(1, $this->count('ap_requests'));
        $this->assertNotEmpty($r['reference']);
    }

    public function testHtmlInInputIsStoredVerbatimNotExecutedInEmail(): void
    {
        ApRequests::submitPublic($this->input(['customer_name' => '<script>x</script> Co']), [], '1.1.1.1');
        foreach ($this->mail as $m) { $this->assertStringNotContainsString('<script>', $m[2]); }
    }
}
