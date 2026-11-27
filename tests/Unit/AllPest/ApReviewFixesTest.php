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
}
