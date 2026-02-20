<?php

/**
 * Mining Manager Permissions
 *
 * 4-tier permission model:
 *   view     - Base access (help page only)
 *   member   - Corp miners: view own data, join events, view moon schedules
 *   director - Corp management: view all corp data, manage operations, analytics, reports, theft
 *   admin    - Full control: settings, tax management, delete actions, API, diagnostics
 *
 * Higher tiers inherit all lower tier permissions via controller logic.
 */
return [
    'view' => [
        'label' => 'mining-manager::permissions.view_label',
        'description' => 'mining-manager::permissions.view_description',
        'division' => 'financial',
    ],

    'member' => [
        'label' => 'mining-manager::permissions.member_label',
        'description' => 'mining-manager::permissions.member_description',
        'division' => 'financial',
    ],

    'director' => [
        'label' => 'mining-manager::permissions.director_label',
        'description' => 'mining-manager::permissions.director_description',
        'division' => 'financial',
    ],

    'admin' => [
        'label' => 'mining-manager::permissions.admin_label',
        'description' => 'mining-manager::permissions.admin_description',
        'division' => 'financial',
    ],
];
