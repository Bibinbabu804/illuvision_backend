# 13 — Creative Marketplace Authorization Audit

**Generated:** 2026-09-26
**Scope:** READ-ONLY audit of the authorization model that a proposed "Creative Marketplace" bounded context would have to live inside. **No Creative code exists** — 0 classes, 0 routes, 0 tables, 0 migrations, 0 tests. Nothing in this document was implemented, and nothing in the existing authorization model was changed.
**Method:** direct source inspection (`config/`, `app/Support/Authorization/`, `app/Http/Middleware/`, `app/Http/Controllers/`, `app/Models/`, `database/migrations/`, `database/seeders/`, `php artisan route:list --json`). Prior `docs/audit/*` artifacts were treated as evidence, not as truth.

## Legend

| Tag | Meaning |
|---|---|
| `[CURRENT FACT]` | Verified from source on 2026-09-26, with `file:line` or command evidence. |
| `[ARCHITECTURE DOCUMENT]` | Normative text from `AGENTS.md` or `docs/architecture/ARCHITECTURE_RULES.md`. |
| `[INFERENCE]` | Reasonably inferred from the implementation or from relationships between artefacts. |
| `[RECOMMENDATION]` | Proposed target design. Not implemented. No route, seed, migration or class was written. |
| `[OPEN DECISION]` | Cannot be determined from available evidence. The missing information is stated exactly. |

---

## 1. Executive summary

`[CURRENT FACT]` The current authorization model is **role → permission → middleware**. There is exactly one permission algorithm, one middleware class, one alias, and one permission string grammar (`section.action`). It is enforced **at the route boundary only**.

`[CURRENT FACT]` It is **route-level only and coarse**. `[INFERENCE]` There is no resource-level authorization primitive anywhere in the repository, so "can this user view *this* brief" is currently expressed as an ad-hoc ownership comparison written inline inside a controller method.

`[CURRENT FACT]` Three things make this model fragile in a way that matters directly for Creative:

1. `users.role` is a free-text `VARCHAR(50)` with **no foreign key** to `roles` (`database/migrations/2026_07_10_000002_update_users_role_column.php:18`).
2. Code writes and queries **four role values that no seeder creates**, and a missing role silently resolves to an **empty permission set** rather than an error (`app/Support/Authorization/RolePermissionChecker.php:62-64`).
3. Three permission strings used on live routes (`settlements.view`, `settlements.approve`, `settlements.pay`) are **never granted to any role by any seeder or migration**.

`[RECOMMENDATION]` Creative must reuse this model — it must **not** introduce a parallel one — but it must seed every permission it references and must add resource-level checks. See §8.

---

## 2. The current model, with evidence

### 2.1 Guards: one session guard, plus Sanctum bearer tokens

`[CURRENT FACT]` `config/auth.php` defines exactly **one** guard:

```text
config/auth.php:16  'guard' => env('AUTH_GUARD', 'web'),
config/auth.php:39  'web' => ['driver' => 'session', 'provider' => 'users'],
```

`[CURRENT FACT]` `config/sanctum.php:36` sets `'guard' => ['web']`, so Sanctum accepts either the first-party `web` session **or** a bearer token from `personal_access_tokens`. `config/auth.php` contains **no** `sanctum` key — the guard is registered by Sanctum's own service provider.

`[CURRENT FACT]` `config/sanctum.php:50` — `'expiration' => null`. Sanctum's own comment at `config/sanctum.php:46` states that the `expires_at` column exists but first-party sessions are unaffected. **With `expiration` null, a personal access token never expires and `expires_at` is never populated.**

`[CURRENT FACT]` `database/migrations/2026_01_08_172144_create_personal_access_tokens_table.php:15-19` — the table has `abilities` (nullable text), `last_used_at`, and `expires_at` (nullable, indexed). `[CURRENT FACT]` `abilities` is **never written**: the only three `createToken(...)` call sites are `app/Http/Controllers/AuthController.php:28`, `app/Http/Controllers/AuthController.php:55`, and `app/Http/Controllers/Auth/GoogleAuthController.php:72`, all of which pass only a name and no abilities. `app/Http/Controllers/Auth/ResetPasswordController.php:68` does the same.

`[INFERENCE]` `tokenCan()` is therefore unusable as an authorization mechanism today — every token is issued with the `*` ability by omission. Creative must not design around token abilities without first deciding to start issuing them.

### 2.2 The role column: free text, no foreign key

`[CURRENT FACT]` The column has been an enum and is now a varchar:

```text
database/migrations/0001_01_01_000000_create_users_table.php:19   enum('role', ['admin', 'user'])->default('user')
database/migrations/2026_07_10_000002_update_users_role_column.php:18
    DB::statement("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'user'")
```

`[CURRENT FACT]` That migration's own comment at line 11 states the reason: *"Change role column from ENUM('admin','user') to VARCHAR to support 'partner' role."*

`[CURRENT FACT]` **There is no foreign key from `users.role` to `roles.id` or `roles.slug`.** The only FKs touching `users` are `created_by_partner_id -> partners.id` (`database/migrations/2026_11_05_000001_add_partner_ownership_to_users_table.php:16-19`) and the self-referencing `user_id` at `database/migrations/0001_01_01_000000_create_users_table.php:33`. `[INFERENCE]` The database therefore cannot reject an unknown role value; only the application can, and only where it explicitly checks.

`[CURRENT FACT]` `database/migrations/2026_08_01_000001_add_status_and_last_login_to_users_table.php:12` adds `status` `string(20) default 'active'` **immediately after** `role` — a suspension flag that sits next to the role column. `[INFERENCE]` `status` is a candidate Creative suspension hook, but nothing in the authorization path reads it (see §2.5).

### 2.3 The permission store: `roles` + `role_permissions`

`[CURRENT FACT]` `database/migrations/2026_06_01_000002_create_roles_table.php:12-18`:

```text
id            bigint PK
name          string UNIQUE
slug          string UNIQUE
description   text nullable
created_at / updated_at
```

`[CURRENT FACT]` `database/migrations/2026_06_01_000003_create_role_permissions_table.php:12-20`:

```text
id         bigint PK
role_id    foreignId -> roles.id, cascadeOnDelete()
permission string
granted    boolean default true
created_at / updated_at
UNIQUE (role_id, permission)
```

`[CURRENT FACT]` `app/Models/Role.php:19-22` — `grantedPermissions()` filters the relation by `granted === true` and plucks `permission`. `[INFERENCE]` The `granted` flag is a deny-list-capable design (a row can exist with `granted = false`), but `[CURRENT FACT]` every seeder and migration in the repository inserts `granted => true` and no code path ever sets it to `false`, so the deny direction has never been exercised.

### 2.4 Who is seeded

`[CURRENT FACT]` `database/seeders/RolesAndPermissionsSeeder.php:18-39` creates exactly **five** roles:

| slug | name | line |
|---|---|---|
| `super-admin` | Super Admin | 19-22 |
| `admin` | Admin | 23-26 |
| `manager` | Manager | 27-30 |
| `staff` | Staff | 31-34 |
| `screen_provider` | Screen Provider | 35-38 |

`[CURRENT FACT]` The permission grammar is `"{section}.{action}"` built from a `$sections` map at `RolesAndPermissionsSeeder.php:42-63`. Counting the actions in that map yields **76 distinct permission strings** across 20 sections (dashboard 1, bookings 6, screens 4, calendar 1, pricing 4, partners 6, screen_providers 6, products 4, clients 4, payments 6, reports 2, contacts 4, settings 2, user_management 4, notifications 2, blogs 4, testimonials 4, faqs 4, jobs 4, rewards 4).

`[CURRENT FACT]` Grants: `super-admin` receives **all 76** (line 143: `($slug === 'super-admin' || $slug === 'superadmin') ? $allPermissions : ...`); `admin` receives all 76 **minus** `settings.delete`, `user_management.delete`, `payments.delete` (lines 75-82 — note those three strings are themselves **not** in the `$sections` map, so the exclusion is a no-op); `manager`, `staff` and `screen_provider` receive explicit lists (lines 83-126).

`[CURRENT FACT]` `database/seeders/ScreenProviderRoleSeeder.php:17-52` is a **second, overlapping** seeder for the same `screen_provider` slug: it `updateOrCreate`s the role, **deletes** its `role_permissions` rows (line 26), re-inserts the same 7 `screen_provider.*` permissions, and clears the cache. `[INFERENCE]` Whichever seeder runs last wins; both currently produce the same 7 rows, so the duplication is currently benign but is a latent conflict.

`[CURRENT FACT]` `database/migrations/2026_08_01_000003_update_roles_and_permissions_data.php:29-45` performs a **rename in place**: `operator -> admin`, `content-reviewer -> manager`, `viewer -> staff`, then `role_permissions` is truncated (line 48) and repopulated from a *different, smaller* section list (lines 8-24, 15 sections / 68 permissions — it omits `notifications`, `blogs`, `testimonials`, `faqs`, `jobs`, `rewards`).

`[INFERENCE]` `RolesAndPermissionsSeeder` (the runnable path, and the one `DatabaseSeeder` invokes) is therefore the **authoritative** definition of the permission set; the data migration is historical and is already superseded. This is worth stating explicitly because a reviewer reading only the migrations would derive a different, smaller permission set than the running system has.

### 2.5 The single owner of the permission algorithm

`[ARCHITECTURE DOCUMENT]` `docs/architecture/ARCHITECTURE_RULES.md:43` (Rule 12): *"the permission algorithm must have exactly one owner, shared by every caller (middleware, models, services) — never duplicated per caller, and never owned by the transport layer."*

`[CURRENT FACT]` That single owner is `App\Support\Authorization\RolePermissionChecker` (`app/Support/Authorization/RolePermissionChecker.php:38`). Every claim below was read in that file:

| Claim | Evidence | Verified |
|---|---|---|
| Resolution is by **role slug only** | `:60` — `Role::where('slug', $roleSlug)->first()` | yes |
| Results cached as `role_permissions:{slug}` | `:73` — `"role_permissions:{$roleSlug}"` | yes |
| TTL is **3600 seconds** | `:40` — `public const CACHE_TTL_SECONDS = 3600;` used at `:57` | yes |
| Comparison is **strict** `in_array` | `:44` — `in_array($permission, $this->permissionsForRole($user->role), true)` | yes |
| A **missing role resolves to an empty list, not an error** | `:52-54` (null/empty string → `[]`) and `:62-64` (`if (! $role) { return []; }`) | yes |
| **There is deliberately NO super-admin bypass here** | documented in the class docblock at `:31-36`; the only branches in `userHasPermission` (`:42-45`) and `permissionsForRole` (`:50-69`) are the null check, the role lookup miss, and the cache | yes |

`[CURRENT FACT]` The bypass asymmetry is also documented in-source at `:26-36`, which states that the super-admin bypass "lives in `PermissionMiddleware::handle()` and has never applied to `User::hasPermission()`" and "must not be 'fixed' as a side effect of this extraction."

`[CURRENT FACT]` The two callers are `app/Models/User.php:80-82` (`hasPermission()` delegating to the checker) and `app/Http/Middleware/PermissionMiddleware.php:44-47` (`userHasPermission()` delegating to the checker). `[CURRENT FACT]` There are **no** other callers in `app/`, `routes/` or `database/`. `[INFERENCE]` This means the convenient `$user->hasPermission()` helper — the only in-model authorization primitive — is **dead in production code**: `grep` across `app/`, `routes/`, `database/` finds no call site. It is exercised only by `tests/Feature/Reporting/ReportingExportAndAccessTest.php:315`, whose comment explicitly records the asymmetry.

`[CURRENT FACT]` Cache invalidation exists as `clearCache()` at `:76-87` (single slug, or all slugs) and is surfaced statically as `PermissionMiddleware::clearPermissionCache()` at `app/Http/Middleware/PermissionMiddleware.php:49-52`, called from `database/seeders/RolesAndPermissionsSeeder.php:156` and `database/seeders/ScreenProviderRoleSeeder.php:52`. `[INFERENCE]` **The only cache invalidation in the whole repository is invoked by seeders.** There is no invalidation on role update, permission update, or user role change, so a permission change can take up to 3600 seconds to take effect. See `app/Http/Controllers/Api/RoleController.php` and `app/Http/Requests/RoleRequest.php` — neither calls `clearPermissionCache`.

### 2.6 The middleware: 401, 403, and the super-admin bypass

`[CURRENT FACT]` `app/Http/Middleware/PermissionMiddleware.php`, registered under the alias `permission` at `bootstrap/app.php:18-20` (`'permission' => PermissionMiddleware::class`). It is the **only** class in `app/Http/Middleware/`.

`[CURRENT FACT]` `handle()` takes variadic permissions and applies them **conjunctively** (line 32-39: every requested permission must be held; the first miss short-circuits to 403).

| Outcome | Body | Status | Evidence |
|---|---|---|---|
| Unauthenticated | `{"status":false,"message":"Unauthenticated."}` | 401 | `:20-25` |
| Authorized | `$next($request)` | — | `:41` |
| **Super-admin bypass** | `$next($request)` when `$user->role === 'super-admin' \|\| $user->role === 'superadmin'` | — | `:28-30` |
| Unauthorized | `{"status":false,"message":"Forbidden. You do not have the required permission: ".$permission}` | 403 | `:34-37` |

`[CURRENT FACT]` The bypass at `:28-30` is checked **before** the permission loop, so a super-admin passes every `permission:*` route regardless of what `role_permissions` says. It also accepts **two** spellings (`super-admin` and `superadmin`) while `RolePermissionChecker` only ever resolves a slug. `[INFERENCE]` `superadmin` therefore acts as a fully-privileged role value that resolves to no permissions at all through any other path — the bypass is the only thing making it privileged. `[CURRENT FACT]` Only `super-admin` is actually seeded (`RolesAndPermissionsSeeder.php:19`); the `superadmin` branch at line 28 and the `superadmin` branch at seeder line 143 are both defensive-only.

`[ARCHITECTURE DOCUMENT]` `docs/architecture/ARCHITECTURE_RULES.md:43` (Rule 12) names this exact asymmetry as a deliberate behaviour that *"must be preserved and pinned by tests until an explicit authorization change is approved, since altering them changes authorization behaviour."* `AGENTS.md` §12 repeats it: *"Deliberate asymmetries between the authoritative route check and convenience helpers — for example a super-admin bypass that exists **only** in middleware — are intentional. Preserve them exactly and keep them pinned by tests."`

### 2.7 There is no policy layer

`[CURRENT FACT]` `app/Policies` **does not exist**. `[CURRENT FACT]` `grep -rn "Gate::" app/ bootstrap/ routes/` returns **zero** matches. `[CURRENT FACT]` `grep -rn "authorize(" app/Http/Controllers/` returns **zero** matches, and **no** controller declares `__construct` middleware.

`[CURRENT FACT]` Authorization is therefore expressed in exactly one of three ways:

1. a `permission:*` middleware group on the route;
2. an inline `auth:sanctum` group;
3. an ad-hoc ownership comparison written inside the controller method.

`[CURRENT FACT]` Representative examples of form 3 — these are the pattern a Creative developer will otherwise copy:

```text
app/Http/Controllers/BookingController.php:116-118
    if ($booking->user_id !== $request->user()->id) {
        return $this->errorResponse('Unauthorized', 403);
    }

app/Http/Controllers/PaymentController.php:48-50
    if ($booking->user_id !== $request->user()->id) {
        return $this->errorResponse('Unauthorized', 403);
    }

app/Http/Controllers/PartnerPortalController.php:157-159
    $partner = $this->getAuthenticatedPartner($request);
    if (!$partner) {
        return $this->errorResponse('Partner not found or access denied.', 403);
    }
```

`[INFERENCE]` Form 3 is not policy authorization. It is a hand-written `id === $request->user()->id` check, it returns 403 rather than 401 for a *missing resource ownership* case, and — critically for Creative — **there is no shared primitive** to express "the actor participates in this project" or "the actor is one of the invited reviewers". A Creative project has many participants with different legitimate rights; a single `user_id` equality check cannot express that, and the Creative team would have to hand-roll a bespoke check per controller. See §7.6 and §8.

### 2.8 Route-level enforcement, measured

`[CURRENT FACT]` From `php artisan route:list --json` (385 routes):

```text
routes with auth:sanctum or the web group ................ 300
routes with a permission:* middleware ..................... 240
routes with permission:* but NO auth middleware ........... 16
routes with neither ....................................... 69
```

`[CURRENT FACT]` **All 16** permission-without-auth routes are `/api/v2/*` — the 6 asset routes, the 3 booking routes, `coverage/cities/{cityId}`, the 5 finance routes and `v2/recommendations`. `[INFERENCE]` This is why `PermissionMiddleware` needs its own 401 branch at all (`PermissionMiddleware.php:20-25`): on the v2 surface the permission middleware **is** the authentication gate.

`[INFERENCE]` The practical consequence for Creative is unambiguous: **a `permission:*` middleware group does not imply the request was authenticated.** A new Creative surface that copies the v2 pattern would get a 401 with `{"status":false,"message":"Unauthenticated."}` instead of Laravel's default, which is a *different* envelope (see artifact 14, §6).

---

## 3. Verified role-value drift

`[CURRENT FACT]` The following role values are written or queried by application code, and **no seeder or migration creates a `roles` row with that slug**. Because `RolePermissionChecker::permissionsForRole()` returns `[]` on a lookup miss (`RolePermissionChecker.php:62-64`), every one of these values resolves to an **empty permission set**. In a `permission:*` group this means a hard 403 for everyone who is not a super-admin; outside a `permission:*` group it means `$user->hasPermission()` is always `false`.

| # | Value | Site | Operation | Verified line content |
|---|---|---|---|---|
| 1 | `partner` | `app/Services/PartnerService.php:29` | **write** — `'role' => 'partner'` in a `User::create`/update payload | `'role' => 'partner',` |
| 2 | `user` | `app/Services/ManualBookingService.php:93` | **write** — `'role' => 'user'` | `'role' => 'user',` |
| 3 | `user` | `app/Http/Controllers/Auth/GoogleAuthController.php:64` | **write** — `'role' => 'user'` on Google sign-up | `'role' => 'user',` |
| 4 | `client` | `app/Http/Controllers/Api/AdminRewardsController.php:31` | **query** — `User::where('role', 'client')->with('rewardAccount')` | `$query = User::where('role', 'client')` |

`[CURRENT FACT]` Corroborating context for #1: `database/migrations/2026_07_10_000002_update_users_role_column.php:11` states the enum was widened to `VARCHAR` *"to support 'partner' role"*, yet `RolesAndPermissionsSeeder.php:18-39` never creates a `partner` role row. The database column and the seed data are describing different systems.

`[CURRENT FACT]` Two further unseeded role values exist, for completeness:

```text
app/Http/Controllers/AuthorController.php:58   'role' => $request->role ?? 'user'   (default 'user')
database/migrations/0001_01_01_000000_create_users_table.php:19   default 'user'
```

`[CURRENT FACT]` By contrast, `app/Http/Controllers/Api/AdminUserController.php:61` and `:105` **do** validate against the real role set: `'role' => ['required', 'string', Rule::in(Role::pluck('slug')->toArray())]`. `[INFERENCE]` So the admin user-management surface cannot assign `partner`/`client`/`user`, while four other code paths create exactly those values. This is a live inconsistency, not a hypothetical one.

`[INFERENCE]` **Impact on Creative, stated as a design constraint:** because a role value that is absent from `roles` fails *silently* (empty permissions, no log, no exception), any Creative role that is written by code rather than by a seeder will produce a system that appears to work — registration succeeds, a token is issued — while every protected route 403s. Creative roles must be **seeded**, and a Creative sign-up path must validate the role against `Role::pluck('slug')` the way `AdminUserController` does.

---

## 4. Verified permission-string drift

`[CURRENT FACT]` Cross-referencing every `permission:*` string on the 385 routes against the 76 strings the seeder can produce yields:

```text
distinct permission strings used on routes ........ 62
distinct strings the seeder can grant .............. 76
USED ON ROUTES BUT NEVER GRANTED .................. 3
  settlements.view
  settlements.approve
  settlements.pay
SEEDED BUT NEVER USED ON ANY ROUTE ................ 24
  blogs.create, blogs.delete, bookings.export, calendar.view, clients.create,
  clients.delete, clients.edit, contacts.create, faqs.create, faqs.delete,
  faqs.edit, notifications.edit, notifications.view, partners.export,
  payments.create, payments.delete, payments.edit, payments.export,
  rewards.export, rewards.report, screen_providers.approve,
  screen_providers.delete, screen_providers.export, user_management.delete
```

`[CURRENT FACT]` `grep -rn "settlements\." database/` returns **no matches at all** — no migration and no seeder mentions a `settlements` permission. The `$sections` map in `RolesAndPermissionsSeeder.php:42-63` has no `settlements` key, and the 15-section map in `database/migrations/2026_08_01_000003_update_roles_and_permissions_data.php:8-24` has no `settlements` key either.

`[CURRENT FACT]` Yet those three strings guard **12 live routes** (7 with `settlements.view`, 4 with `settlements.approve`, 1 with `settlements.pay`), including `GET api/v2/finance/settlements` and `POST api/v2/finance/settlements/batch`.

`[INFERENCE]` Consequence: the settlement surface is reachable **only** through the `PermissionMiddleware` super-admin bypass (`:28-30`). Every `admin`, `manager`, `staff` and `screen_provider` account receives 403 on every settlement route. `[INFERENCE]` The 24 unused-but-seeded permissions are the mirror image: they are grantable, cacheable and reportable, but no route consults them — so granting them has no effect on access.

`[RECOMMENDATION]` Creative must treat this as the **precedent to avoid**, not the pattern to copy. The failure mode is silent and total: a permission string in a route that nobody grants produces a route that only super-admins can ever call, with no error anywhere. Every `creative.*` string that appears in a Creative route definition must be inserted by a seeder in the same change, and a test must assert that every permission string referenced by a route is granted to at least one non-super-admin role.

---

## 5. ROLE → PERMISSION → RESOURCE → ACTION for the roles Creative needs

`[RECOMMENDATION]` The whole table below is a **proposal**. No role row, no `role_permissions` row, no route and no seeder was written. `[ARCHITECTURE DOCUMENT]` It follows `docs/audit/creative-marketplace/10-existing-table-reuse-analysis.json:2114-2116`, which already recorded the governing decision: *"existing: roles + role_permissions + RolePermissionChecker + PermissionMiddleware / do_not_create: a creative_roles / creative_permissions / creative authorization resolver / instead: add a creator role row and creative.* permission rows."* This artifact does not revisit that decision; it specifies it.

`[RECOMMENDATION]` Permission grammar reuses the existing `{section}.{action}` shape observed at `RolesAndPermissionsSeeder.php:69` — there is no need for a third segment, and inventing one would break the `section.action` string form that `in_array` matching depends on.

### 5.1 Role inventory and verdicts

| Creative role | Exists today? | Verdict | Note |
|---|---|---|---|
| `super-admin` | **EXISTING** (`RolesAndPermissionsSeeder.php:19`) | **EXISTING** — needs no Creative rows | Already bypasses everything at `PermissionMiddleware.php:28-30`. Adding `creative.*` rows is harmless but unnecessary. |
| `admin` | **EXISTING** (`RolesAndPermissionsSeeder.php:23`) | **EXTEND** | Add `creative.*` grants. Currently receives all 76 minus 3. |
| `manager` | **EXISTING** (`RolesAndPermissionsSeeder.php:27`) | **EXTEND** | Candidate owner of the *project manager* duties, at `[OPEN DECISION]` §5.4. |
| `staff` | **EXISTING** (`RolesAndPermissionsSeeder.php:31`) | **EXTEND** (read-only) | Candidate owner of *reviewer* duties at `[OPEN DECISION]` §5.4. |
| `screen_provider` | **EXISTING** (`RolesAndPermissionsSeeder.php:35`) | `[OPEN DECISION]` | No evidenced relationship to Creative supply. See §5.6. |
| `creator` | **does not exist** | **NEW** | A new `roles` row. Prior artifacts assume this (`10-...json:117`, `09-...json:657`). See the blocking objection in §5.5. |
| buyer / client | **does not exist** | **NEW** | `[OPEN DECISION]` §5.2. The value `client` is already *used* by `AdminRewardsController.php:31` but is not seeded. |
| reviewer | **does not exist** | `[OPEN DECISION]` | §5.4. |
| project manager | **does not exist** | `[OPEN DECISION]` | §5.4. |
| finance / settlement | **does not exist** | `[OPEN DECISION]** | §5.6. The `settlements.*` string family already exists on routes but is granted to nobody — see §4. |

### 5.2 Buyer / client

| Resource | Action | Permission | Verdict |
|---|---|---|---|
| Brief | create | `creative.briefs.create` | **NEW** |
| Brief | view own | `creative.briefs.view` | **NEW** |
| Brief | edit own (pre-publish) | `creative.briefs.edit` | **NEW** |
| Brief | close own | `creative.briefs.close` | **NEW** — `[OPEN DECISION]` §5.7 |
| Brief | view all (staff/admin) | `creative.briefs.view_all` | **NEW** |
| Proposal | view own brief's proposals | `creative.proposals.view` | **NEW** |
| Proposal | accept | `creative.proposals.accept` | **NEW** |
| Proposal | reject / withdraw-request | `creative.proposals.reject` | **NEW** |
| Project | view own | `creative.projects.view` | **NEW** |
| Project | cancel own | `creative.projects.cancel` | **NEW** — `[OPEN DECISION]` §5.7 |
| Delivery | view own | `creative.deliveries.view` | **NEW** |
| Delivery | accept | `creative.deliveries.accept` | **NEW** — `[OPEN DECISION]` §5.7 (acceptance window) |
| Delivery | request changes | `creative.deliveries.request_changes` | **NEW** |
| Review | write (post-completion) | `creative.reviews.create` | **NEW** — `[OPEN DECISION]` §5.7 |
| Funding / escrow | create | `creative.fundings.create` | **NEW** — `[OPEN DECISION]` §5.7 (is funding in scope at all?) |
| Dispute | open | `creative.disputes.open` | **NEW** — `[OPEN DECISION]` §5.7 (dispute window unknown) |

`[OPEN DECISION]` **What is the buyer's role slug?** `client`, `buyer`, `advertiser` and `user` are all attested somewhere in the code: `client` at `AdminRewardsController.php:31`, `user` at `GoogleAuthController.php:64` and `ManualBookingService.php:93`, `advertiser` in no code. Nothing states which is canonical. Missing information: the product decision naming the role, plus whether the existing unseeded `client` users must be migrated into it.

`[OPEN DECISION]` **Is the buyer a `users.role` value at all, or is buyer-ness derived from a Creative table?** `docs/audit/creative-marketplace/09-creative-database-target-schema-blueprint.json:698` (OD-DB-08) already flags this as BLOCKING: *"Can a user be both an advertiser (client) and a creator? The single `users.role` varchar cannot express it."* Missing information: the answer to that question. If a user can be both, the single-column model is insufficient and §5.5 applies with full force.

### 5.3 Creator

| Resource | Action | Permission | Verdict |
|---|---|---|---|
| Service (listing) | create | `creative.services.create` | **NEW** |
| Service | edit own | `creative.services.edit` | **NEW** |
| Service | publish / unpublish | `creative.services.publish` | **NEW** |
| Service | view own (incl. drafts) | `creative.services.view` | **NEW** |
| Portfolio item | create / edit / delete own | `creative.portfolio.manage` | **NEW** |
| Brief | view open briefs in own category | `creative.briefs.view_open` | **NEW** |
| Proposal | submit | `creative.proposals.submit` | **NEW** |
| Proposal | edit own (pre-submit) | `creative.proposals.edit` | **NEW** |
| Proposal | withdraw own | `creative.proposals.withdraw` | **NEW** |
| Proposal | view outcome | `creative.proposals.view` | **NEW** |
| Project | view own | `creative.projects.view` | **NEW** |
| Project | submit delivery | `creative.deliveries.submit` | **NEW** |
| Revision | respond to change request | `creative.revisions.create` | **NEW** |
| Earnings | view own | `creative.earnings.view` | **NEW** — `[OPEN DECISION]` §5.7 |
| Payout | request own | `creative.payouts.request` | **NEW** — `[OPEN DECISION]` §5.7 |
| Profile | edit own | `creative.profile.edit` | **NEW** |
| Onboarding | submit application | `creative.onboarding.submit` | **NEW** — `[OPEN DECISION]` §5.7 (is onboarding gated?) |

`[RECOMMENDATION]` All creator permissions are **`*_own`-by-relationship, not `*_own`-by-column**. There is no `user_id` on a proposal from the actor's point of view — a proposal belongs to a creator row and a brief row. `[INFERENCE]` This is precisely the capability the current model lacks and the reason §7.6 matters.

### 5.4 Reviewer

| Resource | Action | Permission | Verdict |
|---|---|---|---|
| Delivery | view assigned | `creative.review.queue.view` | **NEW** |
| Delivery | approve | `creative.review.approve` | **NEW** |
| Delivery | reject with reason | `creative.review.reject` | **NEW** |
| Revision | view | `creative.revisions.view` | **NEW** |
| Review (public rating) | moderate | `creative.reviews.moderate` | **NEW** |

`[OPEN DECISION]` **Is "reviewer" a distinct role, or is it `staff` / `manager` wearing a hat?** The closest existing analogue is `booking_media.review_status`, whose review actions are performed under `permission:screens.edit` — i.e. the existing system's reviewer is just a `manager` or `admin` with `screens.edit` (`app/Http/Controllers/BookingController.php:1223,1259`, gated by the `screens.view`/`screens.edit` route groups). Missing information: whether Creative review is a full-time internal function, an external contracted reviewer, or the same staff population. This determines whether `roles` gains a `reviewer` row.

`[OPEN DECISION]` **Is review separation-of-duties mandatory — i.e. may a creator review their own delivery, or the requester approve their own project?** `docs/audit/creative-marketplace/09-creative-database-target-schema-blueprint.json:611` already declares separation of duties MANDATORY for payouts (`creative.payouts.request` vs `creative.payouts.approve`, with `requested_by != approved_by`). Missing information: whether the same rule extends to delivery review, and who is accountable for enforcing it.

### 5.5 Project manager

| Resource | Action | Permission | Verdict |
|---|---|---|---|
| Project | view all in portfolio | `creative.projects.view_all` | **NEW** |
| Project | assign creator | `creative.projects.assign` | **NEW** |
| Project | move status | `creative.projects.transition` | **NEW** — mirrors the existing `POST api/v2/bookings/{id}/transition` shape, see artifact 14 §9 |
| Revision | request changes | `creative.revisions.request` | **NEW** |
| Brief | moderate / close | `creative.briefs.moderate` | **NEW** |
| Brief | extend deadline | `creative.briefs.extend` | **NEW** — `[OPEN DECISION]` §5.7 |
| Delivery | view all | `creative.deliveries.view_all` | **NEW** |
| Dispute | resolve | `creative.disputes.resolve` | **NEW** — `[OPEN DECISION]` §5.7 |

`[OPEN DECISION]` **Does "project manager" become a new `roles` row, or is it mapped onto the existing `manager` role (already granted `bookings.create`, `bookings.edit`, `screens.edit` at `RolesAndPermissionsSeeder.php:83-100`)?** Missing information: the staffing decision. Adding a `project_manager` row is cheap (`Role::updateOrCreate`, as at `RolesAndPermissionsSeeder.php:131-137`); mapping onto `manager` avoids a role but silently widens `manager` to every user who already holds it.

`[RECOMMENDATION]` **Objection that must be recorded before any role row is created:** `09-creative-database-target-schema-blueprint.json:657` proposes *"roles (one new row: creator)"*. That plan only works under a **single-role-per-user** assumption. `09-...json:698` (OD-DB-08) and `10-existing-table-reuse-analysis.json:72` both state the opposite requirement: *"A user who is both an advertiser and a creator is not expressible through users.role alone. If Creative needs the dual role, it must be derived from the presence of a `creative_creators` row, not from a new users column."* `[INFERENCE]` **These two prior positions are in direct conflict, and the conflict is unresolved.** If dual-role is real, a `creator` row in `roles` is not merely insufficient — assigning it would *overwrite* a user's existing role and revoke their buyer permissions, because `RolePermissionChecker` resolves a single slug (`RolePermissionChecker.php:60`, `:44`). This is the single most consequential authorization finding in this document. It is a `[OPEN DECISION]`, and it is **blocking** for any Creative role work.

### 5.6 Finance / settlement

| Resource | Action | Permission | Verdict |
|---|---|---|---|
| Earning | view all | `creative.earnings.view_all` | **NEW** |
| Earning | approve / accrue | `creative.earnings.approve` | **NEW** |
| Earning | reverse (compensating record) | `creative.earnings.reverse` | **NEW** |
| Payout | view all | `creative.payouts.view_all` | **NEW** |
| Payout | approve | `creative.payouts.approve` | **NEW** — separation of duties vs `creative.payouts.request` per `09-...json:611` |
| Payout | mark paid | `creative.payouts.pay` | **NEW** |
| Settlement | view | `creative.settlements.view` | **NEW** — see below |

`[INFERENCE]` `creative.settlements.*` deliberately mirrors the existing `settlements.view` / `settlements.approve` / `settlements.pay` string family so that Creative settlement endpoints are shaped like the existing ones — but the Creative strings must be **granted**, which the existing family demonstrably is not (§4).

`[OPEN DECISION]` **Who holds Creative financial authority?** There is no `finance`, `accountant` or `settlement` role in the system. The existing settlement routes are reachable only by `super-admin` (§4). Missing information: whether Creative finance is the same population as the existing settlement operators, a new role, or a subset of `admin`. `[INFERENCE]` Until this is answered, the `09-...json:611` separation-of-duties requirement (`requested_by != approved_by`) cannot be expressed as two permissions on two different roles — it would be two permissions on the same role, which is not separation of duties.

### 5.7 Actions that exist in the matrix but whose business rule is unknown

`[OPEN DECISION]` The following permissions are named because the **resource** is foreseeable, not because the **rule** is known. No limit, window, threshold, penalty or amount appears anywhere in this document, and none may be invented:

| Permission | Exactly what is missing |
|---|---|
| `creative.revisions.create` / `.request` | Whether a revision count is limited, whether a limit is per-round or per-project, and who sets it. No evidence exists in any artifact or in the codebase. |
| `creative.deliveries.accept` | Whether acceptance has a time window after submission, and what happens on expiry (auto-accept, escalate, or stay open). No evidence. |
| `creative.disputes.open` / `.resolve` | Whether a dispute window exists after acceptance, its length, and its preconditions. No evidence. |
| `creative.payouts.request` | The minimum payable amount, the payout cadence, and whether a creator may request below the minimum. No evidence. |
| `creative.fundings.create` | Whether escrow/funding is in Creative scope at all, and if so who funds and when funds are released. No evidence. |
| `creative.projects.cancel` | Who may cancel (client, creator, admin, or a combination), at which states, and whether any financial consequence follows. No evidence. |
| `creative.briefs.extend` | Whether a deadline may be extended, by whom, and how many times. No evidence. |
| `creative.onboarding.submit` | Whether creator onboarding is approval-gated (mirroring `PartnerApprovalController.php:23`) or open self-registration. No evidence. |
| `creative.reviews.create` | Whether a client may review after acceptance, whether the review is public, and whether it is moderated. No evidence. |

---

## 6. Weaknesses that affect a Creative implementation

Listed as findings. **Nothing here was fixed.** `AGENTS.md` §18 forbids opportunistic remediation, and `AGENTS.md` §12 forbids weakening or widening a guard to make something pass.

### 6.1 No policy layer

`[CURRENT FACT]` `app/Policies` does not exist; zero `Gate::` calls; zero `$this->authorize()` calls; zero controller constructor middleware. `[RECOMMENDATION]` Creative is the first bounded context that genuinely needs one — a project has a client, a creator, a reviewer, a project manager and a finance actor, and no single column expresses "may this actor act on this instance". Creative should introduce `app/Policies/Creative/*` and call `$this->authorize()` from its controllers, and the Creative team should add an architecture test asserting that every Creative controller action reaches a policy. `[INFERENCE]` This is additive: it does not require changing any existing route, so Rule 5 / `AGENTS.md` §13 are not engaged.

### 6.2 Ad-hoc ownership checks instead of resource-level authorization

`[CURRENT FACT]` Three cited inline `user_id`/`partner` comparisons (§2.7). `[INFERENCE]` Each is correct for a single-owner resource. None generalises. A Creative project has no `user_id`; it has a client user, a creator, and a set of reviewers, and "can this actor view it" is a membership question over a relationship table. Copying the `BookingController.php:116` pattern would produce a check that is either wrong (rejects legitimate participants) or absent (lets other participants through). `[RECOMMENDATION]` Do not copy it.

### 6.3 Free-text role column with no foreign key

`[CURRENT FACT]` `VARCHAR(50)`, no FK (§2.2). `[INFERENCE]` Consequences for Creative: (a) an invalid role value is storable and invisible; (b) a `creator` row can only ever be one of several things a user is, because the column holds one value; (c) `AdminUserController.php:61/105` already has to work around this by validating against `Role::pluck('slug')` at the application layer. `[RECOMMENDATION]` Creative should **not** widen `users.role` (that is a shared-table schema change, `AGENTS.md` §14) and should derive creator-ness from the existence of a creator row, per `10-existing-table-reuse-analysis.json:72`. Resolving this properly is `[OPEN DECISION]` §5.5.

### 6.4 The super-admin bypass asymmetry — preserve, do not fix

`[CURRENT FACT]` The bypass exists **only** in `PermissionMiddleware::handle()` (`:28-30`) and deliberately not in `RolePermissionChecker` (`RolePermissionChecker.php:31-36`). `[ARCHITECTURE DOCUMENT]` `ARCHITECTURE_RULES.md:43` and `AGENTS.md` §12 both require it be preserved and test-pinned until an explicit authorization change is approved.

`[RECOMMENDATION]` For Creative specifically this has a non-obvious implication that must be written into the Creative design: **a Creative policy check (`$this->authorize()`) does NOT get the super-admin bypass.** If Creative policies are added alongside the route middleware, then for a `super-admin` account the route middleware passes and the policy denies. The two layers disagree. `[INFERENCE]` That is not a Creative bug — it is the pre-existing asymmetry becoming visible, exactly as Rule 12 anticipates. The Creative team must decide, per action, whether the policy is written to mirror the bypass (and therefore become a second, un-pinned owner of the authorization algorithm, which Rule 12 forbids) or not (and therefore denies super-admins on some Creative actions). `[OPEN DECISION]` Missing information: an explicit authorization decision on this point. It must be settled before the first Creative controller is written, because retrofitting it is a behaviour change to a financial-adjacent surface.

### 6.5 No creator role, and the dual-role conflict

`[CURRENT FACT]` No `creator` role exists. `[INFERENCE]` And a `creator` row in `roles` is not a safe way to introduce one, for the reason given in §5.5. `[OPEN DECISION]` §5.5 is blocking.

### 6.6 No resource-level authorization primitive

`[CURRENT FACT]` The only authorization primitive that exists is `RolePermissionChecker::userHasPermission(User, string): bool` (`:42-45`) — a **role-level** question. There is no `can(User, $resource, $action)`, no ability model, and no policy. `[INFERENCE]` So every instance-level question a Creative context asks is currently unanswerable through the shared mechanism and must be answered by hand inside a controller. That is the single largest structural gap between the current model and what Creative requires.

### 6.7 Sanctum tokens that never expire

`[CURRENT FACT]` `config/sanctum.php:50` is `null`; tokens are issued with no abilities (§2.1). `[INFERENCE]` For Creative this matters more than for the existing surface, because a Creative token grants access to commercially sensitive material — briefs, proposals, negotiated amounts, source files and earnings — and there is currently **no** mechanism to expire it, revoke it proactively, or scope it. The only revocation path is deleting the `personal_access_tokens` row. `[OPEN DECISION]` Missing information: the required session lifetime and the revocation policy for Creative tokens. Note that setting a global `expiration` would change the behaviour of every existing token and every existing API contract, which `AGENTS.md` §13 forbids without explicit authorisation — so any Creative token lifetime must be decided as a **Creative-specific** concern, not a global config change.

### 6.8 Permission cache invalidation is seeder-only

`[CURRENT FACT]` `clearPermissionCache` is called from exactly two places, both seeders (`RolesAndPermissionsSeeder.php:156`, `ScreenProviderRoleSeeder.php:52`). `[INFERENCE]` A `role_permissions` change made at runtime — including by `app/Http/Controllers/Api/RoleController.php` — leaves the stale permission set cached for up to 3600 seconds. For Creative this means a revoked `creative.*` grant, or a suspended creator, would keep access for up to an hour. `[OPEN DECISION]` Missing information: whether Creative role/permission changes are permitted at runtime at all. If Creative permissions are seeded-only and never edited through the API, this weakness is not triggered and no change is needed; if there is any admin UI for them, invalidation must be added.

### 6.9 A route uses an unregistered middleware alias

`[CURRENT FACT]` `routes/api.php:717` — `Route::middleware(['auth:sanctum', 'admin'])->group(...)`, guarding `GET api/admin/currency-rates` and `POST api/admin/currency-rates/sync`. `bootstrap/app.php:18-20` registers exactly one alias, `permission`. There is no `admin` alias and no `AdminMiddleware` class (`app/Http/Middleware/` contains only `PermissionMiddleware.php`).

`[CURRENT FACT]` Verified at runtime: `app("admin")` throws `Illuminate\Contracts\Container\BindingResolutionException: Target class [admin] does not exist.`

`[INFERENCE]` Both routes therefore fail at middleware resolution on every request. `[CURRENT FACT]` No test references `admin/currency-rates` (`grep -rn "admin/currency-rates" tests/` → no matches), which is consistent with the defect never having been observed. `[INFERENCE]` This is unrelated to Creative and is **out of scope**; it is recorded here per `AGENTS.md` §18 because it is a genuine authorization-surface defect discovered during this audit, and because it demonstrates the failure mode Creative must avoid — an authorization control that appears to exist and does not.

### 6.10 Unauthenticated write endpoints

`[CURRENT FACT]` `routes/api.php:703` — `Route::apiResource('gallery', GalleryController::class)` is declared **outside** any `auth:sanctum` group (the preceding group closes at line 699). `php artisan route:list --json` shows `POST api/gallery`, `PUT|PATCH api/gallery/{gallery}` and `DELETE api/gallery/{gallery}` carry no `auth:sanctum` and no `permission:*`. `[CURRENT FACT]` 69 of 385 routes have neither an auth nor a permission middleware, and among them are further unauthenticated writes: `POST api/blogs` (line 106 area), `POST api/screen-categories` (`:217`), `POST api/admin/screen-categories` (`:218`).

`[INFERENCE]` This is a pre-existing security exposure unrelated to Creative. It is recorded, not fixed. `[RECOMMENDATION]` It matters to this audit for one reason: it establishes that "the route has middleware" is **not** a safe assumption in this codebase, and a Creative reviewer must verify the middleware of every proposed route rather than infer it.

---

## 7. What Creative must not do

`[RECOMMENDATION]` Consolidated, each traceable to a rule above:

1. **Do not create a second permission algorithm.** `ARCHITECTURE_RULES.md:43` (Rule 12) — one owner only. `10-existing-table-reuse-analysis.json:2115` already recorded this as `do_not_create`.
2. **Do not add a super-admin bypass anywhere new** — not in a policy, not in a Creative service, not in a route guard — unless the §6.4 `[OPEN DECISION]` is explicitly resolved and authorised.
3. **Do not ship a route that references an unseeded permission string.** §4.
4. **Do not write an unseeded role value.** §3. Validate role assignment against `Role::pluck('slug')` as `AdminUserController.php:61/105` does.
5. **Do not rely on `users.role` to express creator-ness** until §5.5 is resolved.
6. **Do not design around `tokenCan()`.** No abilities are issued (§2.1).
7. **Do not assume a new `auth:sanctum`-only surface gets Laravel's 401.** Creative routes will get `{"status":false,"message":"Unauthenticated."}` from `PermissionMiddleware` if a permission group is present. See artifact 14 §6.

---

## 8. Recommendation summary

`[RECOMMENDATION]` Creative authorization, in dependency order:

1. **Settle `[OPEN DECISION]` §5.5 first** (single-role vs dual-role). Nothing else in the role model can be built correctly until this is answered, because a `creator` row in `roles` can silently strip a user's buyer permissions.
2. **Seed, do not merely reference.** Every `creative.*` string in §5.2-§5.6 must be inserted by a seeder in the same change as the route that uses it. Add a test asserting every permission string referenced by a route is granted to at least one non-super-admin role — this test would have caught the `settlements.*` drift in §4.
3. **Add `app/Policies/Creative/*`** for instance-level questions, and call `$this->authorize()`. This is the first policy layer in the repository; keep it inside the Creative bounded context and do not retrofit it onto existing controllers (`AGENTS.md` §18).
4. **Reuse `RolePermissionChecker` unchanged** for all role-level questions, and do not touch `PermissionMiddleware`'s bypass. Add `creative.*` grants to existing roles where §5.1 says EXTEND; do not modify the permission algorithm, the middleware, the cache key format, or the TTL.
5. **Mirror, do not invent, the permission string grammar** (`section.action`), so `in_array` matching keeps working and the strings remain legible next to the existing 76.
6. **Decide the §6.4 policy-vs-bypass question in writing** before the first Creative controller exists.

`[INFERENCE]` Steps 1, 2 and 6 are prerequisites. Steps 3, 4 and 5 are additive and change no existing contract.

---

## 9. Discrepancies vs existing artifacts

Source wins. Each item below is a place where an existing `docs/audit/*` artifact is incomplete, wrong, or in conflict with the code.

| # | Existing artifact | Claim | Source reality | Classification |
|---|---|---|---|---|
| D1 | `09-creative-database-target-schema-blueprint.json:657` | *"roles (one new row: creator)"* | Safe **only** under a single-role-per-user assumption, which `09-...json:698` (OD-DB-08) and `10-...json:72` both contradict. A `creator` row overwrites any existing role and, because `RolePermissionChecker` resolves one slug (`RolePermissionChecker.php:60,44`), revokes the user's other permissions. | **Unresolved conflict inside the prior artifact set.** `[OPEN DECISION]` §5.5. |
| D2 | `09-...json:657` | *"role_permissions (new creative.* grant rows)"* | Correct in intent, but incomplete: it does not record that the existing `settlements.view` / `.approve` / `.pay` strings are already used on 12 routes and granted to **no** role (`grep -rn "settlements\." database/` → no matches). Creative is proposing to extend a permission namespace that is already broken in the same family. | New finding; not in any prior artifact. |
| D3 | `09-...json:611` | Separation of duties **MANDATORY** via `creative.payouts.request` vs `creative.payouts.approve` | Not currently expressible. There is no `finance` role, so both permissions would land on the same role. The requirement is stated as settled; the supporting role does not exist. | `[OPEN DECISION]` §5.6. |
| D4 | `02-current-architecture-assessment.md` §12 and root `AGENTS.md` §4 | baseline table and FK figures | RESOLVED by re-measurement on 2026-09-26: **73 distinct tables** (75 `Schema::create` calls; `pricing_peak_rules` and `pricing_weekend_rules` are each created twice) and **96 foreign-key definitions** (94 `->constrained()` + 2 legacy `->foreign()`). Root `AGENTS.md` §4 states 74 tables / 94 foreign keys: the 94 is the `->constrained()` count alone and the 74 overstates tables by the 2 duplicate creates. The earlier "91" in `01-current-backend-inventory.json` was an undercount and has been superseded. | Resolved by measurement; source wins. |
| D5 | `10-existing-table-reuse-analysis.json:104-124` | `role_permissions`: verdict EXTEND, "add creative.* grant rows" | Verified correct. `role_permissions` is the only permission store, the `UNIQUE(role_id, permission)` constraint (migration `:19`) composes cleanly with a new namespace, and `grantedPermissions()` needs no change. **This artifact agrees.** | No conflict. |
| D6 | `01-current-backend-inventory.json` | `middleware_classes: 1` | Verified correct: `app/Http/Middleware/` contains only `PermissionMiddleware.php`. | No conflict. |
| D7 | `10-...json:2114-2116` | `do_not_create: a creative_roles / creative_permissions / creative authorization resolver` | Verified correct and adopted by this artifact §7.1. `ARCHITECTURE_RULES.md:43` (Rule 12) requires it. | No conflict. |
| D8 | — | Not recorded anywhere | `users.status` (`database/migrations/2026_08_01_000001_add_status_and_last_login_to_users_table.php:12`, default `'active'`) exists but **nothing in the authorization path reads it** — not `PermissionMiddleware`, not `RolePermissionChecker`. A suspended or deactivated Creative account retains full access. | New finding. |
| D9 | — | Not recorded anywhere | `RolesAndPermissionsSeeder` (20 sections, 76 permissions) and `database/migrations/2026_08_01_000003_update_roles_and_permissions_data.php` (15 sections, 68 permissions) define **different** permission sets, and the latter renames role slugs in place. A reviewer reading only migrations derives the wrong set. The seeder is authoritative. | New finding. |
| D10 | — | Not recorded anywhere | `PermissionMiddleware.php:28` accepts **two** super-admin spellings (`super-admin`, `superadmin`) while `RolePermissionChecker` resolves one slug. `superadmin` is privileged only via the bypass and resolves to `[]` everywhere else. | New finding. |
| D11 | — | Not recorded anywhere | 24 seeded permissions are referenced by **no** route (see §4). | New finding. |
| D12 | — | Not recorded anywhere | `routes/api.php:717` uses an unregistered `admin` middleware alias; `app("admin")` throws `BindingResolutionException`. Two live routes are unreachable and untested. | New finding. |
| D13 | — | Not recorded anywhere | `RolePermissionChecker` is the single owner, but its **only** cache-invalidation callers are two seeders. Runtime permission edits via `app/Http/Controllers/Api/RoleController.php` do not invalidate, leaving a ≤3600s stale window. | New finding. |
| D14 | — | Not recorded anywhere | `User::hasPermission()` (`app/Models/User.php:80-82`) has **no production call site**. The model-level convenience helper is unused; only `PermissionMiddleware` consumes the checker in `app/`. | New finding. |

---

## 10. Open decisions

`[OPEN DECISION]` Consolidated. Each states exactly what information is missing. **None of these was resolved, guessed, or worked around in this document.**

| ID | Question | Exactly what is missing |
|---|---|---|
| OD-AUTH-01 | Can a `users` account be both buyer and creator? | A product/identity decision. It determines whether a `creator` row in `roles` is safe at all. Already flagged BLOCKING at `09-...json:698` (OD-DB-08) and `10-...json:72`; unresolved. **Blocks all Creative role work.** |
| OD-AUTH-02 | What is the buyer's canonical role slug — `client`, `buyer`, `advertiser` or `user`? | A naming decision plus a migration decision for existing `client`/`user` rows. All four values are attested in code; only `user` is a column default. |
| OD-AUTH-03 | Is "reviewer" a new `roles` row, or `staff`/`manager` with extra grants? | A staffing decision. The existing analogue uses `screens.edit` on a `manager`/`admin`, so there is no precedent for a dedicated reviewer role. |
| OD-AUTH-04 | Is "project manager" a new `roles` row, or the existing `manager`? | A staffing decision, plus an impact assessment on existing `manager` accounts if grants are widened. |
| OD-AUTH-05 | Who holds Creative financial authority? | An org decision. There is no `finance`/`accountant` role, and the existing settlement surface is super-admin-only by accident (§4). Until answered, the `09-...json:611` separation-of-duties requirement cannot be satisfied by two distinct roles. |
| OD-AUTH-06 | Do Creative **policies** grant the super-admin bypass, or not? | An explicit authorization decision, per `ARCHITECTURE_RULES.md:43`. The asymmetry is deliberate; extending it into a new policy layer is a behaviour decision, not an implementation detail (§6.4). |
| OD-AUTH-07 | What is the required lifetime and revocation policy for Creative tokens? | A security decision. A global `config/sanctum.php` change would alter every existing token and breach `AGENTS.md` §13, so a Creative-specific mechanism is required. |
| OD-AUTH-08 | Are Creative role/permission grants editable at runtime? | A product decision. If yes, cache invalidation on write is required (§6.8); if no, the 3600s TTL is harmless. |
| OD-AUTH-09 | Does `users.status` participate in Creative access control? | A decision plus implementation. The column exists and nothing reads it (D8). |
| OD-AUTH-10 | What is the rule for every item in §5.7? | Product rules that do not exist in any code or artifact: revision limits, delivery acceptance window, dispute window and preconditions, payout minimum and cadence, escrow/funding scope, who may cancel and at which states, deadline extension rules, creator onboarding gating, and post-completion review policy. **No value is proposed for any of them.** |
| OD-AUTH-11 | Must a seeded `creative.*` grant be given to a non-super-admin role, and is that testable? | A test-design decision. This is the guard that would have caught the `settlements.*` drift; §8 step 2 recommends it. |

---

## 11. Verification performed

`[CURRENT FACT]` Read-only. No file outside this artifact was created, modified or deleted; no test suite was run; no route, migration, seeder or class was written.

```text
Read:    AGENTS.md; docs/architecture/ARCHITECTURE_RULES.md;
         docs/audit/creative-marketplace/{01,09,10}
Commands: php artisan route:list --json
          app("admin") container-resolution probe (read-only, no state change)
          arithmetic count of the $sections map in RolesAndPermissionsSeeder.php:42-63
          enum-vs-constant diff of BookingStatus against App\Models\Booking
Source:  config/auth.php, config/sanctum.php, config/filesystems.php
         app/Support/Authorization/RolePermissionChecker.php
         app/Http/Middleware/PermissionMiddleware.php
         app/Http/Controllers/Controller.php
         app/Models/{User,Role,RolePermission,Booking,BookingMedia,Payment,Partner,ScreenProviderEnquiry}.php
         database/migrations/ (auth, roles, role_permissions, users, tokens, partner/screen-provider enquiries)
         database/seeders/{RolesAndPermissionsSeeder,ScreenProviderRoleSeeder}.php
Grep:    zero-result searches recorded as fact: app/Policies absent; Gate:: zero;
         authorize( zero; settlements. in database/ zero; admin/currency-rates in tests/ zero;
         hasPermission production call sites zero
```

`[CURRENT FACT]` No `ArchitectureRuleTest` run was performed for this artifact, because **no code was changed**; it is not required for a read-only documentation artifact (`AGENTS.md` §17, "Documentation-only changes").
