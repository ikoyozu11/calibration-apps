<div
    x-data="{ open: false }"
    x-on:keydown.escape.window="if (open) { open = false; $refs.notificationTrigger.focus(); }"
    x-on:click.outside="open = false"
    x-on:instruments-desk-profile-open.window="open = false"
    @class([
        'app-header-notifications relative shrink-0',
        'app-mobile-notifications' => $mobile,
    ])
>
    <button
        type="button"
        x-ref="notificationTrigger"
        x-on:click="open = !open; if (open) { window.dispatchEvent(new CustomEvent('instruments-desk-notifications-open')); }"
        wire:click="refreshNotifications"
        class="app-header-notifications-trigger"
        aria-label="Notifications{{ $unreadCount > 0 ? ', '.$unreadCount.' unread' : '' }}"
        aria-controls="global-notification-menu"
        :aria-expanded="open ? 'true' : 'false'"
        aria-haspopup="menu"
    >
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
        </svg>
        @if ($unreadCount > 0)
            <span class="app-header-notifications-badge" aria-hidden="true">{{ $unreadCount > 99 ? '99+' : $unreadCount }}</span>
        @endif
    </button>

    <div
        id="global-notification-menu"
        x-cloak
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="translate-y-1 opacity-0"
        x-transition:enter-end="translate-y-0 opacity-100"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="translate-y-0 opacity-100"
        x-transition:leave-end="translate-y-1 opacity-0"
        class="app-header-notifications-menu"
        role="menu"
        aria-label="Calibration notifications"
    >
        <div class="app-header-notifications-heading">
            <div>
                <p class="text-sm font-semibold text-navy">Notifications</p>
                <p class="mt-0.5 text-xs text-muted">Calibration alerts</p>
            </div>
            @if ($unreadCount > 0)
                <span class="inline-flex shrink-0 items-center rounded-full bg-brand/10 px-2 py-1 text-[11px] font-semibold text-brand">
                    {{ $unreadCount }} unread
                </span>
            @endif
        </div>

        @if ($unreadCount > 0)
            <div class="flex justify-end border-b border-line px-4 py-2">
                <button
                    type="button"
                    wire:click="markAllAsRead"
                    class="text-xs font-semibold text-brand hover:text-navy focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand"
                >
                    Mark all as read
                </button>
            </div>
        @endif

        @if ($notifications === [])
            <div class="px-5 py-8 text-center">
                <p class="text-sm font-semibold text-navy">All caught up</p>
                <p class="mt-1 text-xs text-muted">No active calibration notifications.</p>
            </div>
        @else
            <div class="grid max-h-[min(28rem,calc(100vh-9rem))] divide-y divide-line overflow-y-auto py-1">
                @foreach ($notifications as $notification)
                    <button
                        type="button"
                        wire:click="openNotification({{ $notification['notificationId'] }})"
                        @class([
                            'app-header-notification-item group',
                            'app-header-notification-item-unread' => ! $notification['isRead'],
                            'cursor-pointer' => $notification['targetAvailable'],
                            'cursor-default' => ! $notification['targetAvailable'],
                        ])
                        role="menuitem"
                        @if (! $notification['targetAvailable']) disabled @endif
                        @if (! $notification['targetAvailable']) aria-disabled="true" @endif
                    >
                        <span @class([
                            'app-header-notification-dot bg-brand',
                            'opacity-0' => $notification['isRead'],
                        ]) aria-hidden="true"></span>
                        <span class="min-w-0 flex-1 text-left">
                            <span class="flex items-start justify-between gap-2">
                                <span @class([
                                    'inline-flex rounded-full px-2 py-1 text-[10px] font-bold uppercase tracking-[0.08em]',
                                    'bg-overdue/10 text-overdue' => $notification['eventType'] === 'overdue',
                                    'bg-due-soon/15 text-due-soon' => $notification['eventType'] === 'due_soon',
                                ])>
                                    {{ $notification['eventType'] === 'overdue' ? 'Overdue' : 'Due Soon' }}
                                </span>
                                <span class="shrink-0 pt-1 text-[11px] text-muted">{{ \Illuminate\Support\Carbon::parse($notification['createdAt'])->diffForHumans() }}</span>
                            </span>
                            <span @class([
                                'mt-2 block break-words text-sm text-navy',
                                'font-semibold' => ! $notification['isRead'],
                                'font-medium' => $notification['isRead'],
                            ])>{{ $notification['instrumentName'] }}</span>
                            <span class="mt-0.5 block break-words text-xs text-muted">{{ $notification['instrumentType'] ?: 'Manufacturer unavailable' }}</span>
                            <span class="mt-2 inline-flex items-center gap-1.5 text-xs text-muted">
                                <svg class="size-3.5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M7 3v3m10-3v3M4.5 9.5h15M6 5h12a2 2 0 0 1 2 2v11.5a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" />
                                </svg>
                                <span>Due {{ \Illuminate\Support\Carbon::parse($notification['dueDate'])->format('d M Y') }}</span>
                            </span>
                            @if (! $notification['targetAvailable'])
                                <span class="mt-1 block text-[11px] text-muted">Target unavailable</span>
                            @endif
                        </span>
                        @if ($notification['targetAvailable'])
                            <svg class="mt-1 size-4 shrink-0 text-muted transition-colors duration-150 group-hover:text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9 18 6-6-6-6" />
                            </svg>
                        @endif
                    </button>
                @endforeach
            </div>
        @endif
    </div>
</div>
