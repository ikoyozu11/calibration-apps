<?php

namespace App\Livewire;

use App\CalibrationDueNotifications\CalibrationNotificationReadModel;
use Illuminate\Contracts\View\View;
use Livewire\Component;

final class CalibrationNotificationCenter extends Component
{
    public bool $mobile = false;

    public int $unreadCount = 0;

    /** @var list<array{notificationId:int,eventType:string,isRead:bool,instrumentName:string,instrumentType:string,dueDate:string,createdAt:string,targetAvailable:bool,targetUrl:?string}> */
    public array $notifications = [];

    public function mount(bool $mobile = false): void
    {
        $this->mobile = $mobile;

        if ($this->enabled()) {
            $this->refreshNotifications();
        }
    }

    public function refreshNotifications(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $snapshot = app(CalibrationNotificationReadModel::class)->snapshotForAuthenticatedUser();
        $this->unreadCount = $snapshot->unreadCount;
        $this->notifications = collect($snapshot->recentNotifications)
            ->map(static fn ($item): array => [
                'notificationId' => $item->notificationId,
                'eventType' => $item->eventType,
                'isRead' => $item->isRead,
                'instrumentName' => $item->instrumentName,
                'instrumentType' => $item->instrumentType,
                'dueDate' => $item->dueDate->toDateString(),
                'createdAt' => $item->createdAt->toIso8601String(),
                'targetAvailable' => $item->targetAvailable,
                'targetUrl' => $item->targetUrl,
            ])
            ->values()
            ->all();
    }

    public function markAllAsRead(): void
    {
        if (! $this->enabled()) {
            return;
        }

        app(CalibrationNotificationReadModel::class)->markAllActiveAsReadForAuthenticatedUser();
        $this->refreshNotifications();
    }

    public function openNotification(int $notificationId): void
    {
        if (! $this->enabled()) {
            return;
        }

        $targetUrl = app(CalibrationNotificationReadModel::class)
            ->openNotificationForAuthenticatedUser($notificationId);

        if ($targetUrl !== null) {
            $this->redirect($targetUrl);
        }

        $this->refreshNotifications();
    }

    public function render(): View
    {
        return view('livewire.calibration-notification-center');
    }

    private function enabled(): bool
    {
        return (bool) config('calibration_notification_center.enabled', false);
    }
}
