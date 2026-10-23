<?php

namespace Tests\Unit;

use App\Support\ColumnSort;
use PHPUnit\Framework\TestCase;

/**
 * Sorting by a click on the header of a column: the clicks, the order, rows with no value, ties.
 */
class ColumnSortTest extends TestCase
{
    protected function columns(): array
    {
        return [
            'port' => ['first' => 'asc', 'value' => fn ($row) => $row['port'] ?? null],
            'time' => ['first' => 'desc', 'value' => fn ($row) => $row['time'] ?? null],
        ];
    }

    protected function ids($rows): array
    {
        return array_column($rows->all(), 'id');
    }

    protected function rows()
    {
        return collect([
            ['id' => 1, 'port' => 'Gi0/10', 'time' => 300],
            ['id' => 2, 'port' => 'Gi0/2', 'time' => 100],
            ['id' => 3, 'port' => null, 'time' => 200],
            ['id' => 4, 'port' => 'Gi0/3', 'time' => null],
        ]);
    }

    protected function byId(): \Closure
    {
        return fn ($a, $b) => $a['id'] <=> $b['id'];
    }

    public function test_the_clicks_go_first_direction_then_the_other_then_the_default_order()
    {
        $c = $this->columns();
        $this->assertSame(['port', 'asc'], ColumnSort::next($c, '', '', 'port'));
        $this->assertSame(['port', 'desc'], ColumnSort::next($c, 'port', 'asc', 'port'));
        $this->assertSame(['', ''], ColumnSort::next($c, 'port', 'desc', 'port'));

        $this->assertSame(['time', 'desc'], ColumnSort::next($c, '', '', 'time'), 'a time starts with the latest');
        $this->assertSame(['time', 'asc'], ColumnSort::next($c, 'time', 'desc', 'time'));
        $this->assertSame(['', ''], ColumnSort::next($c, 'time', 'asc', 'time'));

        $this->assertSame(['time', 'desc'], ColumnSort::next($c, 'port', 'desc', 'time'), 'another column starts again');
    }

    public function test_an_unknown_column_changes_nothing()
    {
        $this->assertSame(['port', 'desc'], ColumnSort::next($this->columns(), 'port', 'desc', 'nope'));
        $this->assertSame(['', ''], ColumnSort::next($this->columns(), '', '', 'nope'));
    }

    public function test_text_sorts_in_natural_order_and_rows_with_no_value_are_last_in_both_directions()
    {
        $this->assertSame([2, 4, 1, 3], $this->ids(ColumnSort::apply($this->rows(), $this->columns(), 'port', 'asc', $this->byId())));
        $this->assertSame([1, 4, 2, 3], $this->ids(ColumnSort::apply($this->rows(), $this->columns(), 'port', 'desc', $this->byId())));
    }

    public function test_numbers_sort_as_numbers()
    {
        $this->assertSame([1, 3, 2, 4], $this->ids(ColumnSort::apply($this->rows(), $this->columns(), 'time', 'desc', $this->byId())));
        $this->assertSame([2, 3, 1, 4], $this->ids(ColumnSort::apply($this->rows(), $this->columns(), 'time', 'asc', $this->byId())));
        $rows = collect([['id' => 1, 'time' => 9], ['id' => 2, 'time' => 10], ['id' => 3, 'time' => 100]]);
        $this->assertSame([1, 2, 3], $this->ids(ColumnSort::apply($rows, $this->columns(), 'time', 'asc', $this->byId())), '9 before 10, not after 100');
    }

    public function test_rows_that_tie_keep_the_default_order_in_either_direction()
    {
        $rows = collect([['id' => 3, 'port' => 'A'], ['id' => 1, 'port' => 'A'], ['id' => 2, 'port' => 'A']]);
        $this->assertSame([1, 2, 3], $this->ids(ColumnSort::apply($rows, $this->columns(), 'port', 'asc', $this->byId())));
        $this->assertSame([1, 2, 3], $this->ids(ColumnSort::apply($rows, $this->columns(), 'port', 'desc', $this->byId())));
    }

    public function test_no_column_or_an_unknown_one_gives_the_default_order()
    {
        $this->assertSame([1, 2, 3, 4], $this->ids(ColumnSort::apply($this->rows(), $this->columns(), '', '', $this->byId())));
        $this->assertSame([1, 2, 3, 4], $this->ids(ColumnSort::apply($this->rows(), $this->columns(), 'nope', 'desc', $this->byId())));
    }

    public function test_a_direction_that_is_neither_asc_nor_desc_is_ascending()
    {
        $this->assertSame([2, 4, 1, 3], $this->ids(ColumnSort::apply($this->rows(), $this->columns(), 'port', 'sideways', $this->byId())));
    }
}
