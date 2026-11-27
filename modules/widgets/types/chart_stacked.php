<?php
declare(strict_types=1);

/**
 * Stacked bar chart with several series. The SQL returns a "label" column plus one numeric column per series;
 * the column names become the legend (e.g. SELECT month AS label, passed AS Pass, failed AS Fail).
 */
return [
    'type'  => 'chart_stacked',
    'label' => 'Stacked Bar Chart',
    'icon'  => 'chart-bar',
    'group' => 'Charts',
    'defaults' => ['width' => 2, 'height' => 2, 'config' => ['sql' => '']],
    'fields' => [
        ['key' => 'sql', 'label' => 'SQL query', 'kind' => 'sql', 'required' => true, 'help' => 'Return a "label" column and one numeric column per series; each column name is a legend entry.'],
    ],
    'render' => static function (array $widget, array $cfg, array $ctx): string {
        $R = 'NuWidgetRegistry';
        $sql = trim((string)($cfg['sql'] ?? ''));
        if ($sql === '') return $R::emptyHint('Not configured yet.');
        $rows = NuWidgetSql::run($ctx['db'], $sql, $ctx['userId'], $ctx['role']);
        if (!$rows) return $R::emptyHint('No data yet.');
        $names = array_values(array_filter(array_keys($rows[0]), static fn($c) => $c !== 'label'));
        $series = [];
        foreach ($names as $n) {
            $series[] = ['name' => (string)$n, 'values' => array_map(static fn($r) => is_numeric($r[$n] ?? null) ? $r[$n] + 0 : 0, $rows)];
        }
        $json = json_encode([
            'type' => 'bar', 'stacked' => true, 'labels' => array_map('strval', array_column($rows, 'label')),
            'series' => $series, 'label' => (string)($widget['widget_title'] ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return '<div class="nu-wg-chart" data-nu-chart="' . $R::esc($json) . '" role="img" aria-label="' . $R::esc((string)($widget['widget_title'] ?? '')) . '"><canvas></canvas></div>';
    },
];
