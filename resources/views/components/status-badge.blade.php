@props(['status'])

@php
    $tone = match ($status) {
        'On Track' => 'bg-emerald-50 text-on-track',
        'Due Soon' => 'bg-amber-50 text-due-soon',
        'Overdue' => 'bg-red-50 text-overdue',
        default => 'bg-slate-100 text-not-calibrated',
    };
@endphp

<span {{ $attributes->class("inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium {$tone}") }}>
    <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
    {{ $status }}
</span>
