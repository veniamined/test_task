<?php
/**
 * Sync — модуль сверки заказов маркетплейса с номенклатурой 1С.
 *
 * Весь прикладной код собран в одном файле, чтобы задача оставалась читаемой
 * и не разрасталась в микросервис. Классы небольшые и слабо связаны:
 *   - Logger           — простой писатель в файл (и в stderr при --verbose).
 *   - Nomenclature     — контейнер товаров 1С + карта дублей артикулов.
 *   - NomenclatureLoader — парсер CSV в формате 1С (UTF-8, BOM, «;», запятая, пробел-тысяч).
 *   - OrderLoader      — загрузчик заказов (из файла; тривиально меняется на HTTP).
 *   - OrderReport      — DTO результата проверки одного заказа.
 *   - Matcher          — основная логика: нормализация SKU, контроль остатка
 *                        с учётом предыдущих заказов, детект дублей и т.д.
 *   - Report           — рендер консоли / CSV / HTML.
 *
 * PHP 8.1+. Внешних зависимостей нет.
 */

declare(strict_types=1);

namespace App;

use RuntimeException;
use Throwable;

// ============================================================================

final class Logger
{
    private bool $verbose;

    public function __construct(private string $file, bool $verbose = false)
    {
        $this->verbose = $verbose;
    }

    public function error(string $msg): void   { $this->write('ERROR', $msg); }
    public function warning(string $msg): void  { $this->write('WARN ', $msg); }
    public function info(string $msg): void     { $this->write('INFO ', $msg); }

    private function write(string $level, string $msg): void
    {
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $msg);
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
        if ($this->verbose) {
            fwrite(STDERR, $line);
        }
    }
}

// ============================================================================

final class Nomenclature
{
    /** @var array<string, array{code: string, sku: string, name: string, unit: string, price: float, stock: int}> */
    public array $items = [];

    /** @var array<string, int> Сколько раз встречается SKU в выгрузке (для детекта дублей в 1С). */
    public array $skuCount = [];

    public function has(string $sku): bool
    {
        return isset($this->items[$sku]);
    }

    /** @return array{code: string, sku: string, name: string, unit: string, price: float, stock: int}|null */
    public function get(string $sku): ?array
    {
        return $this->items[$sku] ?? null;
    }
}

// ============================================================================

final class NomenclatureLoader
{
    /**
     * Читает выгрузку 1С в формате:
     *   UTF-8 (с BOM), разделитель «;», запятая как десятичный разделитель,
     *   пробел как разделитель тысяч, перевод строк CRLF.
     * Битые строки пишет в лог, но не валит общую загрузку.
     */
    public static function fromCsv(string $path, Logger $logger): Nomenclature
    {
        if (!is_file($path)) {
            throw new RuntimeException("Файл номенклатуры не найден: {$path}");
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть файл: {$path}");
        }

        // Снимаем BOM, если есть.
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, 0, ';');
        if ($header === false) {
            fclose($handle);
            throw new RuntimeException("Пустой файл номенклатуры: {$path}");
        }
        $header = array_map('trim', $header);
        // Ожидаем 6 колонок: Код;Артикул;Наименование;ЕдИзм;Цена;Остаток.
        // Если порядок другой — это уже проблема источника, фиксируем в логе.
        if (count($header) < 6) {
            fclose($handle);
            throw new RuntimeException("В заголовке номенклатуры ожидается 6 колонок, получено " . count($header));
        }

        $nom = new Nomenclature();
        $lineNo = 1;
        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            $lineNo++;
            try {
                // Пропуск пустых хвостовых строк (частое явление после CRLF).
                if (count($row) === 1 && trim((string)$row[0]) === '') {
                    continue;
                }
                if (count($row) < 6) {
                    throw new RuntimeException("Ожидается 6 колонок, получено " . count($row));
                }
                [$code, $sku, $name, $unit, $priceRaw, $stockRaw] = array_slice(array_map('trim', $row), 0, 6);

                if ($sku === '') {
                    $logger->warning("Номенклатура, строка {$lineNo}: пустой артикул — пропуск");
                    continue;
                }
                $price = self::parseAmount($priceRaw);
                $stock = (int) self::parseAmount($stockRaw);

                $nom->skuCount[$sku] = ($nom->skuCount[$sku] ?? 0) + 1;
                if (isset($nom->items[$sku])) {
                    $logger->warning(sprintf(
                        'Номенклатура: дубль SKU %s (коды %s и %s). Использую первую встреченную карточку.',
                        $sku, $nom->items[$sku]['code'], $code
                    ));
                } else {
                    $nom->items[$sku] = [
                        'code'  => $code,
                        'sku'   => $sku,
                        'name'  => $name,
                        'unit'  => $unit,
                        'price' => $price,
                        'stock' => $stock,
                    ];
                }
            } catch (Throwable $e) {
                $logger->error("Номенклатура, строка {$lineNo}: " . $e->getMessage());
            }
        }
        fclose($handle);

        return $nom;
    }

    /**
     * Парсит число из выгрузки 1С:
     *   «1 250,00» -> 1250.00
     *   «13,000»   -> 13.0
     *   «0,45»     -> 0.45
     * Убираем пробелы (в т.ч. NBSP) и меняем запятую на точку.
     */
    public static function parseAmount(string $value): float
    {
        $value = str_replace([' ', "\xC2\xA0"], '', trim($value));
        $value = str_replace(',', '.', $value);
        if ($value === '' || !is_numeric($value)) {
            throw new RuntimeException("Некорректное число: '{$value}'");
        }
        return (float) $value;
    }
}

// ============================================================================

final class OrderLoader
{
    /**
     * Загружает заказы из JSON-файла.
     * Чтобы переключиться на реальный API маркетплейса, достаточно заменить
     * тело на file_get_contents($url) или HTTP-клиент — парсер тот же.
     *
     * @return array<int, array>
     */
    public static function fromFile(string $path, Logger $logger): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Файл заказов не найден: {$path}");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Не удалось прочитать файл: {$path}");
        }
        return self::parseJson($content);
    }

    public static function fromUrl(string $url, Logger $logger): array
    {
        $content = @file_get_contents($url);
        if ($content === false) {
            throw new RuntimeException("Не удалось получить данные по URL: {$url}");
        }
        return self::parseJson($content);
    }

    /** @return array<int, array> */
    private static function parseJson(string $content): array
    {
        $data = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Ошибка JSON: ' . json_last_error_msg());
        }
        if (!is_array($data) || !isset($data['data']['orders']) || !is_array($data['data']['orders'])) {
            throw new RuntimeException("В ответе отсутствует data.orders");
        }
        return $data['data']['orders'];
    }
}

// ============================================================================

final class OrderReport
{
    /** @var array<int, array{level: string, type: string, msg: string}> */
    public array $issues = [];

    /** @var array<int, array> */
    public array $items = [];

    public bool $isDuplicate = false;
    public bool $cancelled = false;
    public bool $empty = false;

    public function __construct(
        public string $orderId,
        public string $status,
        public string $createdAt,
        public string $buyer,
        public ?float $declaredTotal = null,
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->issues as $i) {
            if ($i['level'] === 'error') {
                return true;
            }
        }
        return false;
    }
}

// ============================================================================

final class Matcher
{
    /** @var array<string, int> Виртуальный остаток: факт минус уже принятые к отгрузке заказы. */
    private array $virtualStock = [];

    /** @var array<string, true> ID заказов, уже обработанных в текущем прогоне. */
    private array $seenOrderIds = [];

    public function __construct(
        private Nomenclature $nom,
        private Logger $logger
    ) {}

    /**
     * @param array<int, array> $orders
     * @return array{orders: array<int, OrderReport>, summary: array<string, int>}
     */
    public function match(array $orders): array
    {
        // Инициализация виртуального остатка из номенклатуры.
        foreach ($this->nom->items as $sku => $item) {
            $this->virtualStock[$sku] = $item['stock'];
        }

        // Обрабатываем в порядке created_at — это даёт осмысленную
        // модель «первый пришёл — первый получил остаток». Дубли ID заказа
        // при этом становятся детерминированными: первый экземпляр «настоящий»,
        // последующие — помечаются и остаток не расходуют.
        usort($orders, static fn($a, $b) => ($a['created_at'] ?? '') <=> ($b['created_at'] ?? ''));

        $reports = [];
        foreach ($orders as $order) {
            $reports[] = $this->matchOrder($order);
        }

        return ['orders' => $reports, 'summary' => $this->buildSummary($reports)];
    }

    private function matchOrder(array $order): OrderReport
    {
        $orderId  = (string)($order['id'] ?? '');
        $status   = (string)($order['status'] ?? '');
        $createdAt = (string)($order['created_at'] ?? '');
        $buyer    = (string)($order['buyer']['name'] ?? '');
        $totalRaw = $order['total'] ?? null;
        $report   = new OrderReport(
            $orderId,
            $status,
            $createdAt,
            $buyer,
            is_numeric($totalRaw) ? (float)$totalRaw : null
        );

        $report->cancelled = ($status === 'cancelled');

        // Дубль заказа: ID уже встречался ранее в этом прогоне.
        // Первый экземпляр считается «настоящим» и расходует остаток,
        // последующие помечаются как дубль и в отгрузку не идут.
        if (isset($this->seenOrderIds[$orderId])) {
            $report->isDuplicate = true;
        } else {
            $this->seenOrderIds[$orderId] = true;
        }

        $items = $order['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            $report->empty = true;
            $report->issues[] = ['level' => 'warn', 'type' => 'empty_order', 'msg' => 'Заказ без позиций'];
            return $report;
        }

        if ($report->cancelled) {
            $report->issues[] = ['level' => 'info', 'type' => 'cancelled', 'msg' => 'Заказ отменён, в отгрузку не идёт'];
        }

        if ($report->isDuplicate) {
            $report->issues[] = [
                'level' => 'error',
                'type'  => 'duplicate_order',
                'msg'   => 'Дубль заказа — уже обработан ранее, в отгрузку не идёт',
            ];
        }

        // Дубли позиций внутри одного заказа (по нормализованному SKU).
        $skuSeen = [];
        foreach ($items as $idx => $item) {
            try {
                $sku = self::normalizeSku((string)($item['sku'] ?? ''));
                if (isset($skuSeen[$sku])) {
                    $report->issues[] = [
                        'level' => 'warn',
                        'type'  => 'duplicate_item',
                        'msg'   => sprintf('В заказе повторяется SKU %s (строки %d и %d)', $sku, $skuSeen[$sku], $idx),
                    ];
                } else {
                    $skuSeen[$sku] = $idx;
                }
            } catch (Throwable $e) {
                $this->logger->error("Заказ {$orderId}, строка {$idx}: " . $e->getMessage());
            }
        }

        // Построчная сверка.
        foreach ($items as $idx => $item) {
            $report->items[] = $this->matchItem($report, $item, $idx);
        }

        // Сверка суммы заказа (если есть).
        $this->checkTotal($order, $report);

        return $report;
    }

    private function matchItem(OrderReport $report, array $item, int $idx): array
    {
        $result = [
            'sku'         => '',
            'name'        => (string)($item['title'] ?? ''),
            'qty'         => (int)($item['quantity'] ?? 0),
            'order_price' => null,
            'c1_price'    => null,
            'stock_total' => null,  // исходный остаток в 1С
            'stock_left'  => null,  // доступно к моменту обработки
            'price_ok'    => null,
            'stock_ok'    => null,
            'issues'      => [],
        ];

        try {
            $sku = self::normalizeSku((string)($item['sku'] ?? ''));
            $result['sku'] = $sku;
        } catch (Throwable $e) {
            $this->logger->error("Заказ {$report->orderId}, строка {$idx}: " . $e->getMessage());
            $result['issues'][] = 'Ошибка обработки: ' . $e->getMessage();
            $report->issues[] = ['level' => 'error', 'type' => 'bad_item', 'msg' => "Строка {$idx}: " . $e->getMessage()];
            return $result;
        }

        // Цену заказа фиксируем всегда — она нужна для проверки total,
        // даже если артикула нет в 1С.
        $orderPrice = $item['price'] ?? null;
        if ($orderPrice === null) {
            $result['issues'][] = 'Не указана цена';
            $report->issues[] = ['level' => 'error', 'type' => 'no_price', 'msg' => "SKU {$sku}: цена не указана"];
        } else {
            $result['order_price'] = (float) $orderPrice;
        }

        $c1Item = $this->nom->get($sku);
        if ($c1Item === null) {
            $result['issues'][] = 'Артикул отсутствует в 1С';
            $report->issues[] = ['level' => 'error', 'type' => 'sku_not_found', 'msg' => "SKU {$sku} отсутствует в 1С"];
            return $result;
        }

        $result['c1_price']    = $c1Item['price'];
        $result['stock_total'] = $c1Item['stock'];

        // Сравнение цен.
        if ($result['order_price'] !== null) {
            if (abs($result['order_price'] - $c1Item['price']) > 0.01) {
                $result['price_ok'] = false;
                $result['issues'][] = sprintf('Цена: заказ %.2f, 1С %.2f', $result['order_price'], $c1Item['price']);
                $report->issues[] = [
                    'level' => 'error',
                    'type'  => 'price_mismatch',
                    'msg'   => sprintf('SKU %s: цена в заказе %.2f ≠ 1С %.2f', $sku, $result['order_price'], $c1Item['price']),
                ];
            } else {
                $result['price_ok'] = true;
            }
        }

        // Остаток — кумулятивно, по всем «настоящим» заказам в порядке created_at.
        // Отменённые и дублёные заказы остаток не расходуют.
        $qty = $result['qty'];
        if ($qty <= 0) {
            $result['issues'][] = "Некорректное количество: {$qty}";
            $report->issues[] = ['level' => 'warn', 'type' => 'bad_qty', 'msg' => "SKU {$sku}: некорректное количество {$qty}"];
        }
        if ($report->cancelled || $report->isDuplicate) {
            $result['stock_ok'] = null;
            $result['stock_left'] = $this->virtualStock[$sku] ?? $c1Item['stock'];
            return $result;
        }
        $available = $this->virtualStock[$sku] ?? 0;
        $result['stock_left'] = $available;
        if ($available < $qty) {
            $result['stock_ok'] = false;
            $result['issues'][] = sprintf('Не хватает остатка: нужно %d, доступно %d', $qty, $available);
            $report->issues[] = [
                'level' => 'error',
                'type'  => 'stock_short',
                'msg'   => sprintf('SKU %s: нужно %d шт., остаток %d шт. (с учётом предыдущих заказов)', $sku, $qty, $available),
            ];
            // Списываем «до нуля», чтобы следующие заказы тоже честно видели дефицит.
            $this->virtualStock[$sku] = 0;
        } else {
            $result['stock_ok'] = true;
            $this->virtualStock[$sku] = $available - $qty;
        }

        return $result;
    }

    private function checkTotal(array $order, OrderReport $report): void
    {
        $declared = $report->declaredTotal;
        $computed = 0.0;
        foreach ($report->items as $ri) {
            if ($ri['order_price'] !== null) {
                $computed += $ri['order_price'] * $ri['qty'];
            }
        }
        if ($declared === null) {
            $report->issues[] = ['level' => 'warn', 'type' => 'null_total', 'msg' => 'У заказа нет суммы (total = null)'];
            return;
        }
        if (abs($computed - $declared) > 0.01) {
            $report->issues[] = [
                'level' => 'warn',
                'type'  => 'total_mismatch',
                'msg'   => sprintf('Сумма позиций %.2f ≠ total %.2f', $computed, $declared),
            ];
        }
    }

    /**
     * Нормализует артикул: trim + uppercase. Маркетплейс иногда отдаёт
     * « ts-1002» с пробелом и в нижнем регистре — это один и тот же товар.
     */
    public static function normalizeSku(string $sku): string
    {
        $sku = trim($sku);
        if ($sku === '') {
            throw new RuntimeException('Пустой артикул');
        }
        return strtoupper($sku);
    }

    /**
     * @param array<int, OrderReport> $reports
     * @return array<string, int>
     */
    private function buildSummary(array $reports): array
    {
        $c = [
            'total'          => count($reports),
            'with_errors'    => 0,
            'cancelled'      => 0,
            'duplicates'     => 0,
            'empty_orders'   => 0,
            'sku_not_found'  => 0,
            'price_mismatch' => 0,
            'stock_short'    => 0,
            'duplicate_item' => 0,
            'total_mismatch' => 0,
            'null_total'     => 0,
            'no_price'       => 0,
            'bad_qty'        => 0,
            'bad_item'       => 0,
        ];
        foreach ($reports as $r) {
            if ($r->cancelled)     { $c['cancelled']++; }
            if ($r->isDuplicate)   { $c['duplicates']++; }
            if ($r->empty)         { $c['empty_orders']++; }
            if ($r->hasErrors())   { $c['with_errors']++; }
            foreach ($r->issues as $issue) {
                $c[$issue['type']] = ($c[$issue['type']] ?? 0) + 1;
            }
        }
        return $c;
    }
}

// ============================================================================

final class Report
{
    /** @param array{orders: array<int, OrderReport>, summary: array<string, int>} $result */
    public function __construct(private array $result) {}

    public function saveHtml(string $path): void
    {
        file_put_contents($path, $this->renderHtml());
    }

    public function saveCsv(string $path): void
    {
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new RuntimeException("Не удалось создать CSV: {$path}");
        }
        // Заголовок.
        fputcsv($fp, [
            'order_id', 'created_at', 'status', 'buyer',
            'sku', 'title', 'qty',
            'price_order', 'price_1c', 'price_ok',
            'stock_total', 'stock_left', 'stock_ok',
            'order_issues',
        ], ';');

        foreach ($this->result['orders'] as $order) {
            $orderIssues = implode(' | ', array_map(static fn($i) => $i['msg'], $order->issues));
            if (count($order->items) === 0) {
                fputcsv($fp, [
                    $order->orderId, $order->createdAt, $order->status, $order->buyer,
                    '', '', '', '', '', '', '', '', '', $orderIssues,
                ], ';');
                continue;
            }
            foreach ($order->items as $item) {
                fputcsv($fp, [
                    $order->orderId,
                    $order->createdAt,
                    $order->status,
                    $order->buyer,
                    $item['sku'],
                    $item['name'],
                    $item['qty'],
                    $item['order_price'] ?? '',
                    $item['c1_price'] ?? '',
                    self::triState($item['price_ok']),
                    $item['stock_total'] ?? '',
                    $item['stock_left'] ?? '',
                    self::triState($item['stock_ok']),
                    implode(' | ', $item['issues']) . ($orderIssues ? ' | ORDER: ' . $orderIssues : ''),
                ], ';');
            }
        }
        fclose($fp);
    }

    public function printConsole(): void
    {
        $s = $this->result['summary'];
        echo "=== Сводка ===\n";
        echo sprintf("Всего заказов:        %d\n", $s['total']);
        echo sprintf("С ошибками:           %d\n", $s['with_errors']);
        echo sprintf("Отменено:             %d\n", $s['cancelled']);
        echo sprintf("Дублей заказов:       %d\n", $s['duplicates']);
        echo sprintf("Пустых заказов:       %d\n", $s['empty_orders']);
        echo sprintf("SKU нет в 1С:        %d\n", $s['sku_not_found']);
        echo sprintf("Разница в цене:       %d\n", $s['price_mismatch']);
        echo sprintf("Нехватка остатка:     %d\n", $s['stock_short']);
        echo sprintf("Дубль позиции в заказе: %d\n", $s['duplicate_item']);
        echo sprintf("Несовпадение суммы:   %d\n", $s['total_mismatch']);
        echo sprintf("Заказы без total:     %d\n", $s['null_total']);
        echo "\n";

        foreach ($this->result['orders'] as $order) {
            $marker = $order->cancelled    ? '[ОТМ]' :
                     ($order->isDuplicate ? '[ДУБ]' :
                     ($order->empty        ? '[ПУСТ]' :
                     ($order->hasErrors()  ? '[ERR]' : '[OK ]')));
            echo sprintf("%s %s  %s  %s  status=%s\n",
                $marker, $order->orderId, $order->createdAt, $order->buyer, $order->status);

            foreach ($order->items as $item) {
                $flag = count($item['issues']) > 0 ? '!' : ' ';
                echo sprintf("  %s %-8s ×%-3d  цена:%s/%s  ост:%s/%s  %s\n",
                    $flag,
                    $item['sku'],
                    $item['qty'],
                    $item['order_price'] ?? '-',
                    $item['c1_price'] ?? '-',
                    $item['stock_total'] ?? '-',
                    $item['stock_left'] ?? '-',
                    implode('; ', $item['issues'])
                );
            }
            foreach ($order->issues as $issue) {
                echo sprintf("    - [%s] %s\n", $issue['level'], $issue['msg']);
            }
            echo "\n";
        }
    }

    private static function triState(?bool $v): string
    {
        return $v === true ? 'OK' : ($v === false ? 'FAIL' : '-');
    }

    private function renderHtml(): string
    {
        $s = $this->result['summary'];
        $generatedAt = date('Y-m-d H:i:s');
        ob_start();
        echo <<<HTML
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Сверка заказов маркетплейса и 1С</title>
<style>
  body { font-family: -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; color: #1a1a1a; }
  h1 { font-size: 20px; margin: 0 0 16px; }
  .summary { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; margin-bottom: 24px; }
  .summary div { background: #f5f6f8; padding: 8px 12px; border-radius: 4px; font-size: 14px; }
  .summary b { display: block; font-size: 22px; color: #2b4a8b; }
  table { border-collapse: collapse; width: 100%; font-size: 13px; }
  th, td { padding: 6px 8px; border: 1px solid #d8dde3; vertical-align: top; }
  th { background: #eef1f5; text-align: left; }
  tr.order-row td { background: #fafbfc; font-weight: 600; }
  .ok    { color: #1a7a3a; }
  .fail  { color: #c83232; font-weight: 700; }
  .warn  { color: #b07b00; }
  .muted { color: #6b7280; }
  .tag   { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 11px; color: #fff; }
  .tag-err   { background: #c83232; }
  .tag-warn  { background: #b07b00; }
  .tag-info  { background: #6b7280; }
  .tag-ok    { background: #1a7a3a; }
  .tag-cancel{ background: #999; }
  .tag-dup   { background: #8e44ad; }
</style>
</head>
<body>
<h1>Сверка заказов маркетплейса и номенклатуры 1С</h1>
<p class="muted">Сгенерировано: {$generatedAt}</p>
<div class="summary">
HTML;
        $cards = [
            'total'          => 'Всего заказов',
            'with_errors'    => 'С ошибками',
            'cancelled'      => 'Отменено',
            'duplicates'     => 'Дублей заказов',
            'empty_orders'   => 'Пустых',
            'sku_not_found'   => 'SKU нет в 1С',
            'price_mismatch'  => 'Разница в цене',
            'stock_short'     => 'Нехватка остатка',
            'duplicate_item'  => 'Дубль позиции',
            'total_mismatch'  => 'Несовпадение суммы',
            'null_total'      => 'Без total',
        ];
        foreach ($cards as $key => $label) {
            $val = $s[$key] ?? 0;
            echo "<div><span>{$label}</span><b>{$val}</b></div>";
        }
        echo "</div>";

        echo "<table>";
        echo "<thead><tr><th>Заказ</th><th>Дата</th><th>Статус</th><th>Покупатель</th><th>SKU</th><th>Наименование</th><th>Кол-во</th><th>Цена (заказ/1С)</th><th>Остаток (всего/доступно)</th><th>Замечания</th></tr></thead>";
        echo "<tbody>";

        foreach ($this->result['orders'] as $order) {
            $badges = '';
            if ($order->cancelled)    { $badges .= ' <span class="tag tag-cancel">отменён</span>'; }
            if ($order->isDuplicate)  { $badges .= ' <span class="tag tag-dup">дубль</span>'; }
            if ($order->empty)        { $badges .= ' <span class="tag tag-warn">пустой</span>'; }
            if (!$order->hasErrors() && !$order->cancelled && !$order->empty && !$order->isDuplicate) {
                $badges .= ' <span class="tag tag-ok">OK</span>';
            } elseif ($order->hasErrors()) {
                $badges .= ' <span class="tag tag-err">ошибки</span>';
            }
            $rowspan = max(1, count($order->items));
            $firstLine = sprintf(
                '<td rowspan="%d"><b>%s</b>%s</td><td rowspan="%d">%s</td><td rowspan="%d">%s</td><td rowspan="%d">%s</td>',
                $rowspan, htmlspecialchars($order->orderId), $badges,
                $rowspan, htmlspecialchars($order->createdAt),
                $rowspan, htmlspecialchars($order->status),
                $rowspan, htmlspecialchars($order->buyer)
            );

            if (count($order->items) === 0) {
                echo "<tr class=\"order-row\">{$firstLine}<td colspan=\"6\" class=\"muted\">позиций нет</td></tr>";
                $this->renderOrderIssuesHtml($order);
                continue;
            }
            $first = true;
            foreach ($order->items as $item) {
                echo $first ? "<tr class=\"order-row\">{$firstLine}" : '<tr>';
                $first = false;

                $priceCell = '';
                if ($item['order_price'] !== null && $item['c1_price'] !== null) {
                    $cls = $item['price_ok'] === true ? 'ok' : ($item['price_ok'] === false ? 'fail' : 'muted');
                    $priceCell = sprintf('<span class="%s">%.2f / %.2f</span>',
                        $cls, $item['order_price'], $item['c1_price']);
                } elseif ($item['c1_price'] !== null) {
                    $priceCell = sprintf('<span class="warn">нет / %.2f</span>', $item['c1_price']);
                } else {
                    $priceCell = '<span class="muted">—</span>';
                }

                $stockCell = '';
                if ($item['stock_total'] !== null) {
                    $cls = $item['stock_ok'] === true ? 'ok' : ($item['stock_ok'] === false ? 'fail' : 'muted');
                    $stockCell = sprintf('<span class="%s">%d / %d</span>',
                        $cls, $item['stock_total'], $item['stock_left'] ?? $item['stock_total']);
                } else {
                    $stockCell = '<span class="muted">—</span>';
                }

                $itemIssues = $item['issues'] ? '<ul><li>' . implode('</li><li>', array_map('htmlspecialchars', $item['issues'])) . '</li></ul>' : '<span class="ok">OK</span>';

                echo sprintf('<td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    htmlspecialchars($item['sku']),
                    htmlspecialchars($item['name']),
                    $item['qty'],
                    $priceCell,
                    $stockCell,
                    $itemIssues
                );
            }
            $this->renderOrderIssuesHtml($order);
        }
        echo "</tbody></table>";
        echo "</body></html>";
        return ob_get_clean();
    }

    private function renderOrderIssuesHtml(OrderReport $order): void
    {
        if (count($order->issues) === 0) {
            return;
        }
        echo '<tr><td colspan="10" style="background:#fff8e8;padding:6px 12px;">';
        foreach ($order->issues as $issue) {
            $cls = $issue['level'] === 'error' ? 'tag-err' : ($issue['level'] === 'warn' ? 'tag-warn' : 'tag-info');
            echo sprintf('<span class="tag %s">%s</span> %s &nbsp; ',
                $cls, strtoupper($issue['level']), htmlspecialchars($issue['msg'])
            );
        }
        echo "</td></tr>";
    }
}
