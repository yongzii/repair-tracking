<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public static function log(
        Request $request,
        ?User $user,
        string $action,
        string $module,
        ?Model $record = null,
        ?string $description = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        return AuditLog::create([
            'shop_id' => $user?->shop_id,
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_role' => $user?->role,
            'action' => $action,
            'module' => $module,
            'record_type' => $record ? class_basename($record) : null,
            'record_id' => $record?->getKey(),
            'description' => $description,
            'old_values' => self::normalizeValues($oldValues),
            'new_values' => self::normalizeValues($newValues),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private static function normalizeValues(?array $values): ?array
    {
        if (!$values) {
            return null;
        }

        return array_map(function ($value) {
            if ($value instanceof \DateTimeInterface) {
                return $value->format(\DateTimeInterface::ATOM);
            }

            if (is_array($value)) {
                return self::normalizeValues($value);
            }

            return $value;
        }, $values);
    }
}
