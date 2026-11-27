<?php
declare(strict_types=1);

require_once __DIR__ . '/ApBranding.php';

require_once dirname(__DIR__, 2) . '/lib/tcpdf/tcpdf.php';

/** Renders a certificate's canonical data (see ApCertificates::data) as a PDF string. Certificates are never stored as files. */
final class ApCertificateRenderer
{
    /** 2026-11-27 18:51 -> 27/11/2026 (display only; the stored data and its hash are unchanged). */
    private static function dmy(string $v): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m) ? "$m[3]/$m[2]/$m[1]" : $v;
    }

    public static function render(array $d): string
    {
        $e = fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $row = fn (string $label, $value): string => '<tr><td width="34%" style="background-color:#eef2ee"><b>' . $e($label) . '</b></td><td width="66%">' . $e($value) . '</td></tr>';
        $c = $d['consignment'] ?? [];
        $s = $d['schedule'] ?? [];
        $a = $d['applied'] ?? [];
        $t = $d['times'] ?? [];

        $sec = fn (string $t): string => '<p style="font-size:11pt"><b>' . $e($t) . '</b></p>';
        $box = fn (string $label, bool $on): string => ($on ? '[X] ' : '[ ] ') . $e($label);
        $targets = (array)($d['targets'] ?? []);
        $enc = (string)($d['enclosure_type'] ?? '');
        $pp = $d['place_parts'] ?? [];
        $html = (!empty($d['_branding'])
            ? ApBranding::headerHtml((array)$d['_branding'])
            : '<h2 style="text-align:center">' . $e($d['provider']['name'] ?? '') . '</h2>' . '<p style="text-align:center">' . nl2br($e($d['provider']['address'] ?? '')) . '</p>')
            . '<p>Date issued (dd/mm/yyyy): <b>' . $e(self::dmy($d['issued_at'] ?? '')) . '</b></p>'
            . '<h3 style="text-align:center">Treatment certificate – Methyl bromide fumigation</h3>'
            . '<table border="1" cellpadding="4">' . $row('Certificate number', $d['number'] ?? '') . $row('Treatment provider ID number', $d['provider']['provider_id'] ?? '') . $row('Job reference', $d['request_reference'] ?? '') . '</table>'
            . $sec('Consignment details') . '<table border="1" cellpadding="4">'
            . $row('Consignment link (container numbers)', $c['container_no'] ?? '') . $row('Seal number(s)', $c['seal_no'] ?? '')
            . $row('Client name', $d['client']['name'] ?? '') . $row('Client address', $d['client']['address'] ?? '')
            . $row('Commodity description', $c['commodity'] ?? '') . $row('Commodity country of origin', $c['origin'] ?? '') . $row('Commodity quantity', $c['quantity'] ?? '')
            . $row('Port of loading', $c['port_of_loading'] ?? '') . $row('Destination country', $c['destination'] ?? '') . '</table>'
            . '<table border="1" cellpadding="4"><tr><td width="50%"><b>Target of fumigation</b><br/>' . $box('Commodity', in_array('commodity', $targets, true)) . '<br/>' . $box('Container', in_array('container', $targets, true)) . '<br/>'
            . $box('Packaging', in_array('packaging', $targets, true)) . '<br/>' . $box('Other: ' . ($d['target_other'] ?? ''), in_array('other', $targets, true)) . '</td>'
            . '<td width="50%"><b>Enclosure type</b><br/>' . $box('Sheeted enclosure', in_array($enc, ['sheeted', 'container_stack'], true)) . '<br/>' . $box('Fumigation chamber', $enc === 'chamber') . '<br/>'
            . $box('Un-sheeted container', $enc === 'container') . '<br/>' . $box('Other: ' . ($d['enclosure_other'] ?? ''), $enc === 'other') . '</td></tr></table>'
            . $sec('Treatment schedule (prescribed / specified treatment schedule)') . '<table border="1" cellpadding="4">'
            . $row('Dose rate (g/m3)', $s['dose_rate'] ?? '') . $row('Exposure period (hours)', $s['exposure_hours'] ?? '') . $row('Temperature (C)', $s['min_temp_c'] ?? '') . '</table>'
            . $sec('Fumigation details (treatment applied)') . '<table border="1" cellpadding="4">'
            . $row('Applied dose rate (g/m3)', $a['dose_rate_used'] ?? '') . $row('Exposure period (hours)', $a['exposure_hours'] ?? '') . $row('Temperature (C), forecast minimum', $a['forecast_min_c'] ?? '')
            . $row('Place of fumigation: street address', $pp['street'] ?? '') . $row('Suburb / town / city', $pp['suburb'] ?? '') . $row('Country / postcode', trim(($pp['country'] ?? '') . ' ' . ($pp['postcode'] ?? '')))
            . $row('Date and time fumigation commenced', ($t['start_point_at'] ?? '') . ' (Fiji time)') . $row('Date and time fumigation completed', ($t['ventilation_at'] ?? '') . ' (Fiji time)')
            . $row('Final TLV reading (ppm)', ($d['tlv_ppm'] ?? '') . ' at ' . ($d['tlv_at'] ?? '')) . '</table>'
            . $sec('Declaration') . '<p>I, the fumigator-in-charge declare:</p><p>' . nl2br($e($d['declaration'] ?? '')) . '</p>';
        $sig = $d['signatures']['fumigator'] ?? [];
        $img = $d['_assets']['fumigator_png'] ?? null;
        $html .= '<table border="1" cellpadding="4"><tr><td width="34%" style="background-color:#eef2ee"><b>Signature</b></td><td width="66%">' . ($img && is_file($img) ? '<img src="' . $e($img) . '" height="40"/>' : '<br/><br/>') . '</td></tr>'
            . $row('Full name', $sig['name'] ?? '') . $row('Date (dd/mm/yyyy)', self::dmy($sig['signed_at'] ?? '')) . $row('Accreditation number', $d['accreditation_no'] ?? '') . '</table>';
        $osig = $d['signatures']['officer'] ?? [];
        if ($osig) {
            $oimg = $d['_assets']['officer_png'] ?? null;
            $html .= '<p><b>Supervising Biosecurity officer</b><br/>' . ($oimg && is_file($oimg) ? '<img src="' . $e($oimg) . '" height="40"/><br/>' : '') . $e($osig['name'] ?? '') . ', ' . $e($osig['designation'] ?? '') . ' - signed ' . $e($osig['signed_at'] ?? '') . ' (Fiji time)</p>';
        }
        if (trim((string)($d['additional_declarations'] ?? '')) !== '') { $html .= $sec('Additional declarations') . '<p>' . nl2br($e($d['additional_declarations'])) . '</p>'; }

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('All Pest');
        $pdf->SetTitle('Fumigation certificate ' . ($d['number'] ?? ''));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->SetFont('helvetica', '', 9, '', true);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('', 'S');
    }
}
