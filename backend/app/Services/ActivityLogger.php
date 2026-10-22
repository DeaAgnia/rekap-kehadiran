<?php

namespace App\Services;

use App\Models\ActivityLog;
use Throwable;

class ActivityLogger
{
    public static function success(
        string $action,
        string $description,
        ?object $entity = null,
        array $metadata = []
    ): void {
        self::write('success', $action, $description, $entity, $metadata);
    }

    public static function error(
        string $action,
        string $description,
        ?object $entity = null,
        array $metadata = []
    ): void {
        self::write('error', $action, $description, $entity, $metadata);
    }

    private static function write(
        string $status,
        string $action,
        string $description,
        ?object $entity,
        array $metadata
    ): void {
        try {
            ActivityLog::create([
                'action' => $action,
                'entity_type' => $entity ? $entity::class : null,
                'entity_id' => $entity?->getKey(),
                'status' => $status,
                'description' => $description,
                'metadata' => $metadata ?: null,
                'actor' => 'admin.sdm',
                'ip_address' => request()?->ip(),
                'occurred_at' => now(),
            ]);
        } catch (Throwable) {
            // Audit logging must never hide the original result from the API caller.
        }
    }
}
