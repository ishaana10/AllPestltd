<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSchedules', 'ApStock'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApStockTest extends TestCase
{
    private ApTestDb $db;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
    }

    private function valid(array $over = []): array
    {
        return array_merge([
            'name' => 'General 48 g/m3 24h', 'treatment_type' => 'general', 'dose_rate' => 48, 'exposure_hours' => 24,
            'min_temp_c' => 21, 'compensation_allowed' => 1, 'topups_allowed' => 1, 'retention_class' => 'standard',
        ], $over);
    }

    public function testScheduleSaveStoresAndListsValidRow(): void
    {
        $id = ApSchedules::save($this->valid());
        $all = ApSchedules::all();
        $this->assertCount(1, $all);
        $this->assertSame($id, $all[0]['id']);
        $this->assertEquals(48.0, (float)$all[0]['dose_rate']);
    }

    public function testScheduleRejectsZeroDoseRate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApSchedules::save($this->valid(['dose_rate' => 0]));
    }

    public function testScheduleRejectsNegativeExposure(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApSchedules::save($this->valid(['exposure_hours' => -1]));
    }

    public function testScheduleRejectsBadTypeNameAndRetentionClass(): void
    {
        foreach ([['treatment_type' => 'pest'], ['name' => ' '], ['retention_class' => 'ninety'], ['min_temp_c' => 'cold']] as $bad) {
            try {
                ApSchedules::save($this->valid($bad));
                $this->fail('expected rejection for ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testScheduleUpdateKeepsId(): void
    {
        $id = ApSchedules::save($this->valid());
        $this->assertSame($id, ApSchedules::save($this->valid(['id' => $id, 'dose_rate' => 56])));
        $this->assertCount(1, ApSchedules::all());
        $this->assertEquals(56.0, (float)ApSchedules::all()[0]['dose_rate']);
    }

    public function testBalanceIsSumOfMovements(): void
    {
        $lot = ApStock::createLot('MB-2026-01', 'Cylinder batch', 1.0);
        ApStock::receive($lot, 10000, 'u1');
        ApStock::consume($lot, 'tr-1', 3600, 'u1');
        ApStock::consume($lot, 'tr-2', 296, 'u1');
        $this->assertEquals(6104.0, ApStock::balance($lot));
    }

    public function testConsumeBeyondBalanceIsRecordedAndFlagged(): void
    {
        $lot = ApStock::createLot('L2', '', 1.0);
        ApStock::receive($lot, 1000, 'u1');
        ApStock::consume($lot, 'tr-1', 1500, 'u1');
        $this->assertEquals(-500.0, ApStock::balance($lot));
        $m = $this->db->fetchAll("SELECT * FROM ap_stock_movements WHERE kind = 'consume'");
        $this->assertCount(1, $m);
        $this->assertSame(1, (int)$m[0]['discrepancy']);
    }

    public function testConsumeWithinBalanceIsNotFlagged(): void
    {
        $lot = ApStock::createLot('L3', '', 1.0);
        ApStock::receive($lot, 1000, 'u1');
        ApStock::consume($lot, 'tr-1', 1000, 'u1');
        $this->assertSame(0, (int)$this->db->fetchOne("SELECT discrepancy FROM ap_stock_movements WHERE kind = 'consume'")['discrepancy']);
    }

    public function testAdjustRequiresReason(): void
    {
        $lot = ApStock::createLot('L4', '', 1.0);
        $this->expectException(InvalidArgumentException::class);
        ApStock::adjust($lot, -100, '  ', 'u1');
    }

    public function testAdjustIsSignedAndKeepsReason(): void
    {
        $lot = ApStock::createLot('L5', '', 1.0);
        ApStock::receive($lot, 1000, 'u1');
        ApStock::adjust($lot, -50, 'leak at valve', 'u1');
        $this->assertEquals(950.0, ApStock::balance($lot));
        $this->assertSame('leak at valve', $this->db->fetchOne("SELECT reason FROM ap_stock_movements WHERE kind = 'adjust'")['reason']);
    }

    public function testReceiveAndConsumeRejectNonPositiveGrams(): void
    {
        $lot = ApStock::createLot('L6', '', 1.0);
        foreach ([0, -5] as $g) {
            try { ApStock::receive($lot, $g, 'u'); $this->fail('receive'); } catch (InvalidArgumentException $e) { $this->assertTrue(true); }
            try { ApStock::consume($lot, 't', $g, 'u'); $this->fail('consume'); } catch (InvalidArgumentException $e) { $this->assertTrue(true); }
        }
    }

    public function testLowStockListsLotsBelowThreshold(): void
    {
        $low = ApStock::createLot('LOW', '', 1.0);
        $high = ApStock::createLot('HIGH', '', 1.0);
        ApStock::receive($low, 6104, 'u');
        ApStock::receive($high, 20000, 'u');
        $rows = ApStock::lowStock(7000);
        $this->assertCount(1, $rows);
        $this->assertSame($low, $rows[0]['lot_id']);
        $this->assertEquals(6104.0, (float)$rows[0]['balance']);
    }

    public function testUsageReportSumsConsumptionWithinRangeOnly(): void
    {
        $lot = ApStock::createLot('R1', '', 1.0);
        ApStock::receive($lot, 20000, 'u');
        foreach ([['2026-10-31 23:59:59', 100], ['2026-11-01 00:00:00', 200], ['2026-11-30 23:59:59', 300], ['2026-12-01 00:00:00', 400]] as [$at, $g]) {
            $this->db->insert('ap_stock_movements', ['id' => ApId::uuid(), 'lot_id' => $lot, 'kind' => 'consume', 'grams' => -$g, 'created_at' => $at]);
        }
        $r = ApStock::usageReport(new DateTimeImmutable('2026-11-01 00:00:00', new DateTimeZone('UTC')), new DateTimeImmutable('2026-11-30 23:59:59', new DateTimeZone('UTC')));
        $this->assertEquals(500.0, $r['total_g']);
        $this->assertCount(1, $r['lots']);
        $this->assertEquals(500.0, (float)$r['lots'][0]['grams']);
    }
}
