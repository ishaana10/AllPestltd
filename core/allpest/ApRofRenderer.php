<?php
declare(strict_types=1);

require_once __DIR__ . '/ApBranding.php';

require_once dirname(__DIR__, 2) . '/lib/tcpdf/tcpdf.php';

/** Renders ApRof::document() as a PDF laid out like the methodology v3.0 record of fumigation (sections A to E). Times are shown in Fiji time. */
final class ApRofRenderer
{
    private static function fiji(?string $utc): string
    {
        if ($utc === null || $utc === '') { return ''; }
        try { return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Pacific/Fiji'))->format('d/m/Y H:i'); } catch (Throwable $e) { return $utc; }
    }

    /** @param array<string,mixed> $d ApRof::document() output, optionally with '_assets' => role => image path */
    public static function render(array $d): string
    {
        $e = fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $cell = fn (string $label, $value, string $w = '50%'): string => '<td width="' . $w . '"><span style="font-size:7pt;color:#475569">' . $e($label) . '</span><br/>' . nl2br($e($value)) . '</td>';
        $sec = fn (string $t, int $cols = 2): string => '<tr><td colspan="' . $cols . '" style="background-color:#e2e8f0"><b>' . $e($t) . '</b></td></tr>';
        $box = fn (string $label, bool $on): string => ($on ? '[X] ' : '[ ] ') . $e($label);
        $yn = fn ($v): string => $v === null ? '' : ((int)$v === 1 ? 'Yes' : 'No');
        $A = $d['A'] ?? []; $B = $d['B'] ?? []; $C = $d['C'] ?? []; $D = $d['D'] ?? []; $E = $d['E'] ?? [];
        $per = ($d['variant'] ?? '') === 'perishable';
        $targets = (array)($B['targets'] ?? []);
        $enc = (string)($C['enclosure_type'] ?? '');
        $names = [];
        foreach ((array)($B['containers'] ?? []) as $c) { $names[(string)$c['id']] = (string)$c['container_no']; }

        $h = (!empty($d['_branding']) ? ApBranding::headerHtml((array)$d['_branding']) : '') . '<h3 style="text-align:center">' . $e($d['title'] ?? '') . '</h3>';
        $h .= '<table border="1" cellpadding="3">' . $sec('Section A: Fumigator in charge') . '<tr>' . $cell('Full name', $A['full_name'] ?? '') . $cell('Accreditation number (if applicable)', $A['accreditation_no'] ?? '') . '</tr></table><br/>';
        $h .= '<table border="1" cellpadding="3">' . $sec('Section B: Job details')
            . '<tr>' . $cell('Treatment provider ID', $B['provider_id'] ?? '') . $cell('Client name/details', $B['client'] ?? '') . '</tr>'
            . '<tr>' . $cell('Job identification number', $B['job_no'] ?? '') . $cell('Description of consignment', $B['description'] ?? '') . '</tr>'
            . '<tr>' . $cell('Street address', $B['street'] ?? '') . '<td width="50%"><span style="font-size:7pt;color:#475569">Target of fumigation</span><br/>' . $box('Commodity', in_array('commodity', $targets, true)) . ' ' . $box('Container', in_array('container', $targets, true)) . ' '
                . $box('Packaging', in_array('packaging', $targets, true)) . ' ' . $box('Other: ' . ($B['target_other'] ?? ''), in_array('other', $targets, true)) . '</td></tr>'
            . '<tr>' . $cell('Suburb/town/city', $B['suburb'] ?? '') . $cell('Country / postcode', trim(($B['country'] ?? '') . ' ' . ($B['postcode'] ?? ''))) . '</tr>'
            . '<tr>' . $cell('Port of loading', $B['port_of_loading'] ?? '') . $cell('Order reference', $B['order_no'] ?? '') . '</tr>'
            . '<tr><td colspan="2"><span style="font-size:7pt;color:#475569">Consignment identification/Container numbers</span><br/>'
            . $e(implode(', ', array_map(fn ($c) => $c['container_no'] . ($c['seal_no'] ? ' (seal ' . $c['seal_no'] . ')' : ''), (array)($B['containers'] ?? [])))) . '</td></tr></table><br/>';
        $s = $C['schedule'] ?? [];
        $h .= '<table border="1" cellpadding="3">' . $sec('Section C: Fumigation details', 4)
            . '<tr>' . $cell('Specified treatment schedule', $s['name'] ?? '', '25%') . $cell('Dose rate (g/m3)', $s['dose_rate'] ?? '', '25%') . $cell('Exposure period (hrs)', $s['exposure_hours'] ?? '', '25%') . $cell('Temperature (C)', $s['min_temp_c'] ?? '', '25%') . '</tr>'
            . '<tr><td colspan="2"><span style="font-size:7pt;color:#475569">Enclosure type</span><br/>' . $box('Sheeted enclosure', in_array($enc, ['sheeted', 'container_stack'], true)) . ' ' . $box('Fumigation chamber', $enc === 'chamber') . ' '
                . $box('Un-sheeted container(s)', $enc === 'container') . ' ' . $box('Other: ' . ($C['enclosure_other'] ?? ''), $enc === 'other') . '</td>'
                . $cell('Length / height / width (m)', ($C['length_m'] ?? '') . ' / ' . ($C['height_m'] ?? '') . ' / ' . ($C['width_m'] ?? ''), '25%') . $cell('Total volume (m3)', $C['volume_m3'] ?? '', '25%') . '</tr>'
            . '<tr>' . $cell('Consignment suitable for fumigation?', $yn($C['suitable'] ?? null), '25%') . $cell('If no, what action?', $C['remedial_action'] ?? '', '25%') . $cell('Dose rate used (g/m3)', $C['dose_rate_used'] ?? '', '25%') . $cell('Calculated dose (g)', $C['calculated_dose_g'] ?? '', '25%') . '</tr>'
            . '<tr>' . $cell('Fumigation type', ($C['fumigation_type'] ?? '') === 'controlled' ? 'Controlled temperature' : 'Ambient temperature', '25%') . $cell('Forecast min temp (C)', $C['forecast_min_c'] ?? '', '25%')
                . $cell('Min enclosure temp (C)', $C['min_enclosure_temp_c'] ?? '', '25%') . $cell('Heaters used?', $yn($C['heaters_used'] ?? null), '25%') . '</tr>'
            . '<tr>' . $cell('Chloropicrin', !empty($C['chloropicrin']) ? 'Yes, ' . ($C['chloropicrin_pct'] ?? '') . '%' : 'No', '25%') . $cell('Amount CH3Br applied (g)', $C['applied_g'] ?? '', '25%')
                . ($per ? $cell('Retention rate (%) or CT product', trim(($C['retention_pct'] ?? '') . ' ' . ($C['ct_product'] ?? '')), '25%') . $cell('Probe location / load factor max, est (%)', trim(($C['probe_location'] ?? '') . ' ' . ($C['load_factor_max'] ?? '') . ' / ' . ($C['load_factor_est'] ?? '')), '25%') : '<td colspan="2"></td>') . '</tr></table><br/>';
        $h .= '<table border="1" cellpadding="3">' . $sec('Section D: Concentration' . ($per ? ' and temperature' : '') . ' readings', 7)
            . '<tr><td colspan="7"><span style="font-size:7pt;color:#475569">Serial number(s) of monitoring device(s)</span><br/>' . $e(implode(', ', (array)($D['serials'] ?? []))) . '</td></tr>'
            . '<tr><td colspan="7"><span style="font-size:7pt;color:#475569">Fumigant injection finished</span><br/>' . $e(self::fiji($D['injected_at'] ?? null)) . '</td></tr>'
            . '<tr style="background-color:#f1f5f9"><td width="12%"><b>Stage</b></td><td width="16%"><b>Date / time</b></td><td width="14%"><b>Container</b></td><td width="30%"><b>Locations</b></td><td width="10%"><b>Equil. %</b></td><td width="9%"><b>Std g/m3</b></td><td width="9%"><b>Initials</b></td></tr>';
        foreach ((array)($D['reading_sets'] ?? []) as $set) {
            $by = [];
            foreach ((array)$set['values'] as $x) { $by[(string)$x['container_id']][] = $x['location'] . ': ' . $x['value']; }
            if (!$by) { $by = ['' => []]; }
            $first = true;
            foreach ($by as $cid => $vals) {
                $h .= '<tr><td>' . ($first ? $e($set['stage']) : '') . '</td><td>' . ($first ? $e(self::fiji($set['taken_at'])) : '') . '</td><td>' . $e($names[$cid] ?? '') . '</td><td>' . $e(implode('; ', $vals)) . '</td><td>'
                    . ($first ? $e($set['equilibrium_pct']) : '') . '</td><td>' . ($first ? $e($set['standard']) : '') . '</td><td>' . ($first ? $e($set['initials']) : '') . '</td></tr>';
                $first = false;
            }
        }
        if (!$D['reading_sets']) { $h .= '<tr><td colspan="7">No readings recorded.</td></tr>'; }
        $h .= '<tr><td colspan="7"><span style="font-size:7pt;color:#475569">Top-up</span><br/>' . (!empty($D['topup_at']) ? $e($D['topup_g']) . ' g at ' . $e(self::fiji($D['topup_at'])) : 'None') . '</td></tr>'
            . '<tr><td colspan="7"><span style="font-size:7pt;color:#475569">Enclosure ventilation start</span><br/>' . $e(self::fiji($D['ventilation_at'] ?? null)) . '</td></tr>'
            . '<tr><td colspan="7"><span style="font-size:7pt;color:#475569">Final TLV readings (ppm)</span><br/>' . $e(implode('; ', array_map(fn ($t) => $t['container_no'] . ': ' . ($t['ppm'] === null ? '-' : $t['ppm']) . ($t['at'] ? ' at ' . self::fiji($t['at']) : ''), (array)($D['tlv'] ?? [])))) . '</td></tr></table><br/>';
        if ($per) {
            $h .= '<table border="1" cellpadding="3">' . $sec('Temperature readings (C), probes 1 to 7', 9) . '<tr style="background-color:#f1f5f9"><td width="19%"><b>Date / time</b></td>';
            for ($n = 1; $n <= 7; $n++) { $h .= '<td width="9%"><b>' . $n . '</b></td>'; }
            $h .= '<td width="18%"><b>Initials</b></td></tr>';
            foreach ((array)($D['temperatures'] ?? []) as $t) {
                $h .= '<tr><td>' . $e(self::fiji($t['taken_at'])) . '</td>';
                for ($n = 1; $n <= 7; $n++) { $h .= '<td>' . $e($t['values'][$n] ?? '') . '</td>'; }
                $h .= '<td>' . $e($t['initials']) . '</td></tr>';
            }
            if (!$D['temperatures']) { $h .= '<tr><td colspan="9">No temperature readings recorded.</td></tr>'; }
            $h .= '</table><br/>';
        }
        $h .= '<table border="1" cellpadding="3">' . $sec('Section E: Fumigator declaration', 3)
            . '<tr><td colspan="3">I, the fumigator-in-charge declare that the fumigation was conducted in accordance with the treatment schedule and all the requirements in the Methyl Bromide Fumigation Methodology, and the information I have provided is true and correct.</td></tr>';
        $assets = (array)($d['_assets'] ?? []);
        foreach ((array)($E['signatures'] ?? []) as $sig) {
            $img = $assets[$sig['role']] ?? null;
            $h .= '<tr><td width="30%">' . $e($sig['role'] === 'officer' ? 'Government officer if supervised' : 'Fumigator-in-charge') . '</td><td width="40%">' . $e($sig['signer_name']) . ($sig['signer_designation'] ? ', ' . $e($sig['signer_designation']) : '')
                . '<br/>' . $e(self::fiji($sig['signed_at'])) . '</td><td width="30%">' . ($img && is_file($img) ? '<img src="' . $e($img) . '" height="32"/>' : '') . '</td></tr>';
        }
        if (empty($E['signatures'])) { $h .= '<tr><td colspan="3">Not signed.</td></tr>'; }
        $h .= '<tr><td colspan="3"><span style="font-size:7pt;color:#475569">Fumigation result (Pass/Fail)</span><br/>' . $e(implode('; ', array_map(fn ($r) => $r['container_no'] . ': ' . ($r['result'] ?: 'pending'), (array)($E['results'] ?? [])))) . '</td></tr>';
        if (!empty($E['officer']['full_name'])) { $h .= '<tr><td colspan="3"><span style="font-size:7pt;color:#475569">Supervising officer</span><br/>' . $e($E['officer']['full_name']) . ($E['officer']['designation'] ? ', ' . $e($E['officer']['designation']) : '') . '</td></tr>'; }
        $h .= '</table>';

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('All Pest');
        $pdf->SetTitle('Record of fumigation');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont('helvetica', '', 8, '', true);
        $pdf->AddPage();
        $pdf->writeHTML($h, true, false, true, false, '');
        return $pdf->Output('', 'S');
    }
}
