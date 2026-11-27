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
}
