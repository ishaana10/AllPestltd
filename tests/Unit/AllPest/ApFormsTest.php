<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApForms'] as $c) { require_once dirname(__DIR__, 3) . "/core/allpest/$c.php"; }

final class ApFormsTest extends TestCase
{
    private ApTestDb $db;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->db->exec('CREATE TABLE nu_forms (form_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER DEFAULT 1, form_code TEXT, form_type TEXT, form_name TEXT, form_table TEXT, form_description TEXT, form_layout TEXT, browse_layout TEXT, form_active INTEGER, form_pk_type TEXT, form_table_mode TEXT, browse_delete_enabled INTEGER DEFAULT 1)');
        $this->db->exec('CREATE TABLE nu_menus (menu_id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER DEFAULT 1, menu_label TEXT, menu_type TEXT, menu_target TEXT, menu_parent_id INTEGER DEFAULT 0, menu_order INTEGER DEFAULT 0, menu_roles TEXT, menu_role_access TEXT, menu_active INTEGER DEFAULT 1, menu_icon TEXT, menu_open_mode TEXT, menu_browse_mode TEXT, menu_preview_mode TEXT, menu_default_view TEXT)');
    }

    private function columns(string $table): array { return array_column($this->db->fetchAll("PRAGMA table_info($table)"), 'name'); }

    private function fieldNames(array $layout): array
    {
        $out = [];
        foreach ($layout as $node) { foreach (($node['children'] ?? [$node]) as $f) { if (isset($f['name'])) { $out[] = $f['name']; } } }
        return $out;
    }

    public function testEveryFormFieldAndBrowseColumnExistsOnItsTable(): void
    {
        foreach (ApForms::definitions() as $code => $d) {
            $cols = $this->columns($d['table']);
            $this->assertTrue($cols !== [], "$code: table {$d['table']} exists");
            foreach ($this->fieldNames($d['layout']) as $n) {
                if ($n === 'consignments') { continue; }   // the subform placeholder, not a column
                $this->assertContains($n, $cols, "$code field $n");
            }
            foreach ($d['browse'] as $c) { $this->assertContains($c['fieldname'], $cols, "$code browse {$c['fieldname']}"); }
        }
    }

    public function testSubformPointsAtARealForeignKey(): void
    {
        $defs = ApForms::definitions();
        $sf = $defs['ap_requests']['layout'][count($defs['ap_requests']['layout']) - 1];
        $this->assertSame('subform', $sf['type']);
        $this->assertArrayHasKey($sf['subform']['form_code'], $defs);
        $this->assertContains($sf['subform']['fk_field'], $this->columns($defs[$sf['subform']['form_code']]['table']));
    }

    public function testProvisionCreatesEveryFormOnceAndKeepsLaterEdits(): void
    {
        $made = ApForms::provision($this->db, 1);
        $this->assertSame(array_keys(ApForms::definitions()), $made);
        $this->db->exec("UPDATE nu_forms SET form_name = 'Edited in the builder' WHERE form_code = 'ap_equipment'");
        $this->assertSame([], ApForms::provision($this->db, 1));
        $this->assertSame('Edited in the builder', $this->db->fetchOne("SELECT form_name FROM nu_forms WHERE form_code = 'ap_equipment'")['form_name']);
        $f = $this->db->fetchOne("SELECT * FROM nu_forms WHERE form_code = 'ap_requests'");
        $this->assertSame('uuid', $f['form_pk_type']);
        $this->assertSame('existing', $f['form_table_mode']);
        $this->assertTrue(is_array(json_decode($f["form_layout"], true)));
    }

    public function testEngineOwnedFormsAreReadOnlyAndCannotBeDeleted(): void
    {
        ApForms::provision($this->db, 1);
        foreach (['ap_treatments', 'ap_certificates', 'ap_invoice_links', 'ap_stock_movements'] as $code) {
            $this->assertSame(0, (int)$this->db->fetchOne('SELECT browse_delete_enabled FROM nu_forms WHERE form_code = ?', [$code])['browse_delete_enabled'], $code);
            foreach ($this->fieldNames(json_decode((string)$this->db->fetchOne('SELECT form_layout FROM nu_forms WHERE form_code = ?', [$code])['form_layout'], true)) as $n) { $this->assertTrue(true); }
            $layout = json_decode((string)$this->db->fetchOne('SELECT form_layout FROM nu_forms WHERE form_code = ?', [$code])['form_layout'], true);
            foreach ($layout as $node) { foreach ($node['children'] as $f) { $this->assertTrue(!empty($f['readonly']), "$code {$f['name']} readonly"); } }
        }
        $this->assertSame(1, (int)$this->db->fetchOne("SELECT browse_delete_enabled FROM nu_forms WHERE form_code = 'ap_technicians'")['browse_delete_enabled']);
    }

    public function testMenuIsOneGroupWithAnEntryPerMainForm(): void
    {
        ApForms::provision($this->db, 1);
        ApForms::provision($this->db, 1);
        $g = $this->db->fetchAll("SELECT * FROM nu_menus WHERE menu_type = 'group' AND menu_label = 'All Pest'");
        $this->assertCount(1, $g);
        $kids = $this->db->fetchAll('SELECT menu_target FROM nu_menus WHERE menu_parent_id = ?', [$g[0]['menu_id']]);
        $expected = array_keys(array_filter(ApForms::definitions(), fn ($d) => ($d['type'] ?? 'main') !== 'subform'));
        $this->assertSame([], array_diff($expected, array_column($kids, 'menu_target')));
        $this->assertCount(count($expected), $kids);
        $this->assertStringContainsString('ap_technician', (string)$g[0]['menu_role_access']);
    }

    public function testTheOldSingleModuleEntryBecomesTheGroup(): void
    {
        $this->db->insert('nu_menus', ['menu_label' => 'All Pest', 'menu_type' => 'module', 'menu_target' => 'allpest', 'menu_parent_id' => 0, 'menu_active' => 1, 'project_id' => 1]);
        ApForms::provision($this->db, 1);
        $this->assertSame(1, (int)$this->db->fetchOne("SELECT COUNT(*) AS n FROM nu_menus WHERE menu_label = 'All Pest'")['n']);
        $this->assertSame('group', $this->db->fetchOne("SELECT menu_type FROM nu_menus WHERE menu_label = 'All Pest'")['menu_type']);
        $this->assertSame(0, (int)$this->db->fetchOne("SELECT COUNT(*) AS n FROM nu_menus WHERE menu_target = 'allpest'")['n']);
    }
}
