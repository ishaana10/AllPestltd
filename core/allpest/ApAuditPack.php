<?php
declare(strict_types=1);

require_once __DIR__ . '/ApDb.php';
require_once __DIR__ . '/ApUploads.php';
require_once __DIR__ . '/ApAudit.php';

/** Audit pack for one treatment: a ZIP with everything an AFAS/BAF auditor asks for. Contains data of that treatment only. */
final class ApAuditPack
{
    /** @return string path of the temporary ZIP (caller deletes it, see send()) */
    public static function build(string $treatmentId): string
    {
        $db = ApDb::get();
        $t = $db->fetchOne('SELECT * FROM ap_treatments WHERE id = ?', [$treatmentId]);
        if (!$t) { throw new InvalidArgumentException('Treatment not found.'); }
        $req = $db->fetchOne('SELECT * FROM ap_requests WHERE id = ?', [$t['request_id']]);
        $containers = $db->fetchAll('SELECT tc.*, c.container_no, c.seal_no, c.commodity, c.origin, c.destination, c.quantity, c.bol_file_id FROM ap_treatment_containers tc JOIN ap_consignments c ON c.id = tc.consignment_id WHERE tc.treatment_id = ? ORDER BY c.container_no, tc.id', [$treatmentId]);
        $cids = array_column($containers, 'id');

        $sets = $db->fetchAll('SELECT * FROM ap_reading_sets WHERE treatment_id = ? ORDER BY taken_at, id', [$treatmentId]);
        $fileIds = [];
        foreach ($sets as &$s) {
            $s['readings'] = $db->fetchAll('SELECT container_id, location, value, meets FROM ap_readings WHERE reading_set_id = ? ORDER BY container_id, location', [$s['id']]);
            $s['photo_file_ids'] = array_column($db->fetchAll('SELECT file_id FROM ap_reading_set_files WHERE reading_set_id = ?', [$s['id']]), 'file_id');
            array_push($fileIds, ...$s['photo_file_ids']);
        }
        unset($s);
        $signatures = $db->fetchAll('SELECT * FROM ap_signatures WHERE treatment_id = ? ORDER BY version, role', [$treatmentId]);
        foreach ($signatures as $sg) { if (!empty($sg['image_file_id'])) { $fileIds[] = $sg['image_file_id']; } }
        foreach ($containers as $c) { if (!empty($c['bol_file_id'])) { $fileIds[] = $c['bol_file_id']; } }
        $fileIds = array_values(array_unique($fileIds));

        $equipIds = json_decode((string)($t['equipment_ids'] ?? '[]'), true) ?: [];
        $equipment = [];
        foreach ($equipIds as $eid) {
            $e = $db->fetchOne('SELECT * FROM ap_equipment WHERE id = ?', [$eid]);
            if ($e) { $e['log'] = $db->fetchAll('SELECT kind, note, created_at FROM ap_equipment_log WHERE equipment_id = ? ORDER BY created_at', [$eid]); $equipment[] = $e; }
        }
        $certs = [];
        foreach ($cids as $cid) {
            $c = $db->fetchOne('SELECT container_id, number, content_sha256, issued_at, emailed_at, verified_by, verified_at FROM ap_certificates WHERE container_id = ?', [$cid]);
            if ($c) { $certs[] = $c; }
        }
        $lot = $t['lot_id'] ? $db->fetchOne('SELECT * FROM ap_stock_lots WHERE id = ?', [$t['lot_id']]) : null;
        $activity = ApAudit::audit()->getLogs(['table' => 'ap_treatments'], 5000, 0);
        $activity = array_values(array_filter($activity, fn ($r) => (string)$r['audit_record_id'] === $treatmentId));
        $activity = array_reverse($activity);
        $chain = ApAudit::audit()->verifyChain();

        $docs = [
            'rof.json' => ['treatment' => $t, 'request' => $req, 'containers' => $containers,
                'versions' => $db->fetchAll('SELECT version, snapshot, reason, user_id, created_at FROM ap_treatment_versions WHERE treatment_id = ? ORDER BY version', [$treatmentId])],
            'readings.json' => $sets,
            'forecast.json' => ['min_c' => $t['forecast_min_c'], 'source' => $t['forecast_source'], 'retrieved_at' => $t['forecast_retrieved_at'], 'document' => json_decode((string)($t['forecast_json'] ?? 'null'), true)],
            'equipment.json' => $equipment,
            'technician.json' => ['technician' => $t['technician_id'] ? $db->fetchOne('SELECT * FROM ap_technicians WHERE id = ?', [$t['technician_id']]) : null,
                'officer' => $t['officer_id'] ? $db->fetchOne('SELECT * FROM ap_officers WHERE id = ?', [$t['officer_id']]) : null],
            'gas.json' => ['lot' => $lot, 'dose_g' => $t['dose_g'], 'applied_g' => $t['applied_g'],
                'movements' => $db->fetchAll('SELECT kind, grams, reason, discrepancy, user_id, created_at FROM ap_stock_movements WHERE treatment_id = ? ORDER BY created_at', [$treatmentId])],
            'signatures.json' => $signatures,
            'certificates.json' => $certs,
            'activity_log.json' => $activity,
        ];

        $zipPath = tempnam(sys_get_temp_dir(), 'appack');
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Could not create the audit pack.'); }
        $manifestFiles = [];
        foreach ($docs as $name => $data) {
            $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $zip->addFromString($name, $json);
            $manifestFiles[] = ['path' => $name, 'sha256' => hash('sha256', $json)];
        }
        $photos = [];
        foreach ($fileIds as $fid) {
            $row = $db->fetchOne('SELECT * FROM ap_files WHERE id = ?', [$fid]);
            $path = $row ? ApUploads::path($fid) : null;
            if (!$row || !$path || !is_file($path)) { $photos[] = ['file_id' => $fid, 'path' => null, 'sha256' => null, 'missing' => true]; continue; }
            $bytes = (string)file_get_contents($path);
            $ext = pathinfo((string)$row['stored_name'], PATHINFO_EXTENSION) ?: 'bin';
            $inZip = "photos/{$fid}.{$ext}";
            $zip->addFromString($inZip, $bytes);
            $sha = hash('sha256', $bytes);
            $photos[] = ['file_id' => $fid, 'path' => $inZip, 'purpose' => $row['purpose'], 'sha256' => $sha, 'matches_recorded_sha256' => $row['sha256'] === null || $row['sha256'] === $sha];
        }
        $manifest = [
            'treatment_id' => $treatmentId, 'request_reference' => $req['reference'] ?? null, 'rof_version' => (int)$t['version'],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'files' => $manifestFiles, 'photos' => $photos,
            'integrity' => ['status' => $chain['ok'] ? 'ok' : 'failed', 'entries_checked' => $chain['checked'], 'unsealed' => $chain['unsealed'], 'first_bad_id' => $chain['first_bad_id'], 'reason' => $chain['reason'],
                'scope' => 'whole audit log hash chain'],
        ];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();
        return $zipPath;
    }

    /** Send the file and delete it. */
    public static function send(string $path, string $filename, bool $headers = true): void
    {
        try {
            if ($headers && PHP_SAPI !== 'cli') {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
                header('Content-Length: ' . filesize($path));
            }
            readfile($path);
        } finally {
            @unlink($path);
        }
    }

    public static function stream(string $treatmentId): void
    {
        self::send(self::build($treatmentId), 'allpest-audit-pack-' . $treatmentId . '.zip');
    }
}
