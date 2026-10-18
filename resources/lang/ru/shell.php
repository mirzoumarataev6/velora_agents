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
        'dashboard' => 'Аналитика',
        'agents' => 'Агенты',
        'conversations' => 'Диалоги',
        'crm' => 'CRM',
        'projects' => 'Задачи',
        'bi' => 'Анализ данных',
        'leads' => 'Заявки',
        'settings' => 'Настройки',
        'team_section' => 'Команда',
        'logout' => 'Выйти',
        'open_menu' => 'Открыть меню',
        'toggle_theme' => 'Переключить тему',
        'collapse_sidebar' => 'Свернуть меню',
        'expand_sidebar' => 'Развернуть меню',
        'teams' => [
            'switcher_label' => 'Команда: :name',
            'switched' => 'Команда изменена',
            'roles' => [
                'owner' => 'Владелец',
                'admin' => 'Администратор',
                'operator' => 'Оператор',
                'viewer' => 'Наблюдатель',
            ],
        ],
    ],
];
