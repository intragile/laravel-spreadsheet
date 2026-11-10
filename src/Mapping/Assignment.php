<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Mapping;

/**
 * Optimal one-to-one assignment (Hungarian algorithm, O(n^3)) that maximizes the total score.
 */
final class Assignment
{
    /**
     * @param  array<int|string, array<int|string, int|float>>  $scores  Non-negative scores, `$scores[row][column]`;
     *                                                                   a missing cell counts as 0 and the matrix may be rectangular.
     * @return array<int|string, int|string> Row key => column key. Only pairs with a positive score are
     *                                       returned; rows left without one are omitted.
     */
    public static function maximize(array $scores): array
    {
        $rows = array_keys($scores);
        $columns = [];

        foreach ($scores as $row) {
            foreach (array_keys($row) as $column) {
                $columns[$column] = true;
            }
        }

        $columns = array_keys($columns);
        $rowCount = count($rows);
        $columnCount = count($columns);

        if ($rowCount === 0 || $columnCount === 0) {
            return [];
        }

        $size = max($rowCount, $columnCount);
        $highest = 0.0;

        foreach ($scores as $row) {
            foreach ($row as $score) {
                $highest = max($highest, (float) $score);
            }
        }

        // Minimizing (highest - score) maximizes the score; padding cells score 0.
        $cost = [];

        foreach ($rows as $i => $rowKey) {
            for ($j = 0; $j < $size; $j++) {
                $columnKey = $columns[$j] ?? null;
                $cost[$i + 1][$j + 1] = $highest - (float) ($columnKey === null ? 0 : ($scores[$rowKey][$columnKey] ?? 0));
            }
        }

        for ($i = $rowCount + 1; $i <= $size; $i++) {
            for ($j = 1; $j <= $size; $j++) {
                $cost[$i][$j] = $highest;
            }
        }

        $owner = self::solve($cost, $size);
        $result = [];

        foreach ($rows as $i => $rowKey) {
            foreach ($owner as $j => $row) {
                if ($row === $i + 1 && $j <= $columnCount && (float) ($scores[$rowKey][$columns[$j - 1]] ?? 0) > 0.0) {
                    $result[$rowKey] = $columns[$j - 1];
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array<int, float>>  $cost  1-indexed square matrix
     * @return array<int, int> Column (1-indexed) => owning row (1-indexed)
     */
    private static function solve(array $cost, int $size): array
    {
        $rowPotential = array_fill(0, $size + 1, 0.0);
        $columnPotential = array_fill(0, $size + 1, 0.0);
        $owner = array_fill(0, $size + 1, 0);
        $way = array_fill(0, $size + 1, 0);

        for ($i = 1; $i <= $size; $i++) {
            $owner[0] = $i;
            $j0 = 0;
            $minValue = array_fill(0, $size + 1, INF);
            $used = array_fill(0, $size + 1, false);

            do {
                $used[$j0] = true;
                $i0 = $owner[$j0];
                $delta = INF;
                $j1 = 0;

                for ($j = 1; $j <= $size; $j++) {
                    if ($used[$j]) {
                        continue;
                    }

                    $current = $cost[$i0][$j] - $rowPotential[$i0] - $columnPotential[$j];

                    if ($current < $minValue[$j]) {
                        $minValue[$j] = $current;
                        $way[$j] = $j0;
                    }

                    if ($minValue[$j] < $delta) {
                        $delta = $minValue[$j];
                        $j1 = $j;
                    }
                }

                for ($j = 0; $j <= $size; $j++) {
                    if ($used[$j]) {
                        $rowPotential[$owner[$j]] += $delta;
                        $columnPotential[$j] -= $delta;
                    } else {
                        $minValue[$j] -= $delta;
                    }
                }

                $j0 = $j1;
            } while ($owner[$j0] !== 0);

            do {
                $j1 = $way[$j0];
                $owner[$j0] = $owner[$j1];
                $j0 = $j1;
            } while ($j0 !== 0);
        }

        unset($owner[0]);

        return $owner;
    }
}
