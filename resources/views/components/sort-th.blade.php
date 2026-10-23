{{-- The header of a column that sorts its list by a click (App\Http\Livewire\Concerns\SortsColumns): the same click turns the direction, the third one gives the default order back --}}
@props(['column', 'sort' => '', 'dir' => ''])
@php($on = $sort === $column)
<th aria-sort="{{ $on ? ($dir === 'desc' ? 'descending' : 'ascending') : 'none' }}">
    <button type="button" wire:click="sortBy('{{ $column }}')" class="cmp-sort{{ $on ? ' on' : '' }}">{{ $slot }}<svg class="cmp-sort-mark" viewBox="0 0 8 8" aria-hidden="true"><path d="M4 1l3.2 5.4H.8z" fill="currentColor"/></svg></button>
</th>
