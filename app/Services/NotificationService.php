<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    public static function send(
        User|int $user,
        string $title,
        string $message,
        string $type = 'info',
        ?array $meta = null,
        ?int $sentBy = null
    ): Notification {
        $userId = $user instanceof User ? $user->id : $user;

        return Notification::create([
            'user_id' => $userId,
            'sent_by' => $sentBy,
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'meta' => $meta,
            'is_read' => false,
        ]);
    }

    public static function sendToMany(array $userIds, string $title, string $message, string $type = 'info', ?array $meta = null, ?int $sentBy = null): void
    {
        foreach (array_unique($userIds) as $userId) {
            self::send($userId, $title, $message, $type, $meta, $sentBy);
        }
    }
}
