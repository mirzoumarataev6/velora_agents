<?php

return [

    'index' => [
        'title' => 'Диалоги · Velora',
        'heading' => 'Диалоги',
        'subheading' => 'Все переписки с клиентами по всем вашим агентам и каналам.',
        'search_placeholder' => 'Поиск по клиентам и сообщениям…',
        'all_agents' => 'Все агенты',
        'all_channels' => 'Все каналы',
        'date_from' => 'С',
        'date_to' => 'По',
        'invalid_date' => 'Введите реальную дату в формате ДД.ММ.ГГГГ.',
        'reset_filters' => 'Сбросить фильтры',
        'unread_badge' => 'непрочитано',
        'load_more' => 'Показать ещё',
        'loading' => 'Загрузка…',
        'results_count' => 'Диалогов: :count',
        'resize_handle' => 'Изменить ширину списка',
    ],

    'escalation' => [
        'signals' => [
            'frustration' => 'Клиент раздражён',
            'complaint' => 'Жалоба на сервис или товар',
            'explicit_request' => 'Просит позвать человека',
            'threatening_to_leave' => 'Угрожает уйти к конкурентам',
            'fallback' => 'Агент не смог ответить',
        ],
        'auto_summary' => [
            // Written by the pipeline's own detector rather than by the model,
            // so the owner sees why the handover fired without a summary having
            // to be generated for it.
            'abuse' => 'Клиент грубит ассистенту',
            'repeated_rudeness' => 'Клиент повторно грубит ассистенту',
            'asked_for_human' => 'Клиент просит позвать человека',
            'repeated_question' => 'Клиент несколько раз задаёт один и тот же вопрос',
        ],
        'notify' => [
            'title' => 'Клиенту нужен человек',
            'cta' => 'Откройте диалог и ответьте лично:',
        ],
    ],

    'search' => [
        'clear' => 'Очистить поиск',
        'next_match' => 'Следующее совпадение',
        'prev_match' => 'Предыдущее совпадение',
        'matches_in_chat' => ':count совп.',
        'empty_title' => 'Ничего не найдено',
        'empty_description' => 'Ни в одном сообщении нет «:term». Попробуйте другой запрос.',
    ],

    /* One line per filter, shown in the inbox's unopened thread pane.
     * The filters are the only non-obvious part of that screen, and the
     * pane is two thirds of it — so the space explains them instead of
     * staying blank. */
    'filter_help' => [
        'needs_human' => 'Ассистент передал разговор человеку — клиент просил оператора или разговор пошёл не так.',
        'has_lead' => 'Из переписки получилась заявка: есть имя, контакт и что человеку нужно.',
        'rated_down' => 'Клиент отметил ответ как неудачный. Единственный сигнал о неверном, но уверенном ответе.',
        'unread' => 'Пришло новое сообщение, которое вы ещё не открывали.',
    ],

    'filters' => [
        'all' => 'Все',
        'unread' => 'Непрочитанные',
        'needs_human' => 'Нужен человек',
        'has_lead' => 'С заявкой',
        'rated_down' => 'С 👎',
        'rated_up' => 'С 👍',
        'closed' => 'Закрытые',
    ],

    'status' => [
        'ai' => 'Отвечает агент',
        'human_takeover' => 'Отвечаете вы',
        'closed' => 'Закрыт',
    ],

    'roles' => [
        'user' => 'Клиент',
        'assistant' => 'Агент',
        'operator' => 'Вы',
        'system' => 'Система',
    ],

    'thread' => [
        'placeholder_title' => 'Выберите диалог',
        'placeholder_description' => 'Откройте переписку слева, чтобы прочитать всю историю и ответить лично.',
        'started' => 'Начат :date',
        'reply_placeholder' => 'Напишите ответ от себя…',
        'send' => 'Отправить',
        'sending' => 'Отправляем…',
        'take_over' => 'Ответить самому',
        'hand_back' => 'Вернуть агенту',
        'close' => 'Закрыть диалог',
        'reopen' => 'Открыть снова',
        'open_agent' => 'Открыть агента',
        'knowledge_used' => 'Использованные знания',
        'knowledge_unconfirmed' => 'слабое совпадение',
        'leads_heading' => 'Заявки из диалога',
        'view_in_leads' => 'Открыть в «Заявках»',
        'rating_up' => '👍',
        'rating_down' => '👎',
        'rating_up_hint' => 'Клиент оценил',
        'rating_down_hint' => 'Клиенту не понравился этот ответ',
        'voice_note' => 'голосовое',
        'no_channel' => 'К этому диалогу не подключён канал, ответить нельзя.',
        'back' => 'К списку',
    ],

    'empty' => [
        'title' => 'Диалогов пока нет',
        'description' => 'Как только клиент напишет в подключённый Telegram, WhatsApp или Instagram, переписка появится здесь.',
        'filtered_title' => 'Ничего не найдено',
        'filtered_description' => 'Попробуйте другого агента, канал или другой запрос.',
    ],

    'toasts' => [
        'sent' => 'Ответ отправлен',
        'taken_over' => 'Теперь вы отвечаете в этом диалоге',
        'handed_back' => 'Агент снова отвечает сам',
        'closed' => 'Диалог закрыт',
        'reopened' => 'Диалог открыт снова',
        'failed' => 'Не получилось. Попробуйте ещё раз.',
        'network' => 'Проблема с сетью — попробуйте ещё раз.',
    ],

    'errors' => [
        'no_channel' => 'К этому диалогу не подключён канал для ответа.',
        'delivery_failed' => 'Сообщение не удалось доставить клиенту. Оно не сохранено — попробуйте ещё раз.',
        'outside_window' => 'Прошло больше 24 часов с последнего сообщения клиента — WhatsApp и Instagram не разрешают писать первыми. Сообщение не отправлено и не сохранено. Дождитесь, пока клиент напишет снова.',
    ],

];
