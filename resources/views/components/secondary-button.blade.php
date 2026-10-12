<button {{ $attributes->merge(['type' => 'button', 'class' => 'cmp-btn cmp-btn-secondary']) }}>
    {{ $slot }}
</button>
