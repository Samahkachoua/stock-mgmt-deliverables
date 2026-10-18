<?php

return [
    'tagline' => 'WMS Core',
    'skip_to_content' => 'Skip to content',
    'select_warehouse' => 'Select warehouse',
    'language' => 'Language',
    'theme' => 'Theme',
    'theme_light' => 'Light',
    'theme_dark' => 'Dark',
    'theme_system' => 'System',
    'theme_invalid' => 'Choose Light, Dark or System.',
    'user_menu' => 'Account menu',
    'sign_out' => 'Sign out',
    'no_warehouses_title' => 'No warehouse assigned',
    'no_warehouses_body' => 'Your account is not assigned to any warehouse yet. Ask your administrator to add you to one.',
    'forbidden_title' => 'You don’t have access to this page',
    'forbidden_body' => 'Your account isn’t allowed to open this page. Ask your administrator if you need it.',
    'go_to_start' => 'Go to start page',

    // Loading states (CLAUDE.md "Loading states"): shared by every form, list and export.
    'loading' => [
        'saving' => 'Saving…',
        'list' => 'Loading…',
        'preparing_file' => 'Preparing file…',
    ],

    // Error pages (resources/views/errors). Shown even when the app is failing.
    'errors' => [
        'home' => 'Go to the start page',
        'reference' => 'Reference: :reference',
        'back' => 'Go back',
        'not_found' => ['title' => 'Page not found', 'body' => 'This address doesn’t lead anywhere. It may have been typed wrongly, or the page was moved.'],
        'expired' => ['title' => 'The page expired', 'body' => 'You were away for a while, so the page needs to be loaded again. Go back and try once more nothing was saved.'],
        'too_many' => ['title' => 'Too many tries', 'body' => 'Please wait a minute, then try again.'],
        'server' => ['title' => 'Something went wrong', 'body' => 'The problem has been recorded. Try again in a moment; if it keeps happening, tell your administrator.'],
        'maintenance' => ['title' => 'Back in a few minutes', 'body' => 'The system is being updated. Please try again shortly.'],
    ],
];
