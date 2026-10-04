<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'registration_no',
        'father_name',
        'program',
        'department',
        'session',
        'department_id',
        'program_id',
        'is_proposal_enrolled',
        'supervision_limit_proposal',
        'supervision_limit_phase_1',
        'supervision_limit_phase_2',
        'avatar',
        'password',
        'status',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_proposal_enrolled' => 'boolean',
        ];
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'student_id');
    }

    public function ledProject(): HasOne
    {
        return $this->project();
    }

    public function projectMemberships(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function pendingInvitations(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class, 'invitee_id')->where('status', 'pending');
    }

    public function memberProject(): HasOneThrough
    {
        return $this->hasOneThrough(
            Project::class,
            ProjectMember::class,
            'user_id',
            'id',
            'id',
            'project_id'
        )->where('project_members.status', 'active');
    }

    public function studentProject(): ?Project
    {
        if ($this->ledProject) {
            return $this->ledProject;
        }

        return $this->memberProject;
    }

    public function studentTeamRole(): ?string
    {
        if ($this->ledProject) {
            return 'leader';
        }

        if ($this->memberProject) {
            return 'member';
        }

        return null;
    }

    public function supervisedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'supervisor_id');
    }

    public function evaluatorAssignments(): HasMany
    {
        return $this->hasMany(ProjectEvaluator::class, 'evaluator_id');
    }

    public function raisedProjectQueries(): HasMany
    {
        return $this->hasMany(ProjectQuery::class, 'raised_by');
    }

    public function evaluatorReviews(): HasMany
    {
        return $this->hasMany(EvaluatorReview::class, 'evaluator_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function departmentRelation(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function programRelation(): BelongsTo
    {
        return $this->belongsTo(Program::class, 'program_id');
    }

    public function programMemberships(): HasMany
    {
        return $this->hasMany(ProgramMembership::class);
    }

    public function programAccessGrants(): HasMany
    {
        return $this->hasMany(ProgramAccessGrant::class, 'grantee_user_id');
    }

    public function primaryProgram(): ?Program
    {
        if ($this->relationLoaded('programRelation')) {
            return $this->programRelation;
        }

        return $this->programRelation()->first();
    }
}
