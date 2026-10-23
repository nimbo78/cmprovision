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
        [$sort, $dir] = $this->sorting();
        [$this->sort, $this->dir] = ColumnSort::next($this->columns(), $sort, $dir, $key);
    }

    /* The chosen column if there is such a column, and the direction (the first one of the column when the address does not say
       asc or desc: /cms?sort=finished is the latest first): for the headers of the view and for the sorting */
    protected function sorting(): array
    {
        $columns = $this->columns();
        $sort = is_string($this->sort) && isset($columns[$this->sort]) ? $this->sort : '';
        if ($sort === '')
            return ['', ''];
        return [$sort, in_array($this->dir, ['asc', 'desc'], true) ? $this->dir : ($columns[$sort]['first'] ?? 'asc')];
    }
}
