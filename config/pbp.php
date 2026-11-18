<?php

return [
    // Display name used across the UI.
    'name' => env('APP_NAME', 'PowerBrands Productions'),

    // Roles (stored in users.role). Order = most to least privileged.
    'roles' => [
        'admin' => 'Admin',
        'supervisor' => 'Supervisor',
        'operator' => 'Operator',
        'viewer' => 'Viewer (read-only)',
    ],

    // First admin account (created by `php artisan pbp:create-admin`).
    'admin_email' => env('PBP_ADMIN_EMAIL', 'andy@powerbrandsny.com'),
    'admin_name' => env('PBP_ADMIN_NAME', 'Andy Wu'),

    // Optional bcrypt hash of the first admin's initial password. Used once by
    // pbp:create-admin and then removed from .env by deploy.sh. Never store a
    // plaintext password here.
    'admin_initial_password_hash' => env('PBP_ADMIN_INITIAL_PASSWORD_HASH'),

    // GOOGLE SIGN-IN (future): when added, put the OAuth client settings in
    // config/services.php ('google' => [...]) and restrict sign-in to this domain.
    'google_allowed_domain' => env('PBP_GOOGLE_ALLOWED_DOMAIN', 'powerbrandsny.com'),

    // Times are stored in UTC and shown / scheduled in this zone.
    'site_timezone' => env('PBP_SITE_TIMEZONE', 'America/New_York'),

    // Where raw-material stock comes from: 'receiving' (Receiving tab, active now)
    // or 'distru' (Phase 2b, not connected yet).
    // Sidebar tabs switched on (rolled out one at a time). Override with PBP_MODULES in .env.
    // Labels stays OFF by default until Andy approves it on the preview site.
    // Andy, Nov 17: "no qc". The step-9 QC release / reject (second-person sign-off) is hidden and labels no longer
    // warn "not QC-released". The code and the qc_* data stay; set PBP_QC_RELEASE_STEP=true to bring it back.
    'qc_release_step' => (bool) env('PBP_QC_RELEASE_STEP', false),

    // Weekly Schedule tab (Andy, Nov 17). Switched on per site with PBP_MODULES=...,schedule (preview first).
    'compliance' => [
        // Never assignable / never "Completed by": anyone whose first name is Jack, plus these names or emails.
        'excluded' => env('PBP_COMPLIANCE_EXCLUDED', 'Jack'),
    ],

    'schedule' => [
        // Who can edit the schedule: "role:admin", "role:supervisor", "name:Jack" (full name or first name), "email:x@y".
        // Admins can also change this list on Schedule > People & setup (stored in Settings, wins over this default).
        'editors' => env('PBP_SCHEDULE_EDITORS', 'role:admin,name:Jenny,name:Leo,name:Jack'),
        // Labor dollars (hours x hourly rate) stay hidden until this is true.
        'show_labor' => (bool) env('PBP_SCHEDULE_SHOW_LABOR', false),
        'default_rate' => (float) env('PBP_SCHEDULE_DEFAULT_RATE', 20),
        'sections' => ['Going to lab', 'In lab', 'Pending'],   // fixed sections on the week page
        'steps' => ['Grind', 'Formulate', 'Fill', 'Cap', 'Label', 'Pack', 'Lab', 'Equipment / cleaning'],
    ],

    'modules' => env('PBP_MODULES', 'production,receiving,checklists,batchlog,labels,schedule,compliance'),
    // Tabs this release switches on whatever PBP_MODULES says (any format, missing or duplicate key). Turn one
    // off again with PBP_MODULES_OFF=schedule in .env.
    'modules_release_on' => ['schedule', 'compliance'],
    'modules_off' => env('PBP_MODULES_OFF', ''),

    // Preview / staging site only (preview.powerbrandsproductions.com). Live never sets these.
    // Both also require APP_ENV=staging.
    'preview' => (bool) env('PBP_PREVIEW', false),                       // banner + noindex header
    'preview_guest_login' => (bool) env('PREVIEW_GUEST_LOGIN', false),   // one-time /guest/{token} links

    'inventory_source' => env('PBP_INVENTORY_SOURCE', 'receiving'),

    // Google Drive filing. OFF until a service account + Shared Drive exist.
    'drive' => [
        'enabled' => (bool) env('PBP_DRIVE_ENABLED', false),
        // 'log' = write to the Laravel log only (testing); 'google' = Google Sheets API.
        'driver' => env('PBP_DRIVE_DRIVER', 'log'),
        'credentials_path' => env('PBP_DRIVE_CREDENTIALS'),   // service-account JSON (outside web root)
        'root_folder_id' => env('PBP_DRIVE_FOLDER_ID'),       // "PBNY Compliance Records" folder
        'max_attempts' => 10,
    ],

    // Nightly local backups (cron script deploy/pbp-backup.sh).
    'backup_dir' => '/var/backups/pbp',
];
