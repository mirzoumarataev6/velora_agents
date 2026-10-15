<?php

return [
    'brand' => 'Velora Agents',
    'loading' => 'Loading…',
    'learn_more' => 'Learn more',
    'back_home' => 'Back home',
    'toggle_theme' => 'Toggle theme',
    'skip_to_content' => 'Skip to content',
    'js_required' => 'Please enable JavaScript in your browser to use this page.',
    'error_generic' => 'Something went wrong. Please try again later.',
    'error_network' => 'No connection to the server. Check your internet connection.',
    // Generic fallback for a JSON request that hit a missing record
    // (ModelNotFoundException) — see bootstrap/app.php's exception
    // renderer. Never the raw "No query results for model [...]" text.
    'error_not_found' => 'The requested record was not found.',

    // HomeDestination's dead end: a role holding none of the sections it
    // knows how to land on.
    'no_access' => [
        'title' => 'No access yet',
        'message' => 'Your role does not have access to any section yet. Ask a team Owner to grant your role access to what you need.',
        'switch_heading' => 'Or switch to another team',
        'sign_out' => 'Sign out',
    ],
];
