<button {{ $attributes->merge(['type' => 'button', 'class' => 'cmp-btn cmp-btn-danger']) }}>
    {{ $slot }}
</button>
