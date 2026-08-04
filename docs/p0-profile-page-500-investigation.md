# P0 Investigation — Profile page returns HTTP 500

**Date:** 2026-08-04  
**Priority:** P0 production  
**Status:** Root cause proven · minimal fix applied · regression verified  
**Canvas:** [/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-profile-page-500-investigation.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-profile-page-500-investigation.canvas.tsx)

---

## Root cause

Blade compiled greeting option labels incorrectly. Source used `Dear {{'{{customer_name}}'}}`, which Blade turned into invalid PHP `<?php echo e('{{customer_name); ?>'}}`, causing a `ParseError` and HTTP 500 for every authenticated user on `GET /profile`.

| Metric | Value |
|--------|-------|
| Exception | `ParseError` |
| Fix size | 2 lines |
| Profile tests | 7/7 passed |
| Blast radius | All roles (greeting select always rendered) |

---

## Verdict

The Profile module controller, services, middleware, and policies are healthy. The page fails while rendering `resources/views/profile/edit.blade.php` because two greeting option labels contain nested Blade mustaches that the compiler truncates into a PHP ParseError.

### Why it started

Introduced in commit `8942d3b` on 2026-08-03 (21:14 +0530): communication template / reply playbooks added a Default Greeting select that tried to display the literal token `{{customer_name}}` using `{{'{{customer_name}}'}}`.

That syntax is not a valid Blade escape for nested braces. The compiler closes the echo at the first inner `}`.

### Related to today's deployments?

**Indirectly — yes in the deployed lineage.** The broken lines landed yesterday in the communication feature commit that is an ancestor of current HEAD. Today's Aug 4 commits (operations workspace / workforce presence) did not touch the Profile Blade, but any deploy that includes `8942d3b` ships the 500.

Latest tagged release in repo: `v4.0.3` (2026-07-29). Bug is newer than that tag.

### Failure mechanism

| Step | What happens |
|------|--------------|
| 1 | `GET /profile` authenticates and `ProfileController::edit` runs |
| 2 | `view('profile.edit')` compiles greeting options |
| 3 | Blade turns `Dear {{'{{customer_name}}'}}` into `<?php echo e('{{customer_name); ?>'}}` |
| 4 | PHP `ParseError: Unclosed '(' does not match '}'` |
| 5 | HTTP 500 for every role — greeting select is always rendered |

---

## Captured exception

| Field | Value |
|-------|-------|
| Exception | `ParseError` |
| Message | `Unclosed '(' does not match '}'` |
| HTTP | 500 |
| View | `resources/views/profile/edit.blade.php` |
| Compiled | `storage/framework/views/abdf737fbc89393c1599531d217f3e8d.php:157` |
| Controller | `ProfileController::edit` — returned view successfully; failure is compile/render |
| Introduced | `8942d3b` — 2026-08-03 21:14 +0530 — feat(communication): add template store runtime and reply playbooks |

### Stack (top frames)

| Frame | Location |
|-------|----------|
| #0 | `Filesystem::getRequire` — compile require of compiled view |
| #1 | `PhpEngine::evaluatePath` |
| #2 | `CompilerEngine::get` |
| #3 | `View::getContents` / render |
| #… | Router → `ProfileController::edit` → `view('profile.edit')` |
| Test | `ProfileTest::test_profile_page_is_displayed` → `GET /profile` |

### Broken compile (proven)

**Source line (pre-fix):**

```blade
Dear {{'{{customer_name}}'}}
```

**Compiled PHP (line 157) — invalid:**

```php
<?php echo e('{{customer_name); ?>'}}
```

### Fixed compile (proven)

**Source line (post-fix):**

```blade
Dear @{{customer_name}}
```

**Compiled / rendered output:**

```text
Dear {{customer_name}}
```

---

## Layer inspection

No assumptions — each layer checked against the reproduced 500.

| Layer | What was checked | Result |
|-------|------------------|--------|
| Route | `GET /profile` → `profile.edit` → `ProfileController@edit` | OK — registered |
| Controller | `ProfileController::edit` | OK — builds user + availability snapshot |
| Service | `OperationsRoleService` / `TeamAvailabilityService` | OK — not in failure path |
| Blade | `profile/edit.blade.php` greeting options | FAIL — ParseError on compile |
| Middleware | auth, `EnsureUserIsActive`, `TrackTeamMemberActivity`, … | OK — reached controller |
| Policy | `LeaveRequestPolicy` `@can` create | OK — gated; not required for 500 |
| Model | `User` + `TeamAvailabilityStatusCast` | OK — null availability casts to Offline |
| Relationships | None eager-loaded by `edit()` | N/A — not involved |
| View data | `user`, `showsTeamAvailability`, `availability` | OK — always renders greeting select |
| JavaScript | None on profile page for greetings | N/A |

**Not the cause:** Earlier log noise for `Route [leave-requests.index]` (2026-08-03 15:09) came from `PendingLeaveApprovalsCardProvider` during artisan boot before routes finished loading — different stack, not `ProfileController`.

---

## Verification matrix

| Scenario | How verified | Result |
|----------|--------------|--------|
| Admin profile | `GET /profile` as `ROLE_ADMIN` | Pass |
| Agent profile | `GET /profile` as `ROLE_AGENT` | Pass |
| Super Admin profile | `GET /profile` as `ROLE_SUPERADMIN` | Pass |
| Missing optional data | null designation/department/phone/company/greeting/telegram/availability | Pass |
| Newly created user | `User::factory()->create()` (baseline ProfileTest) | Pass |
| Soft-deleted relations | `edit()` loads no soft-deleted relations; SoftDeletes on User only blocks auth | N/A — not causal |
| Null-safe handling | `TeamAvailabilityStatusCast` + snapshot null coalescing | Pass |

### Regression

| Check | Evidence | Result |
|-------|----------|--------|
| Profile opens | ProfileTest display + role matrix | Pass |
| Edit profile | `PATCH /profile` with greeting + fields | Pass |
| Avatar | No avatar upload UI on profile page | N/A |
| Password change | `PUT /password` via `PasswordController` | Pass |
| Preferences | `default_greeting_style` saved + literal `{{customer_name}}` labels render | Pass |
| Authorization | No policy/middleware changes; LeaveRequest `@can` unchanged | Pass |
| Console errors | Server-rendered Blade only; no profile JS bundle | N/A / no JS surface |

---

## Minimal fix

Replace nested mustache string echo with Blade's `@{{` escape so the literal token `{{customer_name}}` is emitted without entering PHP echo parsing.

### Files modified

| File | Change |
|------|--------|
| `resources/views/profile/edit.blade.php` | Minimal Blade escape fix (2 lines) |
| `tests/Feature/ProfileTest.php` | Role / null / password / greeting regression coverage |

### Diff (production fix only)

```diff
- Dear {{'{{customer_name}}'}}
+ Dear @{{customer_name}}
- Hello {{'{{customer_name}}'}}
+ Hello @{{customer_name}}
```

### Test results

| Metric | Value |
|--------|-------|
| Tests run | 7 |
| Passed | 7 |
| Assertions | 30 |

Suite: `ProfileTest` (6) + `EmployeeRoleAccessTest` personal access (1).

```bash
php artisan test --filter='ProfileTest|test_employee_can_access_personal_attendance_leave_and_profile'
```

---

## Rollback strategy

1. Revert the two greeting lines in `resources/views/profile/edit.blade.php` (or `git checkout -- resources/views/profile/edit.blade.php` if only this fix is present).
2. Run `php artisan view:clear` so stale compiled views are discarded.
3. Optionally revert ProfileTest additions if rolling back the whole change set.
4. Confirm `GET /profile` returns 200 for an authenticated admin/agent.

**Note:** Rolling back the Blade fix without removing the greeting select restores the ParseError. Prefer keeping the `@{{customer_name}}` escape. Rolling back commit `8942d3b` entirely removes the greeting UI but is wider than needed.

---

## Deliverables checklist

- [x] Interactive Canvas — [p0-profile-page-500-investigation.canvas.tsx](/Users/ravi/.cursor/projects/Users-ravi-radium-service-desk/canvases/p0-profile-page-500-investigation.canvas.tsx)
- [x] Markdown report — this file
- [x] Root cause identified
- [x] Files modified listed
- [x] Test results recorded
- [x] Rollback strategy documented
- [x] Minimal targeted fix only (no Profile module rewrite)
