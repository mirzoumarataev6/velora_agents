<?php

return [
    'brand' => 'Velora Agents',
    'loading' => 'Загрузка…',
    'learn_more' => 'Подробнее',
    'back_home' => 'На главную',
    'toggle_theme' => 'Переключить тему',
    'skip_to_content' => 'Перейти к содержимому',
    'js_required' => 'Для работы этой страницы необходимо включить JavaScript в браузере.',
    'error_generic' => 'Что-то пошло не так. Попробуйте позже.',
    'error_network' => 'Нет соединения с сервером. Проверьте интернет.',
    // Generic fallback for a JSON request that hit a missing record
    // (ModelNotFoundException) — see bootstrap/app.php's exception
    // renderer. Never the raw "No query results for model [...]" text.
    'error_not_found' => 'Запрошенная запись не найдена.',

    // Тупик HomeDestination: роль не имеет доступа ни к одному из разделов,
    // которые он умеет открывать.
    'no_access' => [
        'title' => 'Пока нет доступа',
        'message' => 'У вашей роли пока нет доступа ни к одному разделу. Попросите владельца команды выдать роли доступ к нужным разделам.',
        'switch_heading' => 'Или перейдите в другую команду',
        'sign_out' => 'Выйти',
    ],
];
