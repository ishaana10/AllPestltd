<?php
declare(strict_types=1);

/** All Pest audit events, written to the StudioX hash-chained audit log (NuAudit) with the treatment id, user and device. */
final class ApAudit
{
    private static ?NuAudit $audit = null;

    public static function useAudit(?NuAudit $a): void { self::$audit = $a; }

    public static function event(string $action, string $treatmentId, array $details): void
    {
        try {
            $a = self::$audit ?? new NuAudit();
            $user = (string)($_SESSION['nu_username'] ?? ($_SESSION['nu_user_id'] ?? 'system'));
            $details = ['treatment_id' => $treatmentId, 'user' => $user, 'device' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'server'), 0, 255)] + $details;
            $a->event($action, $action . ' ' . $treatmentId, $details, 'success', ['table' => 'ap_treatments', 'record_id' => $treatmentId]);
        } catch (Throwable $e) {
            error_log('[ApAudit] ' . $e->getMessage());
        }
    }

    /** A request lifecycle event (received, confirmed, scheduled...) on the studio audit trail. Never blocks the work. */
    public static function request(string $action, string $requestId, array $details = []): void
    {
        try {
            $a = self::$audit ?? new NuAudit();
            $user = (string)($_SESSION['nu_username'] ?? ($_SESSION['nu_user_id'] ?? 'public'));
            $details = ['request_id' => $requestId, 'user' => $user, 'device' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'server'), 0, 255)] + $details;
            $a->event($action, $action . ' ' . $requestId, $details, 'success', ['table' => 'ap_requests', 'record_id' => $requestId]);
        } catch (Throwable $e) {
            error_log('[ApAudit] ' . $e->getMessage());
        }
    }

    /** The audit object used for reading the chain back (activity log, integrity check). */
    public static function audit(): NuAudit
    {
        return self::$audit ?? new NuAudit();
    }
}
