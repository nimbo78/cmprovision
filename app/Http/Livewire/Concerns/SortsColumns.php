<?php

namespace App\Http\Livewire\Concerns;

use App\Support\ColumnSort;
use Livewire\Attributes\Url;

/**
 * A list that is sorted by a click on the header of a column (ColumnSort). The choice is in the address (?sort=time&dir=asc),
 * so it stays through a reload and can be sent as a link; an unknown key there is the default order.
 */
trait SortsColumns
{
    #[Url(as: 'sort', except: '')]
    public $sort = '';

    #[Url(as: 'dir', except: '')]
    public $dir = '';

    /** ['key' => ['value' => fn ($row) => ..., 'first' => 'asc'|'desc']], see ColumnSort */
    abstract protected function columns(): array;

    public function sortBy($key)
    {
        if (!is_string($key))
            return;
        [$this->sort, $this->dir] = ColumnSort::next($this->columns(), is_string($this->sort) ? $this->sort : '', is_string($this->dir) ? $this->dir : '', $key);
    }

    /* The chosen column if there is such a column, and the direction: for the headers of the view */
    protected function sorting(): array
    {
        $sort = is_string($this->sort) && isset($this->columns()[$this->sort]) ? $this->sort : '';
        return [$sort, $sort !== '' && $this->dir === 'desc' ? 'desc' : ($sort !== '' ? 'asc' : '')];
    }
}
