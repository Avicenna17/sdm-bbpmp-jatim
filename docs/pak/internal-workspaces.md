# Internal Module Workspace Architecture — Corrective Phase 01

Status: implemented application-layer correction to approved Final Corrective Plan v2. This document supersedes the internal multi-panel design in the original Phase 01 plan and historical implementation reports. Database/domain contracts and all PENDING business rules remain unchanged.

## Panel, identity and routes

One internal Filament panel `admin`, one `/admin/login`, one web session and one user/person identity. Namespace `App\Filament\Pak` remains. No tenancy, tenant model, session `active_module`, Referer context, or Phase 02 features. `/pegawai` is a future separate employee panel, not implemented. KGB is not registered or displayed.

| Route | Purpose |
| --- | --- |
| `/admin` | Dispatch to authorized module home or selector |
| `/admin/modules` | Authorized module cards; single module redirects home |
| `/admin/manage-data` | Existing SDM home (ManageData page generator) |
| `/admin/pak` | PAK home (Dashboard page generator) |
| `/admin/pak/employees` | Employee foundation |
| `/admin/pak/functional-categories` | JF categories |
| `/admin/pak/functional-levels` | JF levels |
| `/admin/pak/functional-positions` | Functional positions |
| `/admin/pak/functional-position-levels` | JF–level mappings |
| `/admin/pak/rule-sets` | Versioned metadata only |
| `/admin/pak/cases` | Draft cases and snapshot |
| `/admin/pak/audit` | Read-only audit |
| `/pak`, `/pak/login`, `/pak/*` | 404; no compatibility redirect |

SDM page/resource/download URLs remain unchanged. Existing granular download authorization remains in place. Page/resource generators supply homes and navigation URLs. PAK resource detail/create/edit routes inherit their resource prefix.

## Access and landing

`InternalModuleAccess` is a small explicit registry of SDM/PAK pages and resources, required module permissions, homes and navigation groups. It is not a generic module framework. New internal routes must be classified deliberately; unknown authenticated panel routes default-deny. Logout and Filament's home dispatcher are explicit neutral exceptions.

| Effective permission / account | Internal access | Default landing |
| --- | --- | --- |
| Active + period.view only (default Admin SDM/Viewer) | SDM only; PAK denied | ManageData |
| Active + pak.access only (default Admin PAK) | PAK only; SDM denied | PAK Dashboard |
| Active + both (Super Admin explicitly seeded) | Both, with granular permissions | Module selector |
| Active, neither (default Pegawai only) | Denied | 403/login validation |
| Pegawai plus internal permissions | Follows effective permissions | 1 or >1 resolver |
| Inactive | Denied; session revoked on protected request | No internal access |

Role names are not gates. Module permission never replaces action/resource authorization. Existing PAK service/policy/query checks remain. Super Admin has explicit permissions, no global bypass. User–Person mapping, account deactivate/reactivate, retained audit, DUK whitelist, verified histories, JF mappings, permanent rule lock and case snapshot are unchanged.

Login response consumes intended URL once. It accepts only same-origin registered GET routes classified as a domain page/resource, with active module access and page/resource authorization. Record routes resolve via the resource query and check view/edit permission for that record. Missing/unauthorized records fall back. External, malformed, unknown, auth, retired and neutral routes are not accepted as intended destinations. Destination middleware/page authorization runs again. No accepted intended URL means zero modules denied, one home, multiple selector.

## Workspace navigation and Livewire

A custom Filament NavigationBuilder preserves existing groups inside the current workspace. Module cards and Ganti Modul appear only for authorized modules; Ganti Modul requires more than one. The selector does not show a combined domain sidebar.

Supported Livewire `current()` supplies the verified rendering component instance, mapped exactly against the registry. Normal HTTP can resolve the registered route after the component stack is popped during layout rendering. Livewire requests with unknown component context receive no domain navigation; there is no global snapshot parsing, Referer or mutable active-module session. Two tabs therefore do not overwrite each other's workspace.

Persistent `EnsureInternalModuleAccess` checks the original registered route through Filament/Livewire middleware. Active-account middleware is ordered before authentication so an inactive authenticated user is logged out consistently. The existing resource/action checks remain mandatory after module middleware. The installed Livewire update URI is obtained using `Livewire::getUpdateUri()` rather than assuming a route name.

## Seeder

`PakPermissionSeeder` explicitly grants all currently registered `pak.*` permissions for guard web to Super Admin on every run. Additive/idempotent; preserves other role permissions. Existing Admin PAK customization is not reset. No automatic PAK grant to Admin SDM/Internal Viewer, user role assignment, status change or User–Person remapping. Application database seeding is not performed by this corrective implementation; test seeding runs only in isolated SQLite.

## Verification and manual UAT

Automated tests cover role matrix, direct/mixed permissions, 0/1/>1 landing, login intended routes and record authorization, granular direct URL denial, selector/navigation visibility, Ganti Modul, component context across two workspaces, real HTTP Livewire refresh after another workspace request, permission revocation, old route 404, unknown internal route rejection, explicit/idempotent seeder and unchanged account metadata. Existing PAK and SDM suites remain required.

Manual UAT before release:
- Review/approve the working-tree change; no final commit is made automatically.
- In staging, explicitly apply the permission seeder through the normal operational process; verify existing users/role customizations and mapping are preserved. No schema change is needed.
- Log in with SDM-only, PAK-only, Super Admin, Pegawai-only, mixed role and inactive accounts. Check intended deep links and logout/login.
- Open SDM and PAK in two browser tabs, switch using Ganti Modul and verify no re-login, sidebar isolation, responsive cards/sidebar and normal back/bookmark behavior.
- Exercise a Livewire modal/table/action in each workspace; revoke permissions or deactivate from another session and confirm access is denied on subsequent requests.
- Check public dashboard, import, publish, export, templates and independent Peta Jabatan publications with representative staging data.
- Confirm old /pak routes return 404 and existing SDM bookmarks still work. Confirm production route/config caches are refreshed through the normal release process after approval.

No business PENDING decision is resolved. BR-SKP-008, BR-PERIOD-003, BR-APPOINT-008, BR-KJ-005 and BR-BAL-003 remain pending, along with simultaneous/Plt/Plh position requirements. No calculation, warning, portal or document generator is introduced.
