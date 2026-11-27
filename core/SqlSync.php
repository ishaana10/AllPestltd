<?php
declare(strict_types=1);
/**
 * Compares install.sql with the live database: finds tables and columns that are missing and creates them. Never drops or alters existing
 * columns. Seed INSERTs run only for tables this run created, and duplicates are ignored. Used by the Developer Settings "Database" section.
 */
final class SqlSync
{
    private const UUID_COLS = ['usr_id', 'umeta_user_id', 'token_user_id', 'usage_user_id', 'file_uploaded_by', 'form_created_by', 'report_created_by', 'query_created_by',
        'doc_created_by', 'sig_user_id', 'event_user_id', 'ph_user_id', 'wf_created_by', 'wfi_started_by', 'wfh_actor_id', 'errlog_user_id', 'audit_user_id', 'widget_user_id', 'pm_user_id', 'project_owner_id'];

    /** Quote-aware split on semicolons, so semicolons inside string literals do not break a statement. */
    public static function statements(string $sql): array
    {
        $out = []; $cur = ''; $sq = false; $dq = false; $esc = false;
        for ($i = 0, $n = strlen($sql); $i < $n; $i++) {
            $c = $sql[$i];
            if ($esc) { $cur .= $c; $esc = false; continue; }
            if ($c === '\\') { $cur .= $c; $esc = true; continue; }
            if ($c === "'" && !$dq) { $sq = !$sq; } elseif ($c === '"' && !$sq) { $dq = !$dq; }
            if ($c === ';' && !$sq && !$dq) { $out[] = $cur; $cur = ''; } else { $cur .= $c; }
        }
        if (trim($cur) !== '') { $out[] = $cur; }
        return array_values(array_filter(array_map('trim', $out), fn ($s) => self::strip($s) !== ''));
    }

    private static function strip(string $s): string
    {
        return trim((string)preg_replace('/^\s*(--[^\n]*\n|#[^\n]*\n|\/\*.*?\*\/\s*)*/s', '', $s . "\n"));
    }

    /** Table a CREATE TABLE statement makes, or null. */
    public static function createdTable(string $stmt): ?string
    {
        return preg_match('/^create\s+table\s+(?:if\s+not\s+exists\s+)?`?([A-Za-z0-9_]+)`?/i', self::strip($stmt), $m) ? $m[1] : null;
    }

    /** Table an INSERT statement targets, or null. */
    public static function insertedTable(string $stmt): ?string
    {
        return preg_match('/^insert\s+(?:ignore\s+)?into\s+`?([A-Za-z0-9_]+)`?/i', self::strip($stmt), $m) ? $m[1] : null;
    }

    /** Column definitions of a CREATE TABLE: name => definition text (no trailing comma). Only simple one-per-line columns. */
    public static function columns(string $stmt): array
    {
        $cols = [];
        foreach (preg_split('/\R/', self::strip($stmt)) as $line) {
            if (preg_match('/^\s*`([A-Za-z0-9_]+)`\s+(.+?),?\s*$/', $line, $m) && !preg_match('/^(primary|unique|key|index|constraint|foreign)\b/i', $m[2])) { $cols[$m[1]] = $m[2]; }
        }
        return $cols;
    }

    /** Apply the UUID install's user-id column type to a statement. */
    public static function adapt(string $stmt, bool $uuid): string
    {
        if (!$uuid) { return $stmt; }
        $stmt = (string)preg_replace('/(`usr_id`\s+)INT(?:\(\d+\))?\s+AUTO_INCREMENT\s+PRIMARY KEY/i', '$1VARCHAR(36) NOT NULL DEFAULT (UUID()) PRIMARY KEY', $stmt);
        foreach (self::UUID_COLS as $col) { $stmt = (string)preg_replace('/(`' . $col . '`\s+)INT(?:\(\d+\))?(?:\s+UNSIGNED)?/i', '$1VARCHAR(36)', $stmt); }
        return $stmt;
    }

    /** @return array{tables:string[],columns:array<string,string[]>} what install.sql has that the database lacks */
    public static function check(object $db, string $sqlFile): array
    {
        $have = []; foreach ($db->fetchAll('SHOW TABLES') as $r) { $have[strtolower((string)array_values($r)[0])] = true; }
        $missingTables = []; $missingCols = [];
        foreach (self::statements((string)file_get_contents($sqlFile)) as $s) {
            $t = self::createdTable($s);
            if ($t === null) { continue; }
            if (!isset($have[strtolower($t)])) { $missingTables[] = $t; continue; }
            $live = []; foreach ($db->fetchAll("SHOW COLUMNS FROM `$t`") as $c) { $live[strtolower((string)$c['Field'])] = true; }
            foreach (self::columns($s) as $name => $def) { if (!isset($live[strtolower($name)])) { $missingCols[$t][] = $name; } }
        }
        return ['tables' => $missingTables, 'columns' => $missingCols];
    }

    /** @return array{created:string[],columns:array<string,string[]>,seeded:int,errors:string[]} */
    public static function run(object $db, string $sqlFile, bool $uuid): array
    {
        $plan = self::check($db, $sqlFile);
        $rep = ['created' => [], 'columns' => [], 'seeded' => 0, 'errors' => []];
        $newTables = array_flip(array_map('strtolower', $plan['tables']));
        foreach (self::statements((string)file_get_contents($sqlFile)) as $s) {
            $t = self::createdTable($s);
            if ($t !== null && isset($newTables[strtolower($t)])) {
                try { $db->exec(self::adapt($s, $uuid)); $rep['created'][] = $t; } catch (Throwable $e) { $rep['errors'][] = "$t: " . $e->getMessage(); }
            } elseif ($t !== null && isset($plan['columns'][$t])) {
                $defs = self::columns($s);
                foreach ($plan['columns'][$t] as $col) {
                    try { $db->exec("ALTER TABLE `$t` ADD COLUMN " . self::adapt('`' . $col . '` ' . $defs[$col], $uuid)); $rep['columns'][$t][] = $col; }
                    catch (Throwable $e) { $rep['errors'][] = "$t.$col: " . $e->getMessage(); }
                }
            }
        }
        foreach (self::statements((string)file_get_contents($sqlFile)) as $s) {
            $t = self::insertedTable($s);
            if ($t === null || !isset($newTables[strtolower($t)])) { continue; }
            try { $db->exec(self::adapt($s, $uuid)); $rep['seeded']++; } catch (Throwable $e) { if (stripos($e->getMessage(), 'Duplicate') === false) { $rep['errors'][] = "seed $t: " . $e->getMessage(); } }
        }
        return $rep;
    }
}
