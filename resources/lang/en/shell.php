<?php

/**
 * PHASE 2 item 13: split out of agents.php's old 'nav' key. The dashboard
 * shell (sidebar, header, team switcher) is what every single dashboard
 * page loads — CRM, BI, Projects, Agents alike — so it used to drag the
 * REST of agents.php along with it: ~70KB per locale, ~210KB across the
 * three the client bundle always carries (see partials/i18n-bundle.blade.php),
 * on a page that has nothing to do with the agent builder. This file is
 * everything the shell itself actually uses; agents.php (minus this) is
 * now only requested by the agent pages that need it — see
 * AgentController's own $i18nExtraNamespaces additions.
 */
return [
    'nav' => [
        'dashboard' => 'Analytics',
        'agents' => 'Agents',
        'conversations' => 'Conversations',
        'crm' => 'CRM',
        'projects' => 'Tasks',
        'bi' => 'Data analysis',
        'leads' => 'Leads',
        'settings' => 'Settings',
        'team_section' => 'Team',
        'logout' => 'Log out',
        'open_menu' => 'Open menu',
        'toggle_theme' => 'Toggle theme',
        'collapse_sidebar' => 'Collapse menu',
        'expand_sidebar' => 'Expand menu',
        'teams' => [
            'switcher_label' => 'Team: :name',
            'switched' => 'Team switched',
            'roles' => [
                'owner' => 'Owner',
                'admin' => 'Admin',
                'operator' => 'Operator',
                'viewer' => 'Viewer',
            ],
        ],
    ],
];
