<?php

namespace App\Support;

class FypPhases
{
    public static function all(): array
    {
        return config('fyp.phases', []);
    }

    public static function slugs(): array
    {
        return config('fyp.phase_order', array_keys(self::all()));
    }

    public static function label(string $slug): string
    {
        return self::all()[$slug] ?? ucwords(str_replace('_', ' ', $slug));
    }

    public static function statuses(): array
    {
        return config('fyp.phase_statuses', []);
    }

    public static function statusSlugs(): array
    {
        return array_keys(self::statuses());
    }

    public static function statusLabel(string $slug): string
    {
        return self::statuses()[$slug] ?? ucwords(str_replace('_', ' ', $slug));
    }

    public static function nextPhase(?string $current): ?string
    {
        $order = self::slugs();
        $index = array_search($current, $order, true);

        if ($index === false || ! isset($order[$index + 1])) {
            return null;
        }

        return $order[$index + 1];
    }

    public static function previousPhase(string $phase): ?string
    {
        $order = self::slugs();
        $index = array_search($phase, $order, true);

        if ($index === false || $index === 0) {
            return null;
        }

        return $order[$index - 1];
    }

    public static function reviewRoles(): array
    {
        return config('fyp.phase_review_roles', []);
    }

    public static function deliverablePhases(): array
    {
        return ['phase_1', 'phase_2'];
    }

    public static function isDeliverablePhase(?string $phase): bool
    {
        return in_array($phase, self::deliverablePhases(), true);
    }
}
