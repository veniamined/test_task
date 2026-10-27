#!/usr/bin/env php
<?php
/**
 * bin/sync.php — точка входа для сверки заказов маркетплейса с 1С.
 *
 * Запуск:
 *   php bin/sync.php
 *   php bin/sync.php --orders=data/orders.json --nomenclature=data/nomenclature_1c.csv
 *   php bin/sync.php --verbose
 *
 * Без аргументов использует данные по умолчанию из ./data/.
 */

declare(strict_types=1);

require __DIR__ . '/../src/Sync.php';

use App\Logger;
use App\Matcher;
use App\NomenclatureLoader;
use App\OrderLoader;
use App\Report;
use App\OrderReport;

// --- Разбор аргументов ------------------------------------------------------

$opts = getopt('', ['orders::', 'nomenclature::', 'out::', 'log::', 'verbose']);

$root           = dirname(__DIR__);
$ordersFile     = $opts['orders']     ?? $root . '/data/orders.json';
$nomenclatureFile = $opts['nomenclature'] ?? $root . '/data/nomenclature_1c.csv';
$outDir         = $opts['out']        ?? $root . '/reports';
$logFile        = $opts['log']        ?? $root . '/logs/sync.log';
$verbose        = isset($opts['verbose']);

// --- Подготовка каталогов ----------------------------------------------------

if (!is_dir($outDir) && !@mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Не удалось создать каталог отчётов: {$outDir}\n");
    exit(1);
}
$logDir = dirname($logFile);
if (!is_dir($logDir) && !@mkdir($logDir, 0777, true) && !is_dir($logDir)) {
    fwrite(STDERR, "Не удалось создать каталог логов: {$logDir}\n");
    exit(1);
}

$logger = new Logger($logFile, $verbose);

// --- Запуск ------------------------------------------------------------------

try {
    $logger->info('=== Старт сверки ===');
    $logger->info('Заказы: ' . $ordersFile);
    $logger->info('Номенклатура: ' . $nomenclatureFile);

    $nomenclature = NomenclatureLoader::fromCsv($nomenclatureFile, $logger);
    $totalRows    = array_sum($nomenclature->skuCount);
    $uniqueSkus   = count($nomenclature->items);
    $logger->info(sprintf('Номенклатура: строк %d, уникальных SKU %d', $totalRows, $uniqueSkus));
    foreach ($nomenclature->skuCount as $sku => $cnt) {
        if ($cnt > 1) {
            $logger->warning("SKU {$sku} в 1С представлен {$cnt} карточками — будет использована первая");
        }
    }

    $orders = OrderLoader::fromFile($ordersFile, $logger);
    $logger->info('Загружено заказов: ' . count($orders));

    $matcher = new Matcher($nomenclature, $logger);
    $result  = $matcher->match($orders);

    $report = new Report($result);
    $report->printConsole();

    $htmlPath = $outDir . '/report.html';
    $csvPath  = $outDir . '/report.csv';
    $report->saveHtml($htmlPath);
    $report->saveCsv($csvPath);

    $logger->info('Отчёт HTML: ' . $htmlPath);
    $logger->info('Отчёт CSV: ' . $csvPath);

    $errors = 0;
    foreach ($result['orders'] as $r) {
        if ($r instanceof OrderReport && $r->hasErrors()) {
            $errors++;
        }
    }
    echo "\nГотово. Заказов с ошибками: {$errors}\n";
    echo "Отчёты: {$htmlPath}, {$csvPath}\n";
    echo "Лог: {$logFile}\n";
    // Ненулевой код, если есть ошибки — удобно для CI.
    exit($errors > 0 ? 2 : 0);
} catch (Throwable $e) {
    $logger->error('Фатальная ошибка: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}
