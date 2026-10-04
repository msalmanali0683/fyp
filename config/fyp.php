<?php

return [

    'roles' => [
        'student' => 'Student',
        'faculty' => 'Faculty',
        'supervisor' => 'Supervisor',
        'admin' => 'Admin',
        'evaluator' => 'Evaluator',
        'fyp-committee-member' => 'FYP Committee Member',
        'fyp-committee-head' => 'FYP Committee Head',
    ],

    'system_roles' => [
        'student',
        'faculty',
        'supervisor',
        'admin',
        'evaluator',
        'fyp-committee-member',
        'fyp-committee-head',
    ],

    'user_management_roles' => [
        'admin',
        'fyp-committee-head',
    ],

    'role_management_roles' => [
        'fyp-committee-head',
    ],

    'dashboard_admin_roles' => [
        'admin',
        'fyp-committee-head',
        'fyp-committee-member',
    ],

    'full_record_access_roles' => [
        'admin',
        'fyp-committee-head',
        'fyp-committee-member',
        'supervisor',
        'evaluator',
    ],

    'full_project_access_roles' => [
        'admin',
        'fyp-committee-head',
        'fyp-committee-member',
        'supervisor',
        'evaluator',
    ],

    'full_project_list_roles' => [
        'admin',
        'fyp-committee-head',
        'fyp-committee-member',
    ],

    'full_project_list_permissions' => [
        'view all projects',
    ],

    'phase_review_roles' => [
        'supervisor',
        'admin',
        'evaluator',
        'fyp-committee-member',
        'fyp-committee-head',
    ],

    'phases' => [
        'proposal' => 'Proposal',
        'phase_1' => 'Phase-1',
        'phase_2' => 'Phase-2',
    ],

    'phase_order' => [
        'proposal',
        'phase_1',
        'phase_2',
    ],

    'phase_statuses' => [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'under_review' => 'Under Review',
        'approved' => 'Approved',
        'revision_required' => 'Revision Required',
        'rejected' => 'Rejected',
    ],

    'project_statuses' => [
        'active' => 'Active',
        'completed' => 'Completed',
        'suspended' => 'Suspended',
    ],

    'project_workflow_stages' => [
        'draft' => 'Draft',
        'invitations_pending' => 'Invitations Pending',
        'group_confirmed' => 'Group Confirmed',
        'supervisor_pending' => 'Supervisor Pending',
        'supervisor_revision_pending' => 'Supervisor Revision Review',
        'supervisor_rejected' => 'Supervisor Rejected',
        'supervisor_accepted' => 'Supervisor Accepted',
        'committee_review' => 'Committee Review',
        'evaluators_assigned' => 'Evaluators Assigned',
        'evaluator_review' => 'Evaluator Review',
        'revision_required' => 'Revision Required',
        'committee_final' => 'Committee Final',
        'committee_head_approval' => 'Committee Head Approval',
        'approved' => 'Approved',
    ],

    'permission_groups' => [
        [
            'key' => 'dashboard',
            'label' => 'Dashboard',
            'permissions' => [
                'view dashboard',
            ],
        ],
        [
            'key' => 'user_management',
            'label' => 'User Management',
            'permissions' => [
                'view users',
                'create users',
                'edit users',
                'delete users',
                'assign user roles',
                'assign user permissions',
            ],
        ],
        [
            'key' => 'role_management',
            'label' => 'Role Management',
            'permissions' => [
                'view roles',
                'create roles',
                'edit roles',
                'delete roles',
            ],
        ],
        [
            'key' => 'student_management',
            'label' => 'Student Management',
            'permissions' => [
                'view students',
                'create students',
                'edit students',
                'delete students',
            ],
        ],
        [
            'key' => 'faculty_management',
            'label' => 'Faculty Management',
            'permissions' => [
                'view faculty',
                'create faculty',
                'edit faculty',
                'delete faculty',
            ],
        ],
        [
            'key' => 'project_management',
            'label' => 'Project Management',
            'permissions' => [
                'view projects',
                'view all projects',
                'delete projects',
                'manage project team',
                'remove team members',
            ],
        ],
        [
            'key' => 'evaluator_management',
            'label' => 'Evaluator Management',
            'permissions' => [
                'view evaluators',
                'assign evaluators',
            ],
        ],
        [
            'key' => 'supervisor_management',
            'label' => 'Supervisor Management',
            'permissions' => [
                'view supervisors',
            ],
        ],
        [
            'key' => 'workflow_management',
            'label' => 'Workflow & Approval',
            'permissions' => [
                'approve fyp',
                'committee final review',
                'committee head approve',
                'manage proposal settings',
                'return proposal to team formation',
                'cancel project invitations',
                'manage supervisor invitations',
                'manage evaluator reviews',
                'decide supervisor change',
                'manage proposal sessions',
                'grant proposal session extensions',
                'decide phase repeat',
                'decide student transfer',
                'respond to project queries',
                'reevaluate phase deliverable',
                'manage phase templates',
                'manage evaluation questions',
            ],
        ],
        [
            'key' => 'reports',
            'label' => 'Reports',
            'permissions' => [
                'view reports',
                'view activity logs',
            ],
        ],
        [
            'key' => 'notification_management',
            'label' => 'Notifications',
            'permissions' => [
                'send notifications',
                'delete notifications',
            ],
        ],
    ],

    'activity_log' => [
        'authority_roles' => ['admin', 'fyp-committee-head'],
        'authority_permissions' => ['view activity logs'],
    ],

    'proposal' => [
        'min_members' => 3,
        'max_members' => 5,
        'min_evaluators' => 2,
        'max_evaluators' => 3,
        'document_disk' => 'public',
        'document_max_kb' => 10240,
        'settings_roles' => ['admin', 'fyp-committee-head'],
        'settings_permissions' => ['manage proposal settings'],
        'default_supervisor_limits' => [
            'proposal' => 5,
            'phase_1' => 5,
            'phase_2' => 5,
        ],
        'default_evaluator_limits' => [
            'proposal' => 5,
            'phase_1' => 5,
            'phase_2' => 5,
        ],
        'assign_evaluator_roles' => ['admin', 'fyp-committee-head', 'fyp-committee-member'],
        'assign_evaluator_permissions' => ['assign evaluators'],
        'view_supervisor_overview_roles' => ['admin', 'fyp-committee-head', 'fyp-committee-member'],
        'view_supervisor_overview_permissions' => ['view supervisors'],
        'evaluator_assignment_stages' => ['committee_review', 'evaluator_review', 'revision_required'],
        'manage_team_roles' => ['admin', 'fyp-committee-head'],
        'manage_team_permissions' => ['manage project team', 'remove team members'],
        'return_team_formation_roles' => ['admin', 'fyp-committee-head'],
        'return_team_formation_permissions' => [
            'return proposal to team formation',
            'manage project team',
        ],
        'manage_deleted_project_roles' => ['admin', 'fyp-committee-head'],
        'manage_deleted_project_permissions' => ['delete projects', 'manage project team'],
        'cancel_invitation_roles' => ['admin', 'fyp-committee-head'],
        'cancel_invitation_permissions' => [
            'cancel project invitations',
            'manage project team',
        ],
        'manage_supervisor_invitation_roles' => ['admin', 'fyp-committee-head'],
        'manage_supervisor_invitation_permissions' => [
            'manage supervisor invitations',
            'manage project team',
        ],
        'manage_evaluator_review_roles' => ['admin', 'fyp-committee-head'],
        'manage_evaluator_review_permissions' => [
            'manage evaluator reviews',
            'assign evaluators',
        ],
        'supervisor_change_authority_roles' => ['admin', 'fyp-committee-head'],
        'supervisor_change_authority_permissions' => [
            'decide supervisor change',
        ],
        'session_management_roles' => ['admin', 'fyp-committee-head'],
        'session_management_permissions' => ['manage proposal sessions'],
        'session_extension_roles' => ['admin', 'fyp-committee-head'],
        'session_extension_permissions' => ['grant proposal session extensions', 'manage proposal sessions'],
        'phase_repeat_authority_roles' => ['admin', 'fyp-committee-head'],
        'phase_repeat_authority_permissions' => ['decide phase repeat'],
        'student_transfer_authority_roles' => ['admin', 'fyp-committee-head'],
        'student_transfer_authority_permissions' => ['decide student transfer'],
        'respond_project_queries_roles' => ['admin', 'fyp-committee-head', 'fyp-committee-member'],
        'respond_project_queries_permissions' => ['respond to project queries'],
        'send_notification_roles' => ['admin', 'fyp-committee-head', 'fyp-committee-member', 'supervisor'],
        'send_notification_permissions' => ['send notifications'],
        'delete_notification_roles' => ['admin', 'fyp-committee-head'],
        'delete_notification_permissions' => ['delete notifications'],
        'reevaluate_phase_roles' => ['admin', 'fyp-committee-head'],
        'reevaluate_phase_permissions' => ['reevaluate phase deliverable'],
        'manage_phase_templates_roles' => ['admin', 'fyp-committee-head'],
        'manage_phase_templates_permissions' => ['manage phase templates'],
        'template_disk' => 'public',
        'template_max_kb' => 10240,
        'manage_question_bank_roles' => ['admin', 'fyp-committee-head'],
        'manage_question_bank_permissions' => ['manage evaluation questions'],
        'committee_review_roles' => ['admin', 'fyp-committee-member', 'fyp-committee-head'],
        'evaluator_resubmit_modes' => [
            'all' => 'All assigned evaluators',
            'negative_only' => 'Only evaluators who rejected or requested revision',
        ],
    ],

    'proposal_workflow_stages' => [
        ['key' => 'proposal_submitted', 'label' => 'Proposal Submitted', 'order' => 1],
        ['key' => 'group_confirmed', 'label' => 'Group Members Confirmed', 'order' => 2],
        ['key' => 'supervisor_review', 'label' => 'Supervisor Review', 'order' => 3],
        ['key' => 'committee_review', 'label' => 'FYP Committee Review', 'order' => 4],
        ['key' => 'evaluators_assigned', 'label' => 'Evaluators Assigned', 'order' => 5],
        ['key' => 'evaluator_review', 'label' => 'Evaluator Review', 'order' => 6],
        ['key' => 'committee_final', 'label' => 'Committee Final Review', 'order' => 7],
        ['key' => 'committee_head_approval', 'label' => 'Committee Head Approval', 'order' => 8],
        ['key' => 'approved', 'label' => 'Proposal Approved', 'order' => 9],
    ],

    'proposal_workflow_map' => [
        'draft' => 'proposal_submitted',
        'invitations_pending' => 'proposal_submitted',
        'group_confirmed' => 'group_confirmed',
        'supervisor_pending' => 'supervisor_review',
        'supervisor_revision_pending' => 'supervisor_review',
        'supervisor_rejected' => 'supervisor_review',
        'supervisor_accepted' => 'committee_review',
        'committee_review' => 'committee_review',
        'evaluators_assigned' => 'evaluators_assigned',
        'evaluator_review' => 'evaluator_review',
        'committee_final' => 'committee_final',
        'committee_head_approval' => 'committee_head_approval',
        'approved' => 'approved',
        'revision_required' => 'evaluator_review',
    ],

    'phase_deliverable_workflow_stages' => [
        ['key' => 'deliverable_submitted', 'label' => 'Deliverable Submitted', 'order' => 1],
        ['key' => 'supervisor_review', 'label' => 'Supervisor Review', 'order' => 2],
        ['key' => 'committee_review', 'label' => 'FYP Committee Review', 'order' => 3],
        ['key' => 'evaluators_assigned', 'label' => 'Evaluators Assigned', 'order' => 4],
        ['key' => 'evaluator_review', 'label' => 'Evaluator Review', 'order' => 5],
        ['key' => 'committee_final', 'label' => 'Committee Final Review', 'order' => 6],
        ['key' => 'committee_head_approval', 'label' => 'Committee Head Approval', 'order' => 7],
        ['key' => 'approved', 'label' => 'Phase Approved', 'order' => 8],
    ],

    'phase_deliverable_workflow_map' => [
        'draft' => 'deliverable_submitted',
        'supervisor_review' => 'supervisor_review',
        'committee_review' => 'committee_review',
        'evaluators_assigned' => 'evaluators_assigned',
        'evaluator_review' => 'evaluator_review',
        'supervisor_revision_pending' => 'supervisor_review',
        'committee_final' => 'committee_final',
        'committee_head_approval' => 'committee_head_approval',
        'approved' => 'approved',
        'revision_required' => 'evaluator_review',
    ],

];
