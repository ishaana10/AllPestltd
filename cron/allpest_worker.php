<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI execution only\n"); }
/** Retries ERPX invoices that could not be sent. Run every few minutes: php cron/allpest_worker.php */
require_once dirname(__DIR__) . '/core/Database.php';
foreach (['ApId', 'ApSchema', 'ApDb', 'ApSettings', 'ApErpxClient', 'ApInvoices', 'ApStock', 'ApReminders'] as $c) { require_once dirname(__DIR__) . "/core/allpest/$c.php"; }
$db = NuDatabase::getInstance();
ApSchema::ensure($db);
ApDb::set($db);
echo '[' . gmdate('Y-m-d H:i:s') . '] allpest worker: ' . ApInvoices::retryPending() . " invoice(s) sent\n";
echo '[' . gmdate('Y-m-d H:i:s') . '] allpest reminders: ' . (ApReminders::sendDaily(new DateTimeImmutable('now', new DateTimeZone('UTC'))) ? 'emailed' : 'none sent') . "\n";
