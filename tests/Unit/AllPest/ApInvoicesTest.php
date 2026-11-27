<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/ApTestDb.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApErpxClient', 'ApInvoices', 'ApForms'] as $c) {
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

    private function inv(): array
    {
        return $this->db->fetchOne('SELECT * FROM ap_invoices WHERE request_id = ?', [$this->reqId]);
    }

    /** @return array<int,array<string,mixed>> */
    private function lines(): array
    {
        return $this->db->fetchAll('SELECT * FROM ap_invoice_lines WHERE invoice_id = ? ORDER BY sort_no', [$this->inv()['id']]);
    }

    private function erpxPosts(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === 'POST' && str_contains($c['url'], 'invoices.php')));
    }

    private function verified(): string
    {
        $this->treatment('general', ['pass', 'fail']);
        $this->raise();
        $id = (string)$this->inv()['id'];
        ApInvoices::verify($id, 'fin1');
        return $id;
    }

    public function testClosingTheLastRofRaisesALocalDraftAndDoesNotCallErpxForTheInvoice(): void
    {
        $this->treatment('general', ['pass', 'pass', 'pass', 'pass', 'pass']);
        $this->raise();
        $i = $this->inv();
        $this->assertSame('draft', $i['status']);
        $this->assertMatchesRegularExpression('/^AP-INV-\d{4}-\d{5}$/', $i['invoice_no']);
        $this->assertSame('Pacific Timber', $i['customer_name']);
        $this->assertCount(5, $this->lines());
        $this->assertSame(600.0, (float)$i['subtotal']);
        $this->assertSame(90.0, (float)$i['tax_amount']);
        $this->assertSame(690.0, (float)$i['total']);
        $this->assertSame([], $this->erpxPosts(), 'nothing is posted to ERPX until finance clicks Post');
    }

    public function testFailedContainerIsStillBilledAndRaisingTwiceMakesOneInvoice(): void
    {
        $this->treatment('general', ['pass', 'fail']);
        $this->raise();
        $this->raise();
        $this->assertCount(2, $this->lines());
        $this->assertSame(1, (int)$this->db->fetchOne('SELECT COUNT(*) AS n FROM ap_invoices')['n']);
    }

    public function testPriceComesFromErpxAndIsCachedForWhenErpxIsDown(): void
    {
        $this->treatment('perishable', ['pass']);
        $this->price = '99.99';
        $this->raise();
        $this->assertEqualsWithDelta(99.99, (float)$this->lines()[0]['unit_price'], 0.0001);
        // ERPX down at the next request: the cached price is used and the invoice is still created
        $req2 = ApId::uuid();
        $this->db->insert('ap_requests', ['id' => $req2, 'reference' => 'AP-209901-0002', 'status' => 'in_progress', 'customer_name' => 'B', 'customer_email' => 'b@x.com', 'created_at' => '2099-01-01 00:00:00', 'updated_at' => '2099-01-01 00:00:00']);
        $tid = ApId::uuid(); $con = ApId::uuid();
        $this->db->insert('ap_treatments', ['id' => $tid, 'request_id' => $req2, 'rof_type' => 'perishable', 'status' => 'closed', 'created_at' => '2099-01-01 00:00:00', 'updated_at' => '2099-01-01 00:00:00']);
        $this->db->insert('ap_consignments', ['id' => $con, 'request_id' => $req2, 'container_no' => 'MSKU1', 'created_at' => '2099-01-01 00:00:00']);
        $this->db->insert('ap_treatment_containers', ['id' => ApId::uuid(), 'treatment_id' => $tid, 'consignment_id' => $con, 'result' => 'pass']);
        $this->failNext = true;
        ApInvoices::setClient(new ApErpxClient('https://erp.test', 'k', $this->http()));
        ApInvoices::raiseForRequest($req2);
        $i2 = $this->db->fetchOne('SELECT * FROM ap_invoices WHERE request_id = ?', [$req2]);
        $this->assertNotNull($i2);
        $this->assertEqualsWithDelta(99.99, (float)$this->db->fetchOne('SELECT unit_price FROM ap_invoice_lines WHERE invoice_id = ?', [$i2['id']])['unit_price'], 0.0001);
    }

    public function testNothingIsRaisedWhileATreatmentIsOpenOrAConsignmentIsUntreated(): void
    {
        $this->treatment('general', ['pass']);
        $this->treatment('general', [null], 'exposure');
        $this->raise();
        $this->assertNull($this->db->fetchOne('SELECT id FROM ap_invoices WHERE request_id = ?', [$this->reqId]));
    }

    public function testFinanceCanEditADraftAndTotalsFollow(): void
    {
        $this->treatment('general', ['pass', 'pass']);
        $this->raise();
        $id = (string)$this->inv()['id'];
        $l = $this->lines();
        ApInvoices::update($id, ['discount' => '20.00', 'notes' => 'Agreed rate', 'lines' => [['id' => $l[0]['id'], 'unit_price' => '150.00', 'description' => 'Container A - special']]]);
        $i = $this->inv();
        $this->assertSame(270.0, (float)$i['subtotal']);       // 150 + 120
        $this->assertSame(20.0, (float)$i['discount']);
        $this->assertSame('Agreed rate', $i['notes']);
        $this->assertSame('Container A - special', $this->lines()[0]['description']);
        $this->assertGreaterThan(0.0, (float)$i['tax_amount']);
        $this->assertSame(round((float)$i['subtotal'] + (float)$i['tax_amount'] - 20, 2), round((float)$i['total'], 2));
    }

    public function testVerifyNeedsEveryLinePricedAndLocksTheInvoice(): void
    {
        $this->treatment('general', ['pass']);
        $this->raise();
        $id = (string)$this->inv()['id'];
        $l = $this->lines();
        ApInvoices::update($id, ['lines' => [['id' => $l[0]['id'], 'unit_price' => '0']]]);
        try { ApInvoices::verify($id, 'fin1'); $this->fail('a zero-priced line must block verification'); } catch (InvalidArgumentException $e) { $this->assertStringContainsString('price', $e->getMessage()); }
        ApInvoices::update($id, ['lines' => [['id' => $l[0]['id'], 'unit_price' => '100']]]);
        ApInvoices::verify($id, 'fin1');
        $this->assertSame('verified', $this->inv()['status']);
        $this->assertSame('fin1', $this->inv()['verified_by']);
        $this->expectException(InvalidArgumentException::class);
        ApInvoices::update($id, ['notes' => 'late change']);
    }

    public function testVerifiedInvoiceCanBeReopenedUntilPosted(): void
    {
        $id = $this->verified();
        ApInvoices::reopen($id, 'fin1');
        $this->assertSame('draft', $this->inv()['status']);
    }

    public function testPostSendsToErpxOnceAndLocksTheInvoice(): void
    {
        $id = $this->verified();
        ApInvoices::setClient(new ApErpxClient('https://erp.test', 'k', $this->http()));
        ApInvoices::post($id, 'fin1');
        ApInvoices::post($id, 'fin2');
        $posts = $this->erpxPosts();
        $this->assertCount(1, $posts);
        $b = json_decode((string)$posts[0]['body'], true);
        $this->assertCount(2, $b['items']);
        $this->assertSame('AP-209901-0001', $b['external_ref']);
        $this->assertSame('unpaid', $b['status']);
        $this->assertSame(7, $b['party_id']);
        $this->assertEqualsWithDelta((float)$this->inv()['total'], (float)$b['total'], 0.001);
        $i = $this->inv();
        $this->assertSame('posted', $i['status']);
        $this->assertSame('INV-101', $i['erpx_invoice_no']);
        $this->assertSame('fin1', $i['posted_by']);
    }

    public function testPostingADraftIsRefused(): void
    {
        $this->treatment('general', ['pass']);
        $this->raise();
        $this->expectException(InvalidArgumentException::class);
        ApInvoices::post((string)$this->inv()['id'], 'fin1');
    }

    public function testFailedPostKeepsTheInvoiceAndTheWorkerRetriesIt(): void
    {
        $id = $this->verified();
        ApInvoices::setClient(new ApErpxClient('https://erp.test', 'k', $this->http()));
        $this->failNext = true;
        ApInvoices::post($id, 'fin1');                      // must not throw: ERPX trouble is retried later
        $i = $this->inv();
        $this->assertSame('posting', $i['status']);
        $this->assertSame(1, (int)$i['attempts']);
        $this->assertStringContainsString('timeout', (string)$i['last_error']);
        $this->assertSame(0, ApInvoices::retryPending(), 'backoff not elapsed');
        $this->now += 3600;
        $this->assertSame(1, ApInvoices::retryPending());
        $this->assertSame('posted', $this->inv()['status']);
        $this->assertSame(1, $this->invoiceCount);
    }

    public function testTimeoutAfterErpxCreatedItDoesNotMakeASecondErpxInvoice(): void
    {
        $id = $this->verified();
        ApInvoices::setClient(new ApErpxClient('https://erp.test', 'k', $this->http()));
        $this->timeoutAfterCreate = true;
        ApInvoices::post($id, 'fin1');
        $this->now += 3600;
        ApInvoices::retryPending();
        $this->assertSame(1, $this->invoiceCount);
        $this->assertSame('posted', $this->inv()['status']);
    }

    public function testInvoiceNumbersAreSequentialPerYear(): void
    {
        $this->treatment('general', ['pass']);
        $this->raise();
        $this->assertTrue(str_ends_with((string)$this->inv()['invoice_no'], '-00001'));
    }

    public function testInvoicesScreenApiAndMenuAreWired(): void
    {
        $root = dirname(__DIR__, 3);
        $mod = (string)file_get_contents($root . '/modules/allpest_invoices/allpest_invoices.php');
        $api = (string)file_get_contents($root . '/api/allpest/invoices.php');
        foreach (['Post to ERPX', 'Verify charges', 'Reopen for changes', 'api/allpest/invoices.php'] as $needle) { $this->assertTrue(str_contains($mod, $needle), $needle); }
        foreach (["'verify'", "'post'", "'update'", "'reopen'", "guard('admin.invoices')"] as $needle) { $this->assertTrue(str_contains($api, $needle), $needle); }
        $this->assertTrue(isset(ApForms::moduleEntries()['allpest_invoices']));
        $this->assertFalse(str_contains($mod, 'alert('));
    }
}
