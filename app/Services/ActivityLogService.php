<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogService
{
    public static function log(
        string $action,
        string $module,
        ?string $description = null,
        ?int $userId = null,
        ?int $projectId = null,
    ): void {
        $request = request();

        ActivityLog::create([
            'user_id' => $userId ?? auth()->id(),
            'project_id' => $projectId,
            'action' => $action,
            'module' => $module,
            'description' => $description,
            'ip_address' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? $request->userAgent() : null,
        ]);
    }
}
