<button {{ $attributes->merge(['type' => 'submit', 'class' => 'cmp-btn']) }}>
    {{ $slot }}
</button>
