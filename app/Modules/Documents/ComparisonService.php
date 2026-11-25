<?php
declare(strict_types=1);
namespace Caleidos\Modules\Documents;
final class ComparisonService
{
    public function compare(string $before, string $after): array {
        $old = $before === '' ? [] : explode("\n", str_replace(["\r\n", "\r"], "\n", $before));
        $new = $after === '' ? [] : explode("\n", str_replace(["\r\n", "\r"], "\n", $after));
        $n = count($old); $m = count($new); $matrix = [];
        // LCS conserva orden y repeticiones; se limita el trabajo para textos extensos.
        if ($n * $m <= 250000) {
            $matrix[$n] = array_fill(0, $m + 1, 0);
            for ($i = $n - 1; $i >= 0; $i--) {
                $matrix[$i] = array_fill(0, $m + 1, 0);
                for ($j = $m - 1; $j >= 0; $j--) $matrix[$i][$j] = $old[$i] === $new[$j] ? 1 + $matrix[$i + 1][$j + 1] : max($matrix[$i + 1][$j], $matrix[$i][$j + 1]);
            }
        }
        $left = []; $right = []; $added = 0; $removed = 0; $i = 0; $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $old[$i] === $new[$j]) {
                $left[] = ['text' => $old[$i++], 'type' => 'same']; $right[] = ['text' => $new[$j++], 'type' => 'same'];
            } elseif ($i < $n && ($j >= $m || !$matrix || $matrix[$i + 1][$j] >= $matrix[$i][$j + 1])) {
                $left[] = ['text' => $old[$i++], 'type' => 'removed']; $right[] = ['text' => '', 'type' => 'gap']; $removed++;
            } else {
                $left[] = ['text' => '', 'type' => 'gap']; $right[] = ['text' => $new[$j++], 'type' => 'added']; $added++;
            }
        }
        return ['before' => $left, 'after' => $right, 'added' => $added, 'removed' => $removed, 'simplified' => !$matrix && $n * $m > 250000];
    }
}
