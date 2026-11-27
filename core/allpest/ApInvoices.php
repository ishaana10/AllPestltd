<?php
declare(strict_types=1);

require_once __DIR__ . '/ApDb.php';
require_once __DIR__ . '/ApSettings.php';
require_once __DIR__ . '/ApErpxClient.php';

/**
 * One ERPX invoice per request, one line per container (failed ones included). Never throws on ERPX trouble:
 * the link stays `pending` with the error and a backoff, and the cron worker retries. States:
 * pending -> draft (reviewed mode) -> releasing -> released; auto mode goes pending -> released.
 * Money is handled as integer cents; prices are read from ERPX each time and never stored.
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

    public static function raiseForRequest(string $requestId): void
    {
        $db = ApDb::get();
        $link = $db->fetchOne('SELECT * FROM ap_invoice_links WHERE request_id = ?', [$requestId]);
        if ($link && $link['state'] !== 'pending') { return; }

        $treatments = $db->fetchAll('SELECT id, status, rof_type FROM ap_treatments WHERE request_id = ?', [$requestId]);
        if (!$treatments) { return; }
        foreach ($treatments as $t) { if ($t['status'] !== 'closed') { return; } }

        $now = self::now()->format('Y-m-d H:i:s');
        if (!$link) {
            $db->insert('ap_invoice_links', ['id' => ApId::uuid(), 'request_id' => $requestId, 'state' => 'pending', 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $link = $db->fetchOne('SELECT * FROM ap_invoice_links WHERE request_id = ?', [$requestId]);
        }

        try {
            $inv = self::send($requestId, $treatments);
            $state = ApSettings::get('invoice.mode', 'reviewed') === 'auto' ? 'released' : 'draft';
            $db->update('ap_invoice_links', ['state' => $state, 'erpx_invoice_id' => $inv['id'], 'erpx_invoice_no' => $inv['invoice_no'], 'last_error' => null, 'next_attempt_at' => null, 'updated_at' => $now], 'id = ?', [$link['id']]);
        } catch (Throwable $e) {
            $attempts = (int)$link['attempts'] + 1;
            $delay = min(21600, 60 * (2 ** ($attempts - 1)));
            $db->update('ap_invoice_links', [
                'attempts' => $attempts, 'last_error' => substr($e->getMessage(), 0, 500),
                'next_attempt_at' => self::now()->modify("+{$delay} seconds")->format('Y-m-d H:i:s'), 'updated_at' => $now,
            ], 'id = ?', [$link['id']]);
        }
    }

    /** @return array{id:int,invoice_no:string,duplicate:bool} */
    private static function send(string $requestId, array $treatments): array
    {
        $db = ApDb::get();
        $req = $db->fetchOne('SELECT * FROM ap_requests WHERE id = ?', [$requestId]);
        $client = self::client();
        $rate = (string)ApSettings::get('erpx.tax_rate', '0');
        $prices = [];
        $items = [];
        $subtotal = 0;
        $tax = 0;
        foreach ($treatments as $t) {
            $code = trim((string)ApSettings::get('erpx.product.' . $t['rof_type'], ''));
            if ($code === '') { throw new RuntimeException("No ERPX product mapped for {$t['rof_type']} fumigation."); }
            $prices[$code] ??= self::toCents($client->productPrice($code));
            $rows = $db->fetchAll('SELECT tc.id, tc.result, c.container_no FROM ap_treatment_containers tc JOIN ap_consignments c ON c.id = tc.consignment_id WHERE tc.treatment_id = ? ORDER BY c.container_no, tc.id', [$t['id']]);
            foreach ($rows as $r) {
                $p = $prices[$code];
                $lineTax = self::taxCents($p, $rate);
                $subtotal += $p;
                $tax += $lineTax;
                $items[] = [
                    'item_name' => 'Methyl bromide fumigation (' . $t['rof_type'] . ')',
                    'description' => 'Container ' . ($r['container_no'] ?: 'n/a') . ' - ' . ($r['result'] === 'pass' ? 'passed' : 'treated'),
                    'qty' => 1, 'unit_price' => self::money($p), 'tax_rate' => (float)($rate === '' ? 0 : $rate),
                    'tax_amount' => self::money($lineTax), 'amount' => self::money($p + $lineTax),
                ];
            }
        }
        if (!$items) { throw new RuntimeException('No containers to bill.'); }

        $partyId = $client->findOrCreateParty([
            'name' => $req['customer_name'], 'email' => strtolower((string)$req['customer_email']), 'tin' => $req['customer_tin'],
            'phone' => $req['customer_phone'], 'address' => $req['customer_address'],
        ]);
        $fiji = self::now()->setTimezone(new DateTimeZone('Pacific/Fiji'));
        return $client->createInvoice([
            'party_id' => $partyId, 'invoice_date' => $fiji->format('Y-m-d'), 'due_date' => $fiji->modify('+30 days')->format('Y-m-d'),
            'subtotal' => self::money($subtotal), 'tax_amount' => self::money($tax), 'discount' => '0.00', 'total' => self::money($subtotal + $tax),
            'notes' => 'Fumigation request ' . $req['reference'], 'external_ref' => $req['reference'],
            'status' => ApSettings::get('invoice.mode', 'reviewed') === 'auto' ? 'unpaid' : 'draft', 'items' => $items,
        ]);
    }

    /** Reviewed mode: release the draft in ERPX, exactly once even if called twice at the same time. */
    public static function release(string $requestId, string $adminId): void
    {
        $db = ApDb::get();
        $claimed = $db->update('ap_invoice_links', ['state' => 'releasing', 'updated_at' => self::now()->format('Y-m-d H:i:s')], "request_id = ? AND state = 'draft'", [$requestId]);
        if ($claimed !== 1) { return; }
        $link = $db->fetchOne('SELECT * FROM ap_invoice_links WHERE request_id = ?', [$requestId]);
        try {
            self::client()->releaseInvoice((int)$link['erpx_invoice_id']);
        } catch (Throwable $e) {
            $db->update('ap_invoice_links', ['state' => 'draft', 'last_error' => substr($e->getMessage(), 0, 500)], 'id = ?', [$link['id']]);
            throw $e;
        }
        $db->update('ap_invoice_links', ['state' => 'released', 'last_error' => null, 'updated_at' => self::now()->format('Y-m-d H:i:s')], 'id = ?', [$link['id']]);
    }

    /** Retry links whose backoff has elapsed. @return int links that now hold an ERPX invoice */
    public static function retryPending(): int
    {
        $db = ApDb::get();
        $due = $db->fetchAll("SELECT request_id FROM ap_invoice_links WHERE state = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= ?) ORDER BY created_at", [self::now()->format('Y-m-d H:i:s')]);
        $ok = 0;
        foreach ($due as $d) {
            self::raiseForRequest((string)$d['request_id']);
            $l = $db->fetchOne('SELECT state FROM ap_invoice_links WHERE request_id = ?', [$d['request_id']]);
            if ($l && $l['state'] !== 'pending') { $ok++; }
        }
        return $ok;
    }
}
