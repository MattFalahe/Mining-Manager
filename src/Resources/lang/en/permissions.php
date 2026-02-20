<?php

return [
    // Base access
    'view_label' => 'View Mining Manager',
    'view_description' => 'Base access to the Mining Manager plugin. Grants access to the Help page only.',

    // Member tier
    'member_label' => 'Member',
    'member_description' => 'View own mining data, own taxes and tax codes, join/leave events, view moon schedules. Read-only access to personal information.',

    // Director tier
    'director_label' => 'Director',
    'director_description' => 'All Member permissions plus: view all corporation data, process ledger, create/edit events, update moon data, view analytics/reports/theft incidents, verify wallet payments.',

    // Admin tier
    'admin_label' => 'Admin',
    'admin_description' => 'All Director permissions plus: manage settings, calculate/manage taxes, generate tax codes, delete data, generate/export reports, resolve theft incidents, API access, diagnostics.',
];
