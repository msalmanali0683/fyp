export const FYP_ROLES = [
  { value: 'student', label: 'Student' },
  { value: 'faculty', label: 'Faculty' },
  { value: 'supervisor', label: 'Supervisor' },
  { value: 'admin', label: 'Admin' },
  { value: 'evaluator', label: 'Evaluator' },
  { value: 'fyp-committee-member', label: 'FYP Committee Member' },
  { value: 'fyp-committee-head', label: 'FYP Committee Head' },
]

export const USER_MANAGEMENT_ROLES = ['admin', 'fyp-committee-head']

export const ROLE_MANAGEMENT_ROLES = ['fyp-committee-head']

export const DASHBOARD_ADMIN_ROLES = ['admin', 'fyp-committee-head', 'fyp-committee-member']

export const SYSTEM_ROLES = FYP_ROLES.map((r) => r.value)

export const STAFF_ROLES = FYP_ROLES.filter((r) => r.value !== 'student')

export const CORE_STAFF_ROLES = FYP_ROLES.filter(
  (r) => !['student', 'faculty', 'supervisor', 'evaluator'].includes(r.value)
)

export const STUDENT_ROLE_OPTIONS = FYP_ROLES.filter((r) => r.value === 'student')

export const FACULTY_ROLE_OPTIONS = FYP_ROLES.filter((r) => r.value === 'faculty')

export const FACULTY_ASSIGNABLE_ROLES = FYP_ROLES.filter((r) =>
  ['faculty', 'supervisor', 'evaluator'].includes(r.value)
)

export const SUPERVISOR_FACULTY_ROLES = FYP_ROLES.filter((r) => ['faculty', 'supervisor'].includes(r.value))

export const EVALUATOR_FACULTY_ROLES = FYP_ROLES.filter((r) => ['faculty', 'evaluator'].includes(r.value))

export const roleLabel = (slug) =>
  FYP_ROLES.find((r) => r.value === slug)?.label ||
  slug.replace(/-/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
