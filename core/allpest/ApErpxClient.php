<?php
declare(strict_types=1);

/** Thin client for the NuvisERPX REST API. `$http` is fn(method, url, headers, ?body): array{status:int, body:string} and throws on transport failure. */
final class ApErpxClient
{
    /** @var callable */
    private $http;

    public function __construct(private string $baseUrl, private string $apiKey, callable $http)
    {
        $this->http = $http;
    }

    /** Real transport (curl). Throws RuntimeException on a network error or timeout. */
    public static function curlHttp(int $timeout = 20): callable
    {
        return static function (string $method, string $url, array $headers, ?string $body) use ($timeout): array {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8]);
            if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
            $out = curl_exec($ch);
            if ($out === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException('ERPX transport: ' . $e); }
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return ['status' => $status, 'body' => (string)$out];
        };
    }

    private function call(string $method, string $path, ?array $json = null): array
    {
        $headers = ['X-API-Key: ' . $this->apiKey, 'Accept: application/json'];
        $body = null;
        if ($json !== null) { $body = json_encode($json); $headers[] = 'Content-Type: application/json'; }
        $r = ($this->http)($method, rtrim($this->baseUrl, '/') . $path, $headers, $body);
        $data = json_decode((string)$r['body'], true);
        if ($r['status'] < 200 || $r['status'] >= 300 || !is_array($data)) {
            $msg = is_array($data) ? (string)($data['message'] ?? '') : '';
            throw new RuntimeException("ERPX {$method} {$path} failed ({$r['status']}) {$msg}");
        }
        return $data;
    }

    /** Customer id in ERPX: found by email, else TIN, else created. */
    public function findOrCreateParty(array $customer): int
    {
        $d = $this->call('POST', '/api/v1/parties.php', array_filter([
            'name' => $customer['name'] ?? null, 'email' => $customer['email'] ?? null, 'tin' => $customer['tin'] ?? null,
            'phone' => $customer['phone'] ?? null, 'address' => $customer['address'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
        return (int)$d['id'];
    }

    /** ERPX selling price for a product code, as a decimal string. ERPX is the only source of prices. */
    public function productPrice(string $productCode): string
    {
        $d = $this->call('GET', '/api/v1/products.php?product_code=' . rawurlencode($productCode));
        $row = $d['data'][0] ?? null;
        if (!$row || !isset($row['selling_price'])) { throw new RuntimeException("ERPX product {$productCode} not found."); }
        return (string)$row['selling_price'];
    }

    /** @return array{id:int,invoice_no:string,duplicate:bool} */
    public function createInvoice(array $payload): array
    {
        $d = $this->call('POST', '/api/v1/invoices.php', $payload);
        return ['id' => (int)$d['invoice_id'], 'invoice_no' => (string)$d['invoice_no'], 'duplicate' => !empty($d['duplicate'])];
    }

    public function releaseInvoice(int $id): void
    {
        $this->call('PATCH', '/api/v1/invoices.php?id=' . $id, ['status' => 'unpaid']);
    }
}
