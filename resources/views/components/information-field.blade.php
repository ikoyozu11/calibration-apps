@props(['label', 'value', 'valueClass' => ''])

<div class="rounded-xl border border-white/70 bg-[#edf4fa] px-4 py-3 shadow-[inset_0_1px_0_rgb(255_255_255/0.8)]">
    <dt class="text-xs font-medium text-muted">{{ $label }}</dt>
    <dd class="mt-1 text-sm font-semibold text-navy {{ $valueClass }}">{{ $value }}</dd>
</div>
