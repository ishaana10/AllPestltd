<?php
declare(strict_types=1);

/**
 * Applies a saved report's filter definitions to its SQL. One implementation for running, PDF export and email.
 *
 * A filter is {field, label, operator, type?, param?}: `field` is a column of the report's result, `param` is the request key that carries
 * the value (defaults to the field, so older reports keep working; two filters on one column, such as a date range, use different params),
 * `type` is text, date or month (only changes the input shown). Fields, params and operators are whitelisted; values are always bound.
 */
final class NuReportFilters
{
    public const OPERATORS = ['=', 'LIKE', '>=', '<=', '>', '<', '!='];

    /** @return array{0:string,1:array<int,string>} sql and bound values */
    public static function apply(string $sql, array $filters, array $params): array
    {
        $where = [];
        $bind = [];
        foreach ($filters as $f) {
            $field = (string)($f['field'] ?? '');
            if ($field === '' || !preg_match('/^[A-Za-z0-9_]+$/', $field)) { continue; }
            $param = (string)($f['param'] ?? '');
            if ($param === '' || !preg_match('/^[A-Za-z0-9_]+$/', $param)) { $param = $field; }
            if (!isset($params[$param]) || is_array($params[$param]) || trim((string)$params[$param]) === '') { continue; }
            $value = trim((string)$params[$param]);
            $op = in_array($f['operator'] ?? '=', self::OPERATORS, true) ? (string)($f['operator'] ?? '=') : '=';
            if ($op === 'LIKE') {
                $where[] = "`{$field}` LIKE ?";
                $bind[] = '%' . $value . '%';
            } else {
                $where[] = "`{$field}` {$op} ?";
                $bind[] = $value;
            }
        }
        if (!$where) { return [$sql, []]; }
        return ['SELECT * FROM (' . rtrim($sql, "; \t\r\n") . ') AS _rpt WHERE ' . implode(' AND ', $where), $bind];
    }
}
