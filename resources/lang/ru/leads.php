<?php

return [
    'index' => [
        'title' => 'Заявки — Velora Agents',
        'heading' => 'Заявки',
        'subheading' => 'Заказы и запросы, которые ваши ассистенты собрали от клиентов.',
        'all_agents' => 'Все агенты',
        'chat_only' => 'только чат',
        'chat_only_hint' => 'Клиент не оставил номер — заказ принят, связаться можно только в чате',
        'no_name' => 'Без имени',
        'items_label' => 'Хочет',
        'note_label' => 'Заметка',
        'delete' => 'Удалить заявку',
        'updated' => 'Заявка обновлена.',
        'deleted' => 'Заявка удалена.',
        'action_failed' => 'Что-то пошло не так. Попробуйте ещё раз.',
        'network_error' => 'Ошибка сети. Попробуйте ещё раз.',
        'delete_confirm' => 'Удалить эту заявку? Действие необратимо.',
        'empty_title' => 'Заявок пока нет',
        'empty_description' => 'Когда клиент оформляет заказ или оставляет контакты, ассистент записывает это здесь.',
        'empty_filtered_title' => 'Ничего не найдено',
        'empty_filtered_description' => 'Попробуйте другой запрос, статус или агента.',
        'search_placeholder' => 'Поиск по имени, контакту и заказу…',
        'search_results' => 'Результаты поиска: «:term»',
        'search_clear' => 'Сбросить',
    ],

    'filters' => [
        'all' => 'Все',
        'new' => 'Новые',
        'in_progress' => 'В работе',
        'done' => 'Готово',
    ],

    'status' => [
        'new' => 'Новая',
        'in_progress' => 'В работе',
        'done' => 'Готово',
    ],

    'source' => [
        'telegram' => 'Telegram',
        'whatsapp' => 'WhatsApp',
        'instagram' => 'Instagram',
        'playground' => 'Песочница',
    ],

    'bell' => [
        'label' => 'Уведомления',
        'title' => 'Новые заявки',
        'mark_seen' => 'Отметить прочитанными',
        'empty' => 'Заявок пока нет.',
        'no_name' => 'Без имени',
        'view_all' => 'Все заявки',
    ],

    'notify' => [
        'heading' => 'Уведомления о заявках в Telegram',
        'description' => 'Привяжите свой личный Telegram, чтобы получать сообщение сразу при новой заявке.',
        'telegram_required' => 'Сначала подключите Telegram-бота для этого агента (вкладка «Каналы»), затем привяжите здесь свой Telegram.',
        'linked_as' => 'Уведомления приходят на',
        'unlink' => 'Отвязать',
        'link_button' => 'Привязать мой Telegram',
        'waiting' => 'Ожидаем, пока вы нажмёте «Старт» в Telegram…',
        'open_telegram' => 'Открыть Telegram',
        'unlink_confirm' => 'Перестать присылать уведомления о заявках в этот чат Telegram?',
        'unlink_confirm_inline' => 'Отвязать?',
        'unlink_yes' => 'Да',
        'unlink_no' => 'Нет',
        'toasts' => [
            'linked' => 'Telegram привязан — вы будете получать уведомления о заявках.',
            'unlinked' => 'Telegram отвязан.',
            'failed' => 'Что-то пошло не так. Попробуйте ещё раз.',
            'network' => 'Ошибка сети. Попробуйте ещё раз.',
        ],
    ],
];
