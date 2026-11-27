<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/core/SqlSync.php';

/** Scripted stand-in for the MySQL connection: knows its tables and columns, records what it was asked to run. */
final class SqlSyncFakeDb
{
    public array $ran = [];
    /** @param array<string,string[]> $tables */
    public function __construct(private array $tables) {}
    public function fetchAll(string $sql): array
    {
        if ($sql === 'SHOW TABLES') { return array_map(fn ($t) => ['Tables_in_x' => $t], array_keys($this->tables)); }
        if (preg_match('/SHOW COLUMNS FROM `(\w+)`/', $sql, $m)) { return array_map(fn ($c) => ['Field' => $c], $this->tables[$m[1]] ?? []); }
        return [];
    }
    public function exec(string $sql): void { $this->ran[] = $sql; }
}

final class SqlSyncTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/sqlsync_' . getmypid() . '.sql';
        file_put_contents($this->file, <<<'SQL'
-- header comment
CREATE TABLE IF NOT EXISTS `nu_old` (
  `old_id` INT AUTO_INCREMENT PRIMARY KEY,
  `old_name` VARCHAR(50) NOT NULL,
  `old_added` TEXT NULL,
  KEY `idx_name` (`old_name`)
) ENGINE=InnoDB;
-- new table
CREATE TABLE IF NOT EXISTS `ap_new` (
  `new_id` INT AUTO_INCREMENT PRIMARY KEY,
  `file_uploaded_by` INT UNSIGNED NULL
) ENGINE=InnoDB;
INSERT INTO `ap_new` (`new_id`) VALUES (1);
INSERT INTO `nu_old` (`old_name`) VALUES ('a;b');
SQL);
    }

    protected function tearDown(): void { @unlink($this->file); }

    public function testSplitKeepsSemicolonsInsideStrings(): void
    {
        $s = SqlSync::statements((string)file_get_contents($this->file));
        $this->assertCount(4, $s);
        $this->assertStringContainsString("'a;b'", $s[3]);
    }

    public function testCheckFindsMissingTablesAndColumnsOnly(): void
    {
        $db = new SqlSyncFakeDb(['nu_old' => ['old_id', 'old_name']]);
        $c = SqlSync::check($db, $this->file);
        $this->assertSame(['ap_new'], $c['tables']);
        $this->assertSame(['nu_old' => ['old_added']], $c['columns']);
        $this->assertSame([], $db->ran);
    }

    public function testRunCreatesAddsAndSeedsOnlyWhatIsMissing(): void
    {
        $db = new SqlSyncFakeDb(['nu_old' => ['old_id', 'old_name']]);
        $r = SqlSync::run($db, $this->file, false);
        $this->assertSame(['ap_new'], $r['created']);
        $this->assertSame(['nu_old' => ['old_added']], $r['columns']);
        $this->assertSame(1, $r['seeded']);   // the seed for ap_new only; nu_old already existed, so its seed is not repeated
        $this->assertSame([], $r['errors']);
        $this->assertCount(3, $db->ran);
        $this->assertStringContainsString('ALTER TABLE `nu_old` ADD COLUMN `old_added` TEXT NULL', implode("\n", $db->ran));
    }

    public function testNothingMissingMeansNothingRuns(): void
    {
        $db = new SqlSyncFakeDb(['nu_old' => ['old_id', 'old_name', 'old_added'], 'ap_new' => ['new_id', 'file_uploaded_by']]);
        $r = SqlSync::run($db, $this->file, false);
        $this->assertSame([], $db->ran);
        $this->assertSame([], $r['created']);
    }

    public function testUuidInstallsGetStringUserColumns(): void
    {
        $db = new SqlSyncFakeDb([]);
        SqlSync::run($db, $this->file, true);
        $created = implode("\n", $db->ran);
        $this->assertStringContainsString('`file_uploaded_by` VARCHAR(36)', $created);
        $this->assertTrue(strpos($created, 'UNSIGNED') === false);
    }

    public function testDeveloperTabAndApiAreWired(): void
    {
        $root = dirname(__DIR__, 3);
        $api = (string)file_get_contents($root . '/api/developer_settings.php');
        $this->assertStringContainsString("case 'db_check':", $api);
        $this->assertStringContainsString("case 'db_run':", $api);
        $this->assertStringContainsString('ApForms::provision(', $api);
        $this->assertStringContainsString('POST required', $api);
        $ui = (string)file_get_contents($root . '/modules/developer_settings/developer_settings.php');
        foreach (['DevSettings.dbCheck()', 'DevSettings.dbRun()', 'dbCheck: dbCheck', 'dbRun: dbRun'] as $needle) { $this->assertStringContainsString($needle, $ui); }
    }
}
