<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApErpxClient', 'ApInvoices'] as $c) {
    require_once dirname(__DIR__, 3) . "/core/allpest/$c.php";
}

final class ApInvoicesTest extends TestCase
{
    private ApTestDb $db;
    private string $reqId;
    /** @var array<int,array{method:string,url:string,body:?string}> */
    private array $calls = [];
    private int $now;
    private int $invoiceCount = 0;
    /** @var array<string,int> external_ref => invoice id */
    private array $byRef = [];
    private bool $timeoutAfterCreate = false;
    private bool $failNext = false;
    private string $price = '120.00';

    protected function setUp(): void
    {
        $this->db = new ApTestDb();
        ApSchema::ensure($this->db);
        ApDb::set($this->db);
        $this->now = strtotime('2099-01-05 00:00:00 UTC');
        ApInvoices::setClock(fn () => new DateTimeImmutable('@' . $this->now));
        foreach (['erpx.base_url' => 'https://erp.test', 'erpx.api_key' => 'k', 'erpx.product.general' => 'FUM-GEN', 'erpx.product.perishable' => 'FUM-PER', 'erpx.tax_rate' => '15', 'invoice.mode' => 'reviewed'] as $k => $v) {
            ApSettings::set($k, $v);
        }
        $this->reqId = ApId::uuid();
        $this->db->insert('ap_requests', ['id' => $this->reqId, 'reference' => 'AP-209901-0001', 'status' => 'in_progress', 'customer_name' => 'Pacific Timber', 'customer_email' => 'B@Example.com', 'created_at' => '2099-01-01 00:00:00', 'updated_at' => '2099-01-01 00:00:00']);
        $this->calls = [];
    }

    private function http(): callable
    {
        return function (string $method, string $url, array $headers, ?string $body): array {
            $this->calls[] = ['method' => $method, 'url' => $url, 'body' => $body];
            if ($this->failNext) { $this->failNext = false; throw new RuntimeException('timeout'); }
            $b = $body !== null ? json_decode($body, true) : [];
            if (str_contains($url, 'parties.php')) { return ['status' => 201, 'body' => json_encode(['status' => 'success', 'id' => 7, 'party_code' => 'CUST-1', 'created' => true])]; }
            if (str_contains($url, 'products.php')) { return ['status' => 200, 'body' => json_encode(['status' => 'success', 'data' => [['product_code' => 'x', 'selling_price' => $this->price]]])]; }
            if ($method === 'POST' && str_contains($url, 'invoices.php')) {
                $ref = $b['external_ref'];
                $dup = isset($this->byRef[$ref]);
                $this->byRef[$ref] ??= ++$this->invoiceCount + 100;
                if ($this->timeoutAfterCreate) { $this->timeoutAfterCreate = false; throw new RuntimeException('timeout after create'); }
                return ['status' => $dup ? 200 : 201, 'body' => json_encode(['status' => 'success', 'invoice_id' => $this->byRef[$ref], 'invoice_no' => 'INV-' . $this->byRef[$ref]] + ($dup ? ['duplicate' => true] : []))];
            }
            if ($method === 'PATCH') { return ['status' => 200, 'body' => json_encode(['status' => 'success'])]; }
            return ['status' => 404, 'body' => '{}'];
        };
    }

    private function raise(): void
    {
        ApInvoices::setClient(new ApErpxClient('https://erp.test', 'k', $this->http()));
        ApInvoices::raiseForRequest($this->reqId);
    }

    /** @return string[] container ids */
    private function treatment(string $rof, array $results, string $status = 'closed'): array
    {
        $tid = ApId::uuid();
        $this->db->insert('ap_treatments', ['id' => $tid, 'request_id' => $this->reqId, 'rof_type' => $rof, 'status' => $status, 'created_at' => '2099-01-01 00:00:00', 'updated_at' => '2099-01-01 00:00:00']);
        $ids = [];
        foreach ($results as $i => $res) {
            $cid = ApId::uuid();
            $con = ApId::uuid();
            $this->db->insert('ap_consignments', ['id' => $con, 'request_id' => $this->reqId, 'container_no' => "MSKU000000$i", 'created_at' => '2099-01-01 00:00:00']);
            $this->db->insert('ap_treatment_containers', ['id' => $cid, 'treatment_id' => $tid, 'consignment_id' => $con, 'result' => $res]);
            $ids[] = $cid;
        }
        return $ids;
    }

    private function body(): array
    {
        foreach ($this->calls as $c) {
            if ($c['method'] === 'POST' && str_contains($c['url'], 'invoices.php')) { return json_decode((string)$c['body'], true); }
        }
        $this->fail('no invoice POST');
    }

    private function link(): array
    {
        return $this->db->fetchOne('SELECT * FROM ap_invoice_links WHERE request_id = ?', [$this->reqId]);
    }

    public function testFiveContainersAtOneTwentyWithFifteenPercentTax(): void
    {
        $this->treatment('general', ['pass', 'pass', 'pass', 'pass', 'pass']);
        $this->raise();
        $b = $this->body();
        $this->assertSame(600.0, (float)$b['subtotal']);
        $this->assertSame(90.0, (float)$b['tax_amount']);
        $this->assertSame(690.0, (float)$b['total']);
        $this->assertCount(5, $b['items']);
        $this->assertSame('AP-209901-0001', $b['external_ref']);
        $this->assertSame('draft', $b['status']);
        $this->assertSame(7, $b['party_id']);
    }

    public function testFailedContainerIsStillBilled(): void
    {
        $this->treatment('general', ['pass', 'fail']);
        $this->raise();
        $this->assertCount(2, $this->body()['items']);
    }

    public function testRofTypeSelectsProductAndPriceIsReadAtInvoiceTime(): void
    {
        $this->treatment('perishable', ['pass']);
        $this->price = '99.99';
        $this->raise();
        $urls = array_column($this->calls, 'url');
        $this->assertNotEmpty(array_filter($urls, fn ($u) => str_contains($u, 'product_code=FUM-PER')));
        $this->assertEqualsWithDelta(99.99, (float)$this->body()['items'][0]['unit_price'], 0.0001);
        $this->assertCount(0, $this->db->fetchAll("SELECT * FROM ap_settings WHERE setting_key LIKE 'price%'"));
    }

    public function testAutoModeCreatesUnpaid(): void
    {
        ApSettings::set('invoice.mode', 'auto');
        $this->treatment('general', ['pass']);
        $this->raise();
        $this->assertSame('unpaid', $this->body()['status']);
        $this->assertSame('released', $this->link()['state']);
    }

    public function testNothingIsRaisedWhileATreatmentIsOpen(): void
    {
        $this->treatment('general', ['pass']);
        $this->treatment('general', [null], 'exposure');
        $this->raise();
        $this->assertSame([], $this->calls);
        $this->assertNull($this->db->fetchOne('SELECT id FROM ap_invoice_links WHERE request_id = ?', [$this->reqId]));
    }

    public function testReleaseCallsErpxOnceEvenWhenRepeated(): void
    {
        $this->treatment('general', ['pass']);
        $this->raise();
        $this->assertSame('draft', $this->link()['state']);
        ApInvoices::release($this->reqId, 'admin1');
        ApInvoices::release($this->reqId, 'admin2');
        $patches = array_filter($this->calls, fn ($c) => $c['method'] === 'PATCH');
        $this->assertCount(1, $patches);
        $this->assertSame('released', $this->link()['state']);
    }

    public function testTransportTimeoutLeavesPendingAndDoesNotThrow(): void
    {
        $this->treatment('general', ['pass']);
        $this->failNext = true;
        $this->raise();
        $l = $this->link();
        $this->assertSame('pending', $l['state']);
        $this->assertSame(1, (int)$l['attempts']);
        $this->assertNotNull($l['next_attempt_at']);
        $this->assertStringContainsString('timeout', (string)$l['last_error']);
    }

    public function testRetryAfterTimeoutThatActuallySucceededEndsWithOneInvoice(): void
    {
        $this->treatment('general', ['pass']);
        $this->timeoutAfterCreate = true;
        $this->raise();
        $this->assertSame('pending', $this->link()['state']);
        $this->assertSame(0, ApInvoices::retryPending(), 'backoff not elapsed');
        $this->now += 3600;
        $this->assertSame(1, ApInvoices::retryPending());
        $this->assertSame(1, $this->invoiceCount);
        $l = $this->link();
        $this->assertSame('draft', $l['state']);
        $this->assertSame(101, (int)$l['erpx_invoice_id']);
        $this->assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_invoice_links')['n']);
    }

    public function testBackoffGrowsWithEachFailure(): void
    {
        $this->treatment('general', ['pass']);
        $this->failNext = true;
        $this->raise();
        $first = strtotime((string)$this->link()['next_attempt_at'] . ' UTC') - $this->now;
        $this->now += 100000;
        $this->failNext = true;
        ApInvoices::retryPending();
        $second = strtotime((string)$this->link()['next_attempt_at'] . ' UTC') - $this->now;
        $this->assertGreaterThan($first, $second);
    }

    public function testMoneyIsExactInCents(): void
    {
        $this->price = '0.10';
        ApSettings::set('erpx.tax_rate', '0');
        $this->treatment('general', array_fill(0, 3, 'pass'));
        $this->raise();
        $this->assertSame('0.30', number_format((float)$this->body()['total'], 2, '.', ''));
    }

    public function testMissingProductMappingIsRecordedNotThrown(): void
    {
        ApSettings::set('erpx.product.general', '');
        $this->treatment('general', ['pass']);
        $this->raise();
        $this->assertSame('pending', $this->link()['state']);
        $this->assertStringContainsString('product', strtolower((string)$this->link()['last_error']));
    }
}
