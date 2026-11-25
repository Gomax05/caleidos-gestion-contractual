<?php
declare(strict_types=1);
namespace Caleidos\Core;
final class CsvResponse
{
    public static function send(array $rows, array $keys, string $name): void {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="caleidos-' . $name . '-' . date('Y-m-d') . '.csv"');
        $stream = fopen('php://output', 'wb'); fwrite($stream, "\xEF\xBB\xBF"); fputcsv($stream, $keys);
        foreach ($rows as $row) fputcsv($stream, array_map(static function ($key) use ($row) { $value = (string) ($row[$key] ?? ''); return preg_match('/^[=+@\-\t\r]/', $value) ? "'" . $value : $value; }, $keys));
        fclose($stream);
    }
}
