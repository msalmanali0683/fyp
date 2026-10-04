<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'registration_no' => $this->registration_no,
            'sap_id' => $this->registration_no,
            'father_name' => $this->father_name,
            'program' => $this->program,
            'department' => $this->department,
            'program_id' => $this->program_id,
            'department_id' => $this->department_id,
            'program_name' => $this->relationLoaded('programRelation')
                ? $this->programRelation?->name
                : $this->program,
            'department_name' => $this->relationLoaded('departmentRelation')
                ? $this->departmentRelation?->name
                : $this->department,
            'session' => $this->session,
            'is_proposal_enrolled' => $this->is_proposal_enrolled,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'roles' => $this->getRoleNames(),
            'direct_permissions' => $this->getDirectPermissions()->pluck('name'),
            'permissions' => $this->getAllPermissions()->pluck('name'),
            'is_faculty_member' => $this->hasRole('faculty'),
            'is_supervisor' => $this->hasRole('supervisor'),
            'is_evaluator' => $this->hasRole('evaluator'),
            'team_status' => $this->when(
                $this->relationLoaded('ledProject') || $this->relationLoaded('memberProject'),
                function () {
                    $project = $this->studentProject();
                    $role = $this->studentTeamRole();

                    return [
                        'in_team' => $project !== null,
                        'team_role' => $role,
                        'project_id' => $project?->id,
                        'project_title' => $project?->title,
                        'label' => match ($role) {
                            'leader' => 'In team (Leader)',
                            'member' => 'In team (Member)',
                            default => 'Not in team',
                        },
                    ];
                }
            ),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
