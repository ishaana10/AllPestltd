<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApNum', 'ApRequests', 'ApTechnicians', 'ApEquipment', 'ApSchedules', 'ApStock',
          'ApStandards', 'ApDose', 'ApReadings', 'ApTemperature', 'ApResult', 'ApTreatments'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

/** Final whole-branch review fixes (Critical/Important). */
final class ApReviewFixesTest extends TestCase
{
    private function rejects(mixed $v): void
    {
        try { ApNum::strict($v, 'X'); $this->assertTrue(false, 'should have been rejected: ' . var_export($v, true)); }
        catch (InvalidArgumentException $e) { $this->assertTrue(true); }
    }

    public function testStrictNumberAcceptsPlainNumbersOnly(): void
    {
        $this->assertSame(4.5, ApNum::strict(' 4.5 ', 'X'));
        $this->assertSame(0.0, ApNum::strict('0', 'X'));
        $this->assertSame(-3.0, ApNum::strict('-3', 'X'));
        $this->assertSame(12.0, ApNum::strict(12, 'X'));
        foreach (['', '>4', 'abc', '1,500', '1e3', '4.', null, [], 'NaN'] as $bad) { $this->rejects($bad); }
    }

    public function testClientForecastKeepsOnlyTheTypedMinimumAndNote(): void
    {
        $f = ApTreatments::sanitizeClientForecast(['manual_min_c' => 25, 'source_note' => 'BoM', 'retrieved_at' => '2099-01-01', 'hourly' => [1], 'source' => 'x']);
        $this->assertSame(['manual_min_c' => 25, 'source_note' => 'BoM'], $f);
        $this->assertSame([], ApTreatments::sanitizeClientForecast('auto-ish'));
    }

    public function testOfflineQueueSurfacesRejectedSetsAndNeverStallsOrLosesReadings(): void
    {
        $node = trim((string)shell_exec('command -v node 2>/dev/null'));
        if ($node === '') { $this->assertTrue(true); return; }   // node not installed here: the script can still be run by hand
        $out = [];
        exec(escapeshellarg($node) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/js/queue.test.js') . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testPublicFormShowsTheServersErrorFieldNotJustMessage(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 3) . '/public_allpest_request.php');
        $this->assertStringContainsString('j.message||j.error', $page);
    }

    public function testSidebarEntryOpensTheAllPestModuleInsteadOfLookingForAForm(): void
    {
        $root = dirname(__DIR__, 3);
        $this->assertTrue(is_file($root . '/modules/allpest/allpest.php'));
        $this->assertStringContainsString("'allpest'", (string)file_get_contents($root . '/assets/js/nubuilder-next.js'));
    }

    public function testTreatmentWorkspaceIsAStudioModuleWithAccessCheckAndScopedStyles(): void
    {
        $root = dirname(__DIR__, 3);
        $m = (string)file_get_contents($root . '/modules/allpest_work/allpest_work.php');
        $this->assertStringContainsString('core/module_bootstrap.php', $m);
        $this->assertStringContainsString("'treatment.record'", $m);
        $this->assertStringContainsString("'allpest_work'", (string)file_get_contents($root . '/assets/js/nubuilder-next.js'));
        foreach (preg_split('/}/', (string)file_get_contents($root . '/assets/css/allpest-studio.css')) as $rule) {
            $sel = trim((string)strstr($rule, '{', true));
            if ($sel === '' || str_starts_with($sel, '/*') || str_starts_with($sel, '.ap-toast')) { continue; }
            $this->assertStringStartsWith('.ap-scope', $sel, 'unscoped rule: ' . $sel);
        }
    }

    private function dbWithRequest(?string $containerList): array
    {
        $db = new ApTestDb();
        ApSchema::ensure($db);
        ApDb::set($db);
        $id = ApId::uuid();
        $db->insert('ap_requests', ['id' => $id, 'reference' => 'AP-W-' . substr($id, 0, 5), 'customer_name' => 'X', 'customer_email' => 'x@example.com', 'container_list' => $containerList]);
        return [$db, $id];
    }

    public function testContainerListFromThePublicFormBecomesConsignmentsExactlyOnce(): void
    {
        [$db, $id] = $this->dbWithRequest("MSKU1234567, SEAL9, Timber, Auckland, Lautoka, 40 ft\n\n  TGHU7654321 ; ; Logs\n");
        $this->assertSame(1, ApRequests::importContainerLists());
        $rows = $db->fetchAll('SELECT container_no, seal_no, commodity, origin, destination, quantity FROM ap_consignments WHERE request_id = ? ORDER BY container_no', [$id]);
        $this->assertCount(2, $rows);
        $this->assertSame(['MSKU1234567', 'SEAL9', 'Timber', 'Auckland', 'Lautoka', '40 ft'], array_values($rows[0]));
        $this->assertSame('TGHU7654321', $rows[1]['container_no']);
        $this->assertSame(0, ApRequests::importContainerLists());                 // already imported: nothing more
        $this->assertSame(2, (int)$db->fetchOne('SELECT COUNT(*) AS n FROM ap_consignments')['n']);
    }

    public function testRequestsWithoutAContainerListAreLeftAlone(): void
    {
        [$db] = $this->dbWithRequest('   ');
        $this->assertSame(0, ApRequests::importContainerLists());
        $this->assertSame(0, (int)$db->fetchOne('SELECT COUNT(*) AS n FROM ap_consignments')['n']);
    }
}
