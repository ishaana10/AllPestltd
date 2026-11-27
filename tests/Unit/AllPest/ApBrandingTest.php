<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApUploads', 'ApBranding'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApBrandingTest extends TestCase
{
    private ApTestDb $db;
    private string $dir;

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->dir = sys_get_temp_dir() . '/ap_brand_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
        ApUploads::configure($this->db, $this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) { if (is_file($f)) { @unlink($f); } }
        @rmdir($this->dir);
    }

    private function logoFile(): array
    {
        $im = imagecreatetruecolor(40, 20);
        $p = tempnam(sys_get_temp_dir(), 'lg');
        imagepng($im, $p);
        return ['name' => 'logo.png', 'type' => 'image/png', 'size' => filesize($p), 'tmp_name' => $p, 'error' => 0];
    }

    public function testLoadReadsCompanyDetailsFromSettings(): void
    {
        ApSettings::set('company_name', 'All Pest Hygiene Solutions');
        ApSettings::set('company_address', "12 Wharf Rd\nLautoka");
        ApSettings::set('company_phone', '+679 666 0000');
        ApSettings::set('company_email', 'info@allpest.com.fj');
        ApSettings::set('company_website', 'allpest.com.fj');
        ApSettings::set('treatment_provider_id', 'TP-007');
        $b = ApBranding::load();
        $this->assertSame('All Pest Hygiene Solutions', $b['name']);
        $this->assertSame('+679 666 0000', $b['phone']);
        $this->assertSame('TP-007', $b['provider_id']);
        $this->assertNull($b['logo_path']);
    }

    public function testLogoPathResolvesOnceALogoIsSet(): void
    {
        $r = ApUploads::store($this->logoFile(), 'other', 'u1', 'test');
        ApBranding::setLogo($r['file_id']);
        $b = ApBranding::load();
        $this->assertNotNull($b['logo_path']);
        $this->assertTrue(is_file($b['logo_path']));
        ApBranding::clearLogo();
        $this->assertNull(ApBranding::load()['logo_path']);
    }

    public function testHeaderHtmlShowsLogoAndEscapedDetails(): void
    {
        ApSettings::set('company_name', 'Pests & Co <b>');
        ApSettings::set('company_phone', '123');
        $html = ApBranding::headerHtml(['name' => 'Pests & Co <b>', 'address' => 'A', 'phone' => '123', 'email' => '', 'website' => '', 'provider_id' => 'TP-1', 'logo_path' => __FILE__]);
        $this->assertStringContainsString('Pests &amp; Co &lt;b&gt;', $html);
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('TP-1', $html);
        $this->assertStringNotContainsString('<b> ', $html);
    }

    public function testHeaderHtmlWithoutLogoHasNoImage(): void
    {
        $html = ApBranding::headerHtml(['name' => 'X', 'address' => '', 'phone' => '', 'email' => '', 'website' => '', 'provider_id' => '', 'logo_path' => null]);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testSetLogoRejectsAnUnknownFile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ApBranding::setLogo('00000000-0000-0000-0000-000000000000');
    }

    public function testSettingsEndpointAcceptsContactKeysAndLogoActions(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/api/allpest/settings.php');
        foreach (['company_phone', 'company_email', 'company_website', "'logo'", 'logo_clear', 'ApBranding::setLogo'] as $needle) {
            $this->assertStringContainsString($needle, $src);
        }
        $ui = (string)file_get_contents(dirname(__DIR__, 3) . '/modules/allpest_settings/allpest_settings.php');
        $this->assertStringContainsString('logo-up', $ui);
    }
}
