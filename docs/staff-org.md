# Staff & organisation (Phase 1, Step 8)

Three tenant-scoped org entities: **departments**, **rooms**, and **staff profiles**.
All use the `BelongsToHospital` trait (global scope + auto-filled `hospital_id`), so no
controller or request ever sets or filters `hospital_id` by hand (constraint B8).

## Files

| Concern | File |
|---|---|
| Tables | `2026_07_19_130000_create_departments_table.php`, `…_130010_create_rooms_table.php`, `…_130020_create_staff_profiles_table.php` |
| Models | `app/Models/Department.php`, `app/Models/Room.php`, `app/Models/StaffProfile.php` (+ `User::staffProfile()`) |
| Enums | `app/Enums/RoomType.php`, `app/Enums/RoomStatus.php` |
| Requests (DTOs) | `app/Http/Requests/{Department,Room,StaffProfile}Request.php` |
| Policies | `app/Policies/{Department,Room,StaffProfile}Policy.php` |
| Controllers | `app/Http/Controllers/Admin/{Department,Room,StaffProfile}Controller.php` |
| Views | `resources/views/admin/{departments,rooms,staff}/…` |
| Routes | `Route::resource('departments'|'rooms'|'staff', …)->except('show')` under `/admin` |

## Departments

Name **and** code are unique **per hospital** — the `unique` rules in `DepartmentRequest`
are manually scoped to the current hospital (a bare unique rule would collide across tenants
and leak names between them), excluding soft-deleted rows and ignoring the row being edited.
`head_user_id` is an optional link to a staff user, validated to belong to the same hospital.

## Rooms

Optionally attached to a department (validated same-hospital). `type` (`RoomType`) and
`status` (`RoomStatus`) are backed enums validated with `Enum` rules; `RoomStatus::isBookable()`
(available only) is the seam the scheduling/admissions modules will use later. Name is unique
per hospital.

## Staff profiles

Clinical/HR extension of a `users` row — **one profile per user** (unique `user_id`), holding
specialty, licence, qualifications, signature path and a weekly `schedule` JSON template.
Login credentials stay on the `User`; this never carries a password. The linked user must
belong to the current hospital (scoped `exists` rule) and the `user_id` link is immutable on
update (the controller drops it from the payload).

## RBAC

Permissions `departments.manage`, `rooms.manage`, `staff.manage` are seeded in `RbacSeeder`
and assigned to `hospital_admin`. Any signed-in staff (`access-admin`) may **view** the org
structure; only the `.manage` holder may create/update/delete. Enforced by the three policies
in the controllers (and, later, identically in the API — C13).

## Tenancy

Each entity ships an A-vs-B isolation test (§2.1): `DepartmentTest`, `RoomTest`,
`StaffProfileTest` prove hospital A cannot list, edit, or cross-link hospital B's rows, and
that unique names are reusable across hospitals. All deletes are soft.
