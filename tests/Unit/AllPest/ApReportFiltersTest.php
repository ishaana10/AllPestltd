<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
require_once dirname(__DIR__, 3) . '/core/ReportFilters.php';

final class ApReportFiltersTest extends TestCase
{
    private ApTestDb $db;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        $this->db->exec('CREATE TABLE t (d TEXT, who TEXT, n INTEGER)');
        foreach ([['2026-11-01', 'Ann', 1], ['2026-11-15', 'Bob', 2], ['2026-12-01', 'Ann', 3]] as $r) { $this->db->insert('t', ['d' => $r[0], 'who' => $r[1], 'n' => $r[2]]); }
    }

    private function run(array $filters, array $params): array
    {
        [$sql, $b] = NuReportFilters::apply('SELECT d, who, n FROM t ORDER BY d', $filters, $params);
        return array_column($this->db->fetchAll($sql, $b), 'n');
    }

    public function testTwoFiltersOnTheSameColumnGiveADateRange(): void
    {
        $f = [['field' => 'd', 'param' => 'from', 'operator' => '>=', 'type' => 'date'], ['field' => 'd', 'param' => 'to', 'operator' => '<=', 'type' => 'date']];
        $this->assertSame([2], array_map('intval', $this->run($f, ['from' => '2026-11-10', 'to' => '2026-11-30'])));
        $this->assertSame([2, 3], array_map('intval', $this->run($f, ['from' => '2026-11-10'])));
    }

    public function testParamDefaultsToTheFieldNameSoOldReportsKeepWorking(): void
    {
        $this->assertSame([1, 3], array_map('intval', $this->run([['field' => 'who', 'operator' => '=']], ['who' => 'Ann'])));
    }

    public function testLikeWrapsTheValueAndEmptyValuesAreSkipped(): void
    {
        $this->assertSame([1, 2], array_map('intval', $this->run([['field' => 'd', 'operator' => 'LIKE'], ['field' => 'who', 'operator' => '=']], ['d' => '2026-11', 'who' => ''])));
    }

    public function testUnsafeFieldsAndOperatorsAreNeverInterpolated(): void
    {
        [$sql, $b] = NuReportFilters::apply('SELECT * FROM t', [['field' => 'd; DROP TABLE t', 'operator' => '='], ['field' => 'n', 'operator' => '= 1 OR 1=1 --']], ['d; DROP TABLE t' => 'x', 'n' => '2']);
        $this->assertFalse(str_contains($sql, 'DROP'));
        $this->assertFalse(str_contains($sql, 'OR 1=1'));
        $this->assertSame(['2'], $b);
        $this->assertTrue(str_contains($sql, '`n` = ?'));
    }

    public function testNoActiveFilterLeavesTheSqlUntouchedAndStripsTheSemicolon(): void
    {
        [$sql, $b] = NuReportFilters::apply('SELECT 1;', [['field' => 'd']], []);
        $this->assertSame('SELECT 1;', $sql);
        $this->assertSame([], $b);
    }

    public function testReportApiUsesTheSharedFilterHelperEverywhere(): void
    {
        $api = (string)file_get_contents(dirname(__DIR__, 3) . '/api/report.php');
        $this->assertSame(3, substr_count($api, 'NuReportFilters::apply('), 'run, export_pdf and email_pdf share one implementation');
        $this->assertFalse(str_contains($api, '`{$field}` {$op} ?'), 'no more hand-built filter SQL in the API');
        $ui = (string)file_get_contents(dirname(__DIR__, 3) . '/modules/reports/reports.php');
        $this->assertTrue(str_contains($ui, 'data-f-param') && str_contains($ui, 'data-f-type'), 'the report builder edits filter param and type');
        $this->assertTrue(str_contains($ui, "f.type === 'date'"), 'the filter bar renders date inputs');
    }
}
