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

    // Moon Manager capability (standalone, not a tier)
    'moon_manager_label' => 'Moon Manager',
    'moon_manager_description' => 'Access the Moon Extraction Planner (assign, move, and auto-fill planned moon pulls across refineries to stagger arrivals), Moon Analytics and Find Moons. Directors and admins also have this access.',
    'moon_finder_label' => 'Moon Finder',
    'moon_finder_description' => 'Use Find Moons on the Extraction Simulator: search every scanned moon by location, composition, value and quality, and see better moons of the same class nearby. Directors and moon managers have this access as well.',

    // Admin tier
    'admin_label' => 'Admin',
    'admin_description' => 'All Director permissions plus: manage settings, calculate/manage taxes, generate tax codes, delete data, generate/export reports, resolve theft incidents, API access, diagnostics.',
];
