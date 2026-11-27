<?php
declare(strict_types=1);
/** Wiring checks for api/form.php: form saves reach the studio audit trail and empty dates/numbers do not break MySQL. */
use PHPUnit\Framework\TestCase;

final class FormSaveHardeningTest extends TestCase
{
    private function src(): string { return (string)file_get_contents(__DIR__ . '/../../../api/form.php'); }

    public function testEveryFormWritePathIsAudited(): void
    {
        $s = $this->src();
        $this->assertTrue(substr_count($s, "nu_audit_change('create'") >= 2, 'main and subform inserts are audited');
        $this->assertTrue(substr_count($s, "nu_audit_change('update'") >= 2, 'main and subform updates are audited');
        $this->assertTrue(strpos($s, "nu_audit_change('delete'") !== false, 'subform deletes are audited');
    }

    public function testEmptyDatesAndNumbersAreNormalisedBeforeSave(): void
    {
        $s = $this->src();
        $this->assertTrue(strpos($s, 'function nu_get_column_meta') !== false);
        $this->assertTrue(strpos($s, "\$val === ''") !== false);
    }

    public function testRequestCodeLoadsItsOwnAuditClass(): void
    {
        // Each API script loads only the classes it names: ApRequests must pull in ApAudit itself, or confirm/schedule die after the update.
        $code = "require " . var_export(dirname(__DIR__, 3) . '/core/allpest/ApRequests.php', true) . "; echo class_exists('ApAudit') ? 'yes' : 'no';";
        $this->assertSame('yes', trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1')));
    }

    public function testPhotoQueueWorksUnderTheStudioSecurityPolicy(): void
    {
        $root = dirname(__DIR__, 3) . '/assets/js/allpest/';
        // CSP connect-src is 'self' https: : fetch() of a data: URL is blocked, so photos are decoded by hand.
        $this->assertSame(0, preg_match('/fetch\(\s*d\s*\)/', (string)file_get_contents($root . 'compress.js')));
        // the queue must follow window.AP_API, not a path that only works at the web root
        $q = (string)file_get_contents($root . 'queue.js');
        $this->assertTrue(strpos($q, 'window.AP_API') !== false);
        $this->assertSame(0, preg_match("/fetch\('\.\.\/\.\.\/api/", $q));
    }

    public function testSigningDoesNotNeedTheOptionalMfaColumnInTheQuery(): void
    {
        // usr_2fa_enabled only exists once MFA has been set up; selecting it by name made signing fail with a 500 on a fresh install.
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/api/allpest/sign.php');
        $this->assertSame(0, preg_match('/SELECT[^;]*usr_2fa_enabled[^;]*FROM nu_users/', $src));
    }

    public function testTreatmentApiLoadsInvoicingSoClosingTheLastRecordRaisesTheInvoice(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/api/allpest/treatments.php');
        $this->assertStringContainsString("'ApInvoices'", $src);
        $this->assertStringContainsString("'ApErpxClient'", $src);
    }
}
