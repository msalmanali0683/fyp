<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PermissionResource;
use App\Support\FypPermissions;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $permissions = Permission::with('roles')->orderBy('name')->get();

        return $this->success([
            'permissions' => PermissionResource::collection($permissions),
            'groups' => FypPermissions::groups(),
        ]);
    }
}
