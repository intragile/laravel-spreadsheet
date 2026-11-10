<?php

declare(strict_types=1);

namespace IntrAgile\LaravelSpreadsheet\Tests\Unit\Mapping;

use IntrAgile\LaravelSpreadsheet\Mapping\Assignment;
use PHPUnit\Framework\TestCase;

final class AssignmentTest extends TestCase
{
    public function test_square_matrix_maximizes_total(): void
    {
        $this->assertSame([0 => 1, 1 => 0], Assignment::maximize([[100, 95], [94, 0]]));
    }

    public function test_beats_greedy(): void
    {
        // A: f1 100, f2 95; B: f1 94 only. Greedy gives A f1 and leaves B unmapped.
        $this->assertSame([0 => 1, 1 => 0], Assignment::maximize([[100, 95], [94, 0]]));
        $this->assertSame([0 => 1, 1 => 0], Assignment::maximize([0 => [0 => 100, 1 => 95], 1 => [0 => 94]]));
    }

    public function test_more_rows_than_columns_leaves_the_weakest_row_out(): void
    {
        $result = Assignment::maximize([[10], [90], [50]]);

        $this->assertSame([1 => 0], $result);
    }

    public function test_more_columns_than_rows(): void
    {
        $this->assertSame([0 => 2, 1 => 0], Assignment::maximize([[1, 2, 9], [8, 3, 4]]));
    }

    public function test_empty_input(): void
    {
        $this->assertSame([], Assignment::maximize([]));
        $this->assertSame([], Assignment::maximize([[]]));
    }

    public function test_matches_brute_force_on_random_matrices(): void
    {
        mt_srand(7);

        for ($round = 0; $round < 40; $round++) {
            $n = mt_rand(1, 5);
            $m = mt_rand(1, 5);
            $scores = [];
            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $m; $j++) {
                    $scores[$i][$j] = (float) mt_rand(0, 100);
                }
            }

            $total = 0.0;
            foreach (Assignment::maximize($scores) as $row => $col) {
                $total += $scores[$row][$col];
            }

            $this->assertEqualsWithDelta($this->bruteForce($scores, 0, []), $total, 1e-9);
        }
    }

    /**
     * @param  array<int, array<int, float>>  $scores
     * @param  list<int|string>  $used
     */
    private function bruteForce(array $scores, int $row, array $used): float
    {
        if ($row >= count($scores)) {
            return 0.0;
        }

        $best = $this->bruteForce($scores, $row + 1, $used);

        foreach (array_keys($scores[$row]) as $col) {
            if (! in_array($col, $used, true)) {
                $best = max($best, $scores[$row][$col] + $this->bruteForce($scores, $row + 1, [...$used, $col]));
            }
        }

        return $best;
    }
}
