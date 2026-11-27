<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/tcpdf/tcpdf.php';

/** Renders a certificate's canonical data (see ApCertificates::data) as a PDF string. Certificates are never stored as files. */
final class ApCertificateRenderer
{
    public static function render(array $d): string
    {
        $e = fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $row = fn (string $label, $value): string => '<tr><td width="34%" style="background-color:#eef2ee"><b>' . $e($label) . '</b></td><td width="66%">' . $e($value) . '</td></tr>';
        $c = $d['consignment'] ?? [];
        $s = $d['schedule'] ?? [];
        $a = $d['applied'] ?? [];
        $t = $d['times'] ?? [];

        $html = '<h2 style="text-align:center">' . $e($d['provider']['name'] ?? '') . '</h2>'
            . '<p style="text-align:center">' . nl2br($e($d['provider']['address'] ?? '')) . '</p>'
            . '<h3 style="text-align:center">Methyl bromide fumigation certificate</h3>'
            . '<table border="1" cellpadding="4">'
            . $row('Certificate number', $d['number'] ?? '') . $row('Date of issue', $d['issued_at'] ?? '')
            . $row('Treatment provider ID', $d['provider']['provider_id'] ?? '') . $row('Request reference', $d['request_reference'] ?? '')
            . $row('Client', trim(($d['client']['name'] ?? '') . "\n" . ($d['client']['address'] ?? '')))
            . $row('Container number', $c['container_no'] ?? '') . $row('Seal number', $c['seal_no'] ?? '')
            . $row('Commodity', $c['commodity'] ?? '') . $row('Origin', $c['origin'] ?? '') . $row('Destination', $c['destination'] ?? '') . $row('Quantity', $c['quantity'] ?? '')
            . $row('Target', $d['target'] ?? '') . $row('Enclosure', ($d['enclosure_type'] ?? '') . ' (' . ($d['fumigation_type'] ?? '') . ')') . $row('Place of treatment', $d['place'] ?? '')
            . $row('Schedule', ($s['name'] ?? '') . ': ' . ($s['dose_rate'] ?? '') . ' g/m3, ' . ($s['exposure_hours'] ?? '') . ' h, minimum ' . ($s['min_temp_c'] ?? '') . ' C')
            . $row('Applied', ($a['dose_rate_used'] ?? '') . ' g/m3 (' . ($a['applied_g'] ?? '') . ' g in ' . ($a['volume_m3'] ?? '') . ' m3), exposure ' . ($a['exposure_hours'] ?? '') . ' h, forecast minimum ' . ($a['forecast_min_c'] ?? '') . ' C')
            . $row('Exposure started', ($t['start_point_at'] ?? '') . ' (Fiji time)') . $row('Ventilation started', ($t['ventilation_at'] ?? '') . ' (Fiji time)')
            . $row('Final TLV reading', ($d['tlv_ppm'] ?? '') . ' ppm at ' . ($d['tlv_at'] ?? ''))
            . '</table><p>' . $e($d['declaration'] ?? '') . '</p><table border="0" cellpadding="4"><tr>';
        foreach (['fumigator' => 'Fumigator-in-charge', 'officer' => 'Supervising Biosecurity officer'] as $role => $label) {
            $sig = $d['signatures'][$role] ?? [];
            $img = $d['_assets'][$role . '_png'] ?? null;
            $html .= '<td width="50%"><b>' . $e($label) . '</b><br/>' . ($img && is_file($img) ? '<img src="' . $e($img) . '" height="40"/><br/>' : '<br/><br/>')
                . $e($sig['name'] ?? '') . '<br/>' . $e($sig['designation'] ?? '') . '<br/>Signed ' . $e($sig['signed_at'] ?? '') . ' (Fiji time)</td>';
        }
        $html .= '</tr></table>';

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
