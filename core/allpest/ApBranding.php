<?php
declare(strict_types=1);

/** Company logo and details, set once in Settings and printed at the top of every ROF and certificate (render-only, never hashed). */
final class ApBranding
{
    public const KEYS = ['company_name', 'company_address', 'company_phone', 'company_email', 'company_website', 'treatment_provider_id', 'company_logo_file_id'];

    /** @return array{name:string,address:string,phone:string,email:string,website:string,provider_id:string,logo_path:?string} */
    public static function load(): array
    {
        $logo = null;
        $id = (string)ApSettings::get('company_logo_file_id', '');
        if ($id !== '') {
            $p = ApUploads::path($id);
            $logo = ($p !== null && is_file($p)) ? $p : null;
        }
        return [
            'name' => (string)ApSettings::get('company_name', 'All Pest Hygiene Solutions'),
            'address' => (string)ApSettings::get('company_address', ''),
            'phone' => (string)ApSettings::get('company_phone', ''),
            'email' => (string)ApSettings::get('company_email', ''),
            'website' => (string)ApSettings::get('company_website', ''),
            'provider_id' => (string)ApSettings::get('treatment_provider_id', ''),
            'logo_path' => $logo,
        ];
    }

    public static function setLogo(string $fileId): void
    {
        $row = ApDb::get()->fetchOne('SELECT id, mime FROM ap_files WHERE id = ?', [$fileId]);
        if (!$row || strpos((string)$row['mime'], 'image/') !== 0) { throw new InvalidArgumentException('Choose an image file for the logo.'); }
        ApSettings::set('company_logo_file_id', $fileId);
    }

    public static function clearLogo(): void { ApSettings::set('company_logo_file_id', ''); }

    /** @param array<string,mixed> $b load() output */
    public static function headerHtml(array $b): string
    {
        $e = fn ($v): string => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $logo = !empty($b['logo_path']) && is_file((string)$b['logo_path']) ? '<img src="' . $e($b['logo_path']) . '" height="42"/>' : '';
        $contact = array_filter([(string)($b['phone'] ?? ''), (string)($b['email'] ?? ''), (string)($b['website'] ?? '')], fn ($x) => $x !== '');
        $lines = '<b style="font-size:12pt">' . $e($b['name'] ?? '') . '</b>';
        if (trim((string)($b['address'] ?? '')) !== '') { $lines .= '<br/>' . nl2br($e($b['address'])); }
        if ($contact) { $lines .= '<br/>' . $e(implode('  |  ', $contact)); }
        if ((string)($b['provider_id'] ?? '') !== '') { $lines .= '<br/>Treatment provider ID: ' . $e($b['provider_id']); }
        $left = $logo !== '' ? '<td width="22%" align="left">' . $logo . '</td><td width="78%" align="right">' : '<td width="100%" align="center">';
        return '<table cellpadding="2"><tr>' . $left . $lines . '</td></tr></table><hr/>';
    }
}
