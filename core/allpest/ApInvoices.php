<?php
declare(strict_types=1);

require_once __DIR__ . '/ApDb.php';
require_once __DIR__ . '/ApSettings.php';
require_once __DIR__ . '/ApErpxClient.php';
require_once __DIR__ . '/ApId.php';
require_once __DIR__ . '/ApAudit.php';

/**
 * One local invoice per request (ap_invoices + ap_invoice_lines), laid out like a NuvisERPX invoice. Created as a draft when the last
 * ROF closes; finance edits and verifies it; a finance click posts it to ERPX. States: draft -> verified -> posting -> posted
 * (posting = sent by finance but ERPX has not accepted it yet; the worker retries with backoff). Posting never throws on ERPX trouble.
 * Money is handled as integer cents. ERPX prices are cached so an invoice can still be raised while ERPX is down.
 */
final class ApInvoices
{
    private static $clock = null;
    private static ?ApErpxClient $client = null;

    public static function setClock(?callable $clock): void { self::$clock = $clock; }
    public static function setClient(?ApErpxClient $c): void { self::$client = $c; }

    private static function now(): DateTimeImmutable
    {
        return self::$clock !== null ? (self::$clock)() : new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function client(): ApErpxClient
    {
        if (self::$client) { return self::$client; }
        $url = (string)ApSettings::get('erpx.base_url', '');
        $key = (string)ApSettings::get('erpx.api_key', '');
        if ($url === '' || $key === '') { throw new RuntimeException('ERPX base URL / API key is not set.'); }
        return new ApErpxClient($url, $key, ApErpxClient::curlHttp());
    }

    /** "120.00" -> 12000. */
    public static function toCents(string $dec): int
    {
        if (!preg_match('/^\s*(-?)(\d+)(?:\.(\d*))?\s*$/', $dec, $m)) { throw new RuntimeException("Bad amount '{$dec}'."); }
        $frac = str_pad(substr(($m[3] ?? '') . '000', 0, 3), 3, '0');
        $cents = (int)$m[2] * 100 + (int)substr($frac, 0, 2) + ((int)$frac[2] >= 5 ? 1 : 0);
        return $m[1] === '-' ? -$cents : $cents;
    }

    private static function money(int $cents): string
    {
        return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Tax in cents for a line at a percentage given with up to two decimals (basis points). */
    private static function taxCents(int $cents, string $rate): int
    {
        $bp = self::toCents($rate === '' ? '0' : $rate);
        return intdiv($cents * $bp + 5000, 10000);
    }

    private static function dec($v): string { return number_format((float)$v, 2, '.', ''); }

    private static function nextNumber(): string
    {
        $year = self::now()->format('Y');
        $last = ApDb::get()->fetchOne('SELECT invoice_no FROM ap_invoices WHERE invoice_no LIKE ? ORDER BY invoice_no DESC LIMIT 1', ["AP-INV-$year-%"]);
        return sprintf('AP-INV-%s-%05d', $year, $last ? (int)substr((string)$last['invoice_no'], -5) + 1 : 1);
    }

    /** ERPX price for a product code as cents; falls back to the last price seen, or 0 (finance must enter it) when ERPX is unreachable. */
    private static function priceFor(string $code): int
    {
        if ($code === '') { return 0; }
        try {
            $cents = self::toCents(self::client()->productPrice($code));
            ApSettings::set('price.cache.' . $code, self::money($cents));
            return $cents;
        } catch (Throwable $e) {
            $cached = (string)ApSettings::get('price.cache.' . $code, '');
            return $cached === '' ? 0 : self::toCents($cached);
        }
    }

    public static function raiseForRequest(string $requestId): void
    {
        $db = ApDb::get();
        if ($db->fetchOne('SELECT id FROM ap_invoices WHERE request_id = ?', [$requestId])) { return; }
        $treatments = $db->fetchAll('SELECT id, status, rof_type FROM ap_treatments WHERE request_id = ?', [$requestId]);
        if (!$treatments) { return; }
        foreach ($treatments as $t) { if ($t['status'] !== 'closed') { return; } }
        $uncovered = $db->fetchOne('SELECT COUNT(*) AS n FROM ap_consignments c WHERE c.request_id = ? AND NOT EXISTS (SELECT 1 FROM ap_treatment_containers tc WHERE tc.consignment_id = c.id)', [$requestId]);
        if ((int)$uncovered['n'] > 0) { return; }   // never invoice a partial request

        $req = $db->fetchOne('SELECT * FROM ap_requests WHERE id = ?', [$requestId]);
        $rate = (string)ApSettings::get('erpx.tax_rate', '0');
        $fiji = self::now()->setTimezone(new DateTimeZone('Pacific/Fiji'));
        $now = self::now()->format('Y-m-d H:i:s');
        $id = ApId::uuid();
        $lines = [];
        $prices = [];
        $sub = 0;
        $tax = 0;
        $n = 0;
        foreach ($treatments as $t) {
            $code = trim((string)ApSettings::get('erpx.product.' . $t['rof_type'], ''));
            $prices[$code] ??= self::priceFor($code);
            $rows = $db->fetchAll('SELECT tc.id, tc.result, c.container_no FROM ap_treatment_containers tc JOIN ap_consignments c ON c.id = tc.consignment_id WHERE tc.treatment_id = ? ORDER BY c.container_no, tc.id', [$t['id']]);
            foreach ($rows as $r) {
                $p = $prices[$code];
                $lt = self::taxCents($p, $rate);
                $sub += $p; $tax += $lt;
                $lines[] = ['id' => ApId::uuid(), 'invoice_id' => $id, 'sort_no' => ++$n, 'treatment_id' => $t['id'], 'container_id' => $r['id'],
                    'description' => 'Methyl bromide fumigation (' . $t['rof_type'] . ') - container ' . ($r['container_no'] ?: 'n/a') . ' - ' . ($r['result'] === 'pass' ? 'passed' : 'treated'),
                    'qty' => '1.00', 'unit_price' => self::money($p), 'tax_amount' => self::money($lt), 'amount' => self::money($p + $lt)];
            }
        }
        if (!$lines) { return; }
        try {
            $db->insert('ap_invoices', ['id' => $id, 'request_id' => $requestId, 'invoice_no' => self::nextNumber(), 'status' => 'draft',
                'invoice_date' => $fiji->format('Y-m-d'), 'due_date' => $fiji->modify('+30 days')->format('Y-m-d'),
                'customer_name' => $req['customer_name'], 'customer_email' => strtolower((string)$req['customer_email']), 'customer_tin' => $req['customer_tin'],
                'customer_phone' => $req['customer_phone'], 'customer_address' => $req['customer_address'], 'tax_rate' => $rate === '' ? '0' : $rate,
                'subtotal' => self::money($sub), 'tax_amount' => self::money($tax), 'discount' => '0.00', 'total' => self::money($sub + $tax),
                'notes' => 'Fumigation request ' . $req['reference'], 'created_at' => $now, 'updated_at' => $now]);
        } catch (Throwable $e) {
            return;   // another process raised it a moment ago
        }
        foreach ($lines as $l) { $db->insert('ap_invoice_lines', $l); }
        ApAudit::request('invoice.drafted', $requestId, ['invoice_id' => $id]);
    }

    private static function load(string $id, array $allowed, string $what): array
    {
        $inv = ApDb::get()->fetchOne('SELECT * FROM ap_invoices WHERE id = ?', [$id]);
        if (!$inv) { throw new InvalidArgumentException('Unknown invoice.'); }
        if (!in_array($inv['status'], $allowed, true)) { throw new InvalidArgumentException("An invoice that is {$inv['status']} cannot be $what."); }
        return $inv;
    }

    /** Recompute line amounts and invoice totals from unit prices, quantity, tax rate and discount. */
    private static function recalc(string $invoiceId): void
    {
        $db = ApDb::get();
        $inv = $db->fetchOne('SELECT tax_rate, discount FROM ap_invoices WHERE id = ?', [$invoiceId]);
        $rate = self::dec($inv['tax_rate']);
        $sub = 0; $tax = 0;
        foreach ($db->fetchAll('SELECT id, qty, unit_price FROM ap_invoice_lines WHERE invoice_id = ?', [$invoiceId]) as $l) {
            $net = (int)round((float)$l['qty'] * self::toCents(self::dec($l['unit_price'])));
            $lt = self::taxCents($net, $rate);
            $sub += $net; $tax += $lt;
            $db->update('ap_invoice_lines', ['tax_amount' => self::money($lt), 'amount' => self::money($net + $lt)], 'id = ?', [$l['id']]);
        }
        $disc = self::toCents(self::dec($inv['discount']));
        $db->update('ap_invoices', ['subtotal' => self::money($sub), 'tax_amount' => self::money($tax), 'total' => self::money($sub + $tax - $disc), 'updated_at' => self::now()->format('Y-m-d H:i:s')], 'id = ?', [$invoiceId]);
    }

    /** Finance edits a draft. $in: discount, notes, due_date, lines[] of {id, description?, qty?, unit_price?}. */
    public static function update(string $invoiceId, array $in): void
    {
        $db = ApDb::get();
        self::load($invoiceId, ['draft'], 'edited');
        $set = [];
        if (isset($in['notes'])) { $set['notes'] = (string)$in['notes']; }
        if (!empty($in['due_date'])) { $set['due_date'] = (string)$in['due_date']; }
        if (isset($in['discount'])) {
            $d = self::toCents((string)$in['discount']);
            if ($d < 0) { throw new InvalidArgumentException('Discount cannot be negative.'); }
            $set['discount'] = self::money($d);
        }
        if ($set) { $db->update('ap_invoices', $set, 'id = ?', [$invoiceId]); }
        foreach ((array)($in['lines'] ?? []) as $l) {
            $line = $db->fetchOne('SELECT id FROM ap_invoice_lines WHERE id = ? AND invoice_id = ?', [(string)($l['id'] ?? ''), $invoiceId]);
            if (!$line) { throw new InvalidArgumentException('Unknown invoice line.'); }
            $c = [];
            if (isset($l['description'])) { $c['description'] = mb_substr(trim((string)$l['description']), 0, 255); }
            if (isset($l['qty'])) { $q = (float)$l['qty']; if ($q <= 0) { throw new InvalidArgumentException('Quantity must be greater than 0.'); } $c['qty'] = number_format($q, 2, '.', ''); }
            if (isset($l['unit_price'])) { $u = self::toCents((string)$l['unit_price']); if ($u < 0) { throw new InvalidArgumentException('Price cannot be negative.'); } $c['unit_price'] = self::money($u); }
            if ($c) { $db->update('ap_invoice_lines', $c, 'id = ?', [$line['id']]); }
        }
        self::recalc($invoiceId);
    }

    /** Finance confirms the charges. Locks the invoice for editing; it can be reopened until it is posted. */
    public static function verify(string $invoiceId, string $userId): void
    {
        $db = ApDb::get();
        $inv = self::load($invoiceId, ['draft'], 'verified');
        $unpriced = $db->fetchOne('SELECT COUNT(*) AS n FROM ap_invoice_lines WHERE invoice_id = ? AND unit_price <= 0', [$invoiceId]);
        if ((int)$unpriced['n'] > 0) { throw new InvalidArgumentException('Every line needs a price above 0 before the invoice can be verified.'); }
        if (self::toCents(self::dec($inv['total'])) <= 0) { throw new InvalidArgumentException('The invoice total must be above 0.'); }
        $now = self::now()->format('Y-m-d H:i:s');
        $db->update('ap_invoices', ['status' => 'verified', 'verified_by' => $userId, 'verified_at' => $now, 'updated_at' => $now], "id = ? AND status = 'draft'", [$invoiceId]);
        ApAudit::request('invoice.verified', (string)$inv['request_id'], ['invoice_no' => $inv['invoice_no'], 'total' => $inv['total']]);
    }

    public static function reopen(string $invoiceId, string $userId): void
    {
        $inv = self::load($invoiceId, ['verified'], 'reopened');
        ApDb::get()->update('ap_invoices', ['status' => 'draft', 'verified_by' => null, 'verified_at' => null, 'updated_at' => self::now()->format('Y-m-d H:i:s')], "id = ? AND status = 'verified'", [$invoiceId]);
        ApAudit::request('invoice.reopened', (string)$inv['request_id'], ['invoice_no' => $inv['invoice_no'], 'by' => $userId]);
    }

    /** Finance clicks Post: queue the verified invoice for ERPX and try now. A repeated click does nothing. */
    public static function post(string $invoiceId, string $userId): void
    {
        $db = ApDb::get();
        $inv = $db->fetchOne('SELECT * FROM ap_invoices WHERE id = ?', [$invoiceId]);
        if (!$inv) { throw new InvalidArgumentException('Unknown invoice.'); }
        if (in_array($inv['status'], ['posting', 'sending', 'posted'], true)) { return; }
        if ($inv['status'] !== 'verified') { throw new InvalidArgumentException('Only a verified invoice can be posted to ERPX.'); }
        $now = self::now()->format('Y-m-d H:i:s');
        if ($db->update('ap_invoices', ['status' => 'posting', 'posted_by' => $userId, 'next_attempt_at' => null, 'updated_at' => $now], "id = ? AND status = 'verified'", [$invoiceId]) !== 1) { return; }
        ApAudit::request('invoice.post_requested', (string)$inv['request_id'], ['invoice_no' => $inv['invoice_no'], 'by' => $userId]);
        self::attempt($invoiceId);
    }

    /** One send to ERPX. Claims the invoice so two callers never send at once; failures are stored with a backoff, never thrown. */
    private static function attempt(string $invoiceId): void
    {
        $db = ApDb::get();
        $now = self::now()->format('Y-m-d H:i:s');
        $stale = self::now()->modify('-10 minutes')->format('Y-m-d H:i:s');
        if ($db->update('ap_invoices', ['status' => 'sending', 'updated_at' => $now], "id = ? AND (status = 'posting' OR (status = 'sending' AND updated_at <= ?))", [$invoiceId, $stale]) !== 1) { return; }
        $inv = $db->fetchOne('SELECT * FROM ap_invoices WHERE id = ?', [$invoiceId]);
        try {
            $erpx = self::send($inv);
            $db->update('ap_invoices', ['status' => 'posted', 'erpx_invoice_id' => $erpx['id'], 'erpx_invoice_no' => $erpx['invoice_no'], 'posted_at' => $now, 'last_error' => null, 'next_attempt_at' => null, 'updated_at' => $now], 'id = ?', [$invoiceId]);
            ApAudit::request('invoice.posted', (string)$inv['request_id'], ['invoice_no' => $inv['invoice_no'], 'erpx_invoice_no' => $erpx['invoice_no']]);
        } catch (Throwable $e) {
            $attempts = (int)$inv['attempts'] + 1;
            $delay = min(21600, 60 * (2 ** ($attempts - 1)));
            $db->update('ap_invoices', ['status' => 'posting', 'attempts' => $attempts, 'last_error' => substr($e->getMessage(), 0, 500),
                'next_attempt_at' => self::now()->modify("+{$delay} seconds")->format('Y-m-d H:i:s'), 'updated_at' => $now], 'id = ?', [$invoiceId]);
        }
    }

    /** @return array{id:int,invoice_no:string,duplicate:bool} */
    private static function send(array $inv): array
    {
        $db = ApDb::get();
        $req = $db->fetchOne('SELECT reference FROM ap_requests WHERE id = ?', [$inv['request_id']]);
        $client = self::client();
        $rate = (float)$inv['tax_rate'];
        $items = [];
        foreach ($db->fetchAll('SELECT * FROM ap_invoice_lines WHERE invoice_id = ? ORDER BY sort_no', [$inv['id']]) as $l) {
            $items[] = ['item_name' => 'Methyl bromide fumigation', 'description' => $l['description'], 'qty' => (float)$l['qty'], 'unit_price' => self::dec($l['unit_price']),
                'tax_rate' => $rate, 'tax_amount' => self::dec($l['tax_amount']), 'amount' => self::dec($l['amount'])];
        }
        $partyId = $client->findOrCreateParty(['name' => $inv['customer_name'], 'email' => $inv['customer_email'], 'tin' => $inv['customer_tin'], 'phone' => $inv['customer_phone'], 'address' => $inv['customer_address']]);
        return $client->createInvoice([
            'party_id' => $partyId, 'invoice_date' => $inv['invoice_date'], 'due_date' => $inv['due_date'],
            'subtotal' => self::dec($inv['subtotal']), 'tax_amount' => self::dec($inv['tax_amount']), 'discount' => self::dec($inv['discount']), 'total' => self::dec($inv['total']),
            'notes' => trim((string)$inv['notes'] . ' [' . $inv['invoice_no'] . ']'), 'external_ref' => $req['reference'], 'status' => 'unpaid', 'items' => $items,
        ]);
    }

    /** Retry postings whose backoff has elapsed (and any send abandoned by a crashed process). @return int invoices now posted */
    public static function retryPending(): int
    {
        $db = ApDb::get();
        $due = $db->fetchAll("SELECT id FROM ap_invoices WHERE (status = 'posting' AND (next_attempt_at IS NULL OR next_attempt_at <= ?)) OR (status = 'sending' AND updated_at <= ?) ORDER BY created_at", [self::now()->format('Y-m-d H:i:s'), self::now()->modify('-10 minutes')->format('Y-m-d H:i:s')]);
        $ok = 0;
        foreach ($due as $d) {
            self::attempt((string)$d['id']);
            $r = $db->fetchOne('SELECT status FROM ap_invoices WHERE id = ?', [$d['id']]);
            if ($r && $r['status'] === 'posted') { $ok++; }
        }
        return $ok;
    }
}
