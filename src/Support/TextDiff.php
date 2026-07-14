<?php

namespace Community\EggBrowser\Support;

/**
 * Minimal unified/inline line diff (no external deps).
 *
 * Returns rows of ['tag' => 'equal'|'remove'|'add', 'text' => string].
 */
class TextDiff
{
    /**
     * @return list<array{tag: string, text: string}>
     */
    public function unifiedLines(string $left, string $right): array
    {
        $a = preg_split("/\r\n|\n|\r/", $left) ?: [''];
        $b = preg_split("/\r\n|\n|\r/", $right) ?: [''];

        // Drop a single trailing empty line from explode-style splits.
        if ($a !== [] && end($a) === '') {
            array_pop($a);
        }
        if ($b !== [] && end($b) === '') {
            array_pop($b);
        }

        $n = count($a);
        $m = count($b);

        // LCS lengths
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                if ($a[$i] === $b[$j]) {
                    $dp[$i][$j] = $dp[$i + 1][$j + 1] + 1;
                } else {
                    $dp[$i][$j] = max($dp[$i + 1][$j], $dp[$i][$j + 1]);
                }
            }
        }

        $rows = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $rows[] = ['tag' => 'equal', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $rows[] = ['tag' => 'remove', 'text' => $a[$i]];
                $i++;
            } else {
                $rows[] = ['tag' => 'add', 'text' => $b[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $rows[] = ['tag' => 'remove', 'text' => $a[$i]];
            $i++;
        }
        while ($j < $m) {
            $rows[] = ['tag' => 'add', 'text' => $b[$j]];
            $j++;
        }

        return $rows;
    }

    /**
     * Compact unified string with -, +, and space prefixes.
     */
    public function unifiedText(string $left, string $right): string
    {
        $lines = [];
        foreach ($this->unifiedLines($left, $right) as $row) {
            $prefix = match ($row['tag']) {
                'remove' => '- ',
                'add' => '+ ',
                default => '  ',
            };
            $lines[] = $prefix . $row['text'];
        }

        return implode("\n", $lines);
    }
}
