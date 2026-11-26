<?php

namespace App\CalibrationDueNotifications;

use App\Authorization\AuthenticatedUserProfile;
use App\Authorization\CalibrationNotificationRecipientResolver;
use App\Models\CalibrationNotification;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

final class CalibrationNotificationReadModel
{
    public function __construct(
        private CalibrationNotificationPersistence $persistence,
        private CalibrationNotificationNavigationResolver $navigation,
        private AuthenticatedUserProfile $profile,
        private CalibrationNotificationRecipientResolver $recipients,
        private CalibrationNotificationConnection $notificationConnection,
    ) {}

    public function snapshotForAuthenticatedUser(?Connection $connection = null): CalibrationNotificationReadModelData
    {
        return $this->snapshotFor($this->authenticatedUser(), $connection);
    }

    public function snapshotFor(User $user, ?Connection $connection = null): CalibrationNotificationReadModelData
    {
        $connection = $this->notificationConnection->resolve($connection);
        $this->assertCurrentUser($user, $connection);
        $profile = $this->profile->resolve($user, $connection);
        $notifications = $this->persistence->latestActiveFor($user, connection: $connection);

        return new CalibrationNotificationReadModelData(
            recipientName: $profile['full_name'],
            recipientInitials: $profile['initials'],
            recipientRole: $profile['role'],
            unreadCount: $this->persistence->unreadCountFor($user, $connection),
            recentNotifications: $this->items($notifications, $user, $connection),
        );
    }

    public function markAsReadForAuthenticatedUser(int $notificationId, ?Connection $connection = null): bool
    {
        $connection = $this->notificationConnection->resolve($connection);

        return $this->persistence->markAsRead(
            $this->authenticatedUser(),
            $notificationId,
            $connection,
        );
    }

    public function markAllActiveAsReadForAuthenticatedUser(?Connection $connection = null): int
    {
        $connection = $this->notificationConnection->resolve($connection);

        return $this->persistence->markAllActiveAsRead($this->authenticatedUser(), $connection);
    }

    public function openNotificationForAuthenticatedUser(
        int $notificationId,
        ?Connection $connection = null,
    ): ?string {
        $connection = $this->notificationConnection->resolve($connection);
        $user = $this->authenticatedUser();
        $this->assertCurrentUser($user, $connection);
        $notification = (new CalibrationNotification)->setConnection($connection->getName())->newQuery()
            ->whereKey($notificationId)
            ->where('recipient_user_id', $user->getKey())
            ->active()
            ->first();

        if (! $notification instanceof CalibrationNotification) {
            return null;
        }

        $this->persistence->markAsRead($user, $notificationId, $connection);

        return $this->navigation->resolve($notification, $user, $connection);
    }

    private function authenticatedUser(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthenticationException('An authenticated user is required.');
        }

        return $user;
    }

    private function assertCurrentUser(User $user, Connection $connection): void
    {
        if ((int) Auth::id() !== (int) $user->getKey()
            || ! $this->recipients->isEligible($user, $connection)) {
            throw new AuthorizationException('Notification ownership does not match the authenticated user.');
        }
    }

    /** @return list<CalibrationNotificationItem> */
    private function items(Collection $notifications, User $user, Connection $connection): array
    {
        return $notifications
            ->map(fn (CalibrationNotification $notification): CalibrationNotificationItem => new CalibrationNotificationItem(
                notificationId: (int) $notification->getKey(),
                eventType: $notification->event_type,
                isRead: $notification->read_at !== null,
                instrumentName: $notification->instrument_name_snapshot,
                instrumentType: $notification->instrument_type_snapshot,
                dueDate: $notification->due_date_snapshot,
                createdAt: $notification->created_at,
                targetAvailable: ($targetUrl = $this->navigation->resolve($notification, $user, $connection)) !== null,
                targetUrl: $targetUrl,
            ))
            ->values()
            ->all();
    }
}
