<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Static checks on the screens: they exist, sit behind the access check, and only call endpoints and scripts that exist. */
final class ApScreensTest extends TestCase
{
    private string $root;

    protected function setUp(): void { $this->root = dirname(__DIR__, 3); }

    /** @return string[] */
    private function pages(): array
    {
        return array_values(array_filter(glob($this->root . '/modules/allpest/*.php') ?: [], fn ($f) => basename($f) !== '_layout.php'));
    }

    public function testAllScreensExistAndStartWithTheAccessCheckedFrame(): void
    {
        $names = array_map('basename', $this->pages());
        foreach (['index.php', 'requests.php', 'treatment.php', 'signing.php', 'technicians.php', 'equipment.php', 'schedules.php', 'stock.php', 'settings.php'] as $n) {
            $this->assertContains($n, $names);
        }
        foreach ($this->pages() as $p) {
            $src = (string)file_get_contents($p);
            $this->assertStringContainsString('core/module_bootstrap.php', $src, basename($p));
            if (basename($p) !== 'allpest.php') { $this->assertStringContainsString('ap_page_start(', $src, basename($p)); }   // allpest.php is the sidebar launcher
        }
        $layout = (string)file_get_contents($this->root . '/modules/allpest/_layout.php');
        $this->assertStringContainsString("ApAccess::can(", $layout);
        $this->assertStringContainsString('http_response_code(403)', $layout);
        $this->assertStringContainsString('nu-csrf.js', $layout, 'state-changing calls must carry the CSRF token');
    }

    public function testEveryEndpointAndScriptAScreenUsesExists(): void
    {
        $files = array_merge($this->pages(), glob($this->root . '/assets/js/allpest/*.js') ?: []);
        foreach ($files as $f) {
            $src = (string)file_get_contents($f);
            preg_match_all("/(?:ApUi\.(?:get|post)|U\.(?:get|post))\(\s*'([a-z_]+\.php)'/", $src, $m);
            foreach ($m[1] as $api) { $this->assertTrue(is_file($this->root . '/api/allpest/' . $api), basename($f) . ' -> ' . $api); }
            preg_match_all('/allpest\/([a-z_]+\.js)/', $src, $js);
            foreach ($js[1] as $j) { $this->assertTrue(is_file($this->root . '/assets/js/allpest/' . $j), basename($f) . ' -> ' . $j); }
        }
    }

    public function testPhotosAreShrunkTo1600PixelsAtQuality075BeforeUpload(): void
    {
        $c = (string)file_get_contents($this->root . '/assets/js/allpest/compress.js');
        $this->assertStringContainsString('MAX = 1600', $c);
        $this->assertStringContainsString('QUALITY = 0.75', $c);
        $this->assertStringContainsString('1600', (string)file_get_contents($this->root . '/core/allpest/ApUploads.php'));
    }

    public function testReadingSetsAreQueuedWithAClientUuidAndSentInOrder(): void
    {
        $q = (string)file_get_contents($this->root . '/assets/js/allpest/queue.js');
        $this->assertStringContainsString('client_uuid', $q);
        $this->assertStringContainsString('localStorage', $q);
        $this->assertStringContainsString("'online'", $q);
        $r = (string)file_get_contents($this->root . '/assets/js/allpest/rof.js');
        $this->assertStringContainsString('ApQueue.uuid()', $r);
        $this->assertStringContainsString('below the standard', $r);
    }

    public function testASignatureThatWasNotDrawnIsRefusedInTheBrowser(): void
    {
        $s = (string)file_get_contents($this->root . '/assets/js/allpest/signpad.js');
        $this->assertStringContainsString('return ink < 40 ? null', $s);
    }
}
