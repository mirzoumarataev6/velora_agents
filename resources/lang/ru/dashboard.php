<?php

return [
    'title' => 'Аналитика — Velora Agents',
    'heading' => 'Аналитика',
    'subheading' => 'Что ваши ассистенты сделали за вас.',

    'greeting' => 'Добрый день, :name',

    'details' => [
        'title' => 'Подробная аналитика',
        'description' => 'Воронка, скорость ответов, спрос и работа агентов',
    ],

    'units' => [
        'seconds' => 'с',
        'ms' => 'мс',
    ],

    'range' => [
        'label' => 'Период',
    ],

    // The trend pill's accessible name. The pill itself shows only "18%" — the
    // arrow beside it is a decorative glyph that a screen reader skips, so the
    // direction has to be spelled out here or the number means nothing.
    'trend' => [
        'up' => 'на :percent% больше, чем в предыдущем периоде',
        'down' => 'на :percent% меньше, чем в предыдущем периоде',
        'flat' => 'без изменений к предыдущему периоду',
    ],

    'agent_filter' => [
        'label' => 'Агент',
        'all' => 'Все агенты',
    ],

    'empty' => [
        'title' => 'Данных пока нет',
        'description' => 'Данные появятся здесь, когда наберётся достаточно диалогов. Подключите канал и дайте ассистенту поработать — первые цифры появятся уже после нескольких переписок.',
        'cta' => 'К агентам',
    ],
    'no_data' => 'Данные появятся здесь, когда наберётся достаточно диалогов.',

    'summary' => [
        'active_conversations' => 'Активные диалоги',
        'active_conversations_hint' => 'Не закрыты и были активны за последние 24 часа',
        'leads_today' => 'Заявки сегодня',
        'leads_week_hint' => 'Заявки с начала недели; линия — по дням за период',
        'leads_week' => 'Заявки за неделю',
        'conversion' => 'Конверсия в заявку',
        'conversion_hint' => 'Доля диалогов, из которых получилась заявка',
        'response_time' => 'Среднее время ответа',
        'response_time_hint' => 'Сколько ассистент тратит на один ответ',
        'messages' => 'Сообщения',
        'messages_hint' => 'Все сообщения за выбранный период',
        'of_total_conversations' => ':count диалог всего|:count диалога всего|:count диалогов всего',
    ],

    'after_hours' => [
        'title' => 'Работает, пока вы спите',
        'description' => 'Сообщения и заявки, обработанные вне рабочего времени — ночью с :from:00 до :to:00 и в выходные.',
        'messages' => 'сообщений обработано',
        'leads' => 'заявок принято',
        'share' => ':percent% всех сообщений',
        'empty' => 'Пока ни одного ночного обращения — как только они появятся, вы увидите их здесь.',
    ],

    // The card that replaced «Работает, пока вы спите». Every string here is a
    // sentence the owner can act on, not a number they have to interpret.
    'highlight' => [
        'eyebrow' => 'Что сделать сейчас',
        'price_approvals' => 'Клиент ждёт вашего решения по цене — :count шт.',
        'price_approvals_action' => 'Согласовать цену',
        'thumbs_down' => 'Клиенты отметили :count ответ(ов) как неудачные',
        'thumbs_down_hint' => 'Откройте диалог и посмотрите, что ассистент ответил неверно.',
        'thumbs_down_action' => 'Посмотреть диалоги',
        'unanswered' => 'Спросили :count раз, а ответа не нашлось: «:subject»',
        'unanswered_hint' => 'Добавьте это в базу знаний — и ассистент ответит сам в следующий раз.',
        'unanswered_action' => 'Пополнить базу знаний',
        'product_gap' => 'Товар «:subject» искали :count раз и не нашли в каталоге',
        'product_gap_hint' => 'Похоже на спрос, который вы пока не закрываете.',
        'product_gap_action' => 'Открыть каталог',
        'open_orders' => 'Открытых заявок: :count',
        'open_orders_hint' => 'Разберите их, пока клиенты ждут.',
        'open_orders_action' => 'К заявкам',
        'clear_title' => 'Всё под контролем',
        'clear' => 'Разбирать нечего: заявки закрыты, вопросов без ответа нет, жалоб на ответы тоже.',
        'after_hours' => 'За период :count сообщений обработано ночью и в выходные — :percent% всех.',
    ],

    'messages_chart' => [
        'title' => 'Сообщения',
        'range_7' => '7 дней',
        'range_30' => '30 дней',
        'total' => 'Всего за период',
        'empty' => 'За этот период сообщений не было.',
        'insight_channel' => 'Больше всего переписок принёс :channel — :percent% всех сообщений.',
        'insight_peak' => 'Пик пришёлся на :day — :count сообщений за день.',
    ],

    'channels' => [
        'telegram' => 'Telegram',
        'whatsapp' => 'WhatsApp',
        'greenapi' => 'WhatsApp (Green API)',
        'instagram' => 'Instagram',
        'playground' => 'Песочница',
    ],

    'pending' => [
        'title' => 'Требует внимания',
        'open_orders' => 'Открытые заявки',
        'open_orders_hint' => 'Новые и в работе',
        'price_approvals' => 'Согласование цены',
        'price_approvals_hint' => 'Клиент ждёт вашего решения',
        'view' => 'Открыть',
        'clear' => 'Всё разобрано — открытых заявок нет.',
        'negative_ratings' => 'Плохие оценки',
        'negative_ratings_hint' => 'Ответы, которые клиенты отметили как неудачные',
        'in_progress' => 'Заявки в работе',
        'in_progress_hint' => 'Вы их уже взяли, но ещё не закрыли',
        'view_all' => 'Все заявки',
    ],

    'funnel' => [
        'title' => 'Воронка',
        // The one sentence the widget was missing: what it is, in plain words.
        'description' => 'Как диалоги превращаются в заявки и подтверждённые продажи.',
        'conversations' => 'Диалоги начаты',
        'conversations_hint' => 'Все переписки с клиентами за всё время',
        'leads' => 'Заявки созданы',
        'leads_hint' => 'Диалоги, в которых клиент оставил заказ или контакт',
        'confirmed' => 'Продажи закрыты',
        'confirmed_hint' => 'Заявки, взятые в работу или закрытые',
        // Neutral, analytical framing — like Amplitude or Mixpanel: this
        // reports the conversion rate INTO this stage, not a loss. A customer
        // not converting at a step is what happened, not a failure.
        'conversion' => 'конверсия на этом этапе: :percent%',
        'of_all' => ':percent% от диалогов',
    ],

    'ratings' => [
        'title' => 'Оценка ответов',
        'description' => 'Клиенты оценивают ответы ассистента прямо в Telegram. Это единственный сигнал, который ловит уверенный, но неверный ответ — то, чего не видит ни одна другая метрика на этой странице.',
        'telegram_only' => 'Telegram',
        'score' => 'Ответов понравилось',
        'total' => 'оценок за период',
        'up' => 'Понравилось',
        'down' => 'Не понравилось',
        'trend_title' => 'Как менялось по дням',
        'worst_title' => 'Последние ответы с 👎',
        'view_negative' => 'Все негативные оценки',
        'view_positive' => 'Все позитивные оценки',
        'open_conversation' => 'Открыть диалог',
        'no_negative' => 'Ни одного 👎 за период — клиентов всё устроило.',
        'empty_title' => 'Оценок пока нет',
        'empty' => 'Оценок пока нет. Они появятся, когда клиенты начнут отмечать ответы в Telegram.',
        'empty_how_title' => 'Как это работает',
        'empty_how' => [
            'Под ответом ассистента в Telegram появляются кнопки 👍 и 👎.',
            'Кнопки показываются не под каждым ответом, а время от времени — чтобы не надоедать клиенту.',
            'Оценить можно один раз; после этого кнопки исчезают.',
        ],
    ],

    'response_trend' => [
        'title' => 'Скорость ответа',
        'description' => 'Среднее время одного ответа по дням — ассистент ускоряется или замедляется.',
        'empty' => 'Пока нечего измерять — время появится после первых ответов.',
        'cell' => ':day — :value',
        'no_replies' => 'ответов не было',
    ],

    'leads_status' => [
        'title' => 'Заявки по статусам',
        'description' => 'Где сейчас находятся все заявки.',
        'empty' => 'Заявок пока нет.',
        'total' => 'Всего заявок',
        'statuses' => [
            'new' => 'Новые',
            'in_progress' => 'В работе',
            'awaiting_price_approval' => 'Ждут согласования цены',
            'done' => 'Завершённые',
        ],
    ],

    'agents_compare' => [
        'title' => 'Нагрузка по агентам',
        'description' => 'Кто из ассистентов ведёт больше переписок за период.',
        'messages' => ':count сообщ.',
        'leads' => ':count заявок',
        'share' => ':percent% нагрузки',
    ],

    'heatmap' => [
        'title' => 'Когда пишут клиенты',
        'description' => 'Объём сообщений по дням недели и часам.',
        'timezone_label' => 'Время: Душанбе (UTC+5)',
        'less' => 'меньше',
        'more' => 'больше',
        'days' => ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'],
        'cell' => ':day, :hour:00 — :count сообщ.',
    ],

    'unanswered' => [
        'title' => 'Вопросы без ответа',
        'description' => 'О чём спрашивают, а ассистенту нечего ответить. Добавьте это в базу знаний или в каталог.',
        'empty' => 'Ассистент ответил на всё. Вопросы без ответа появятся здесь.',
        'times' => ':count раз',
        'reasons' => [
            'catalog_miss' => 'нет в каталоге',
            'knowledge_miss' => 'нет в базе знаний',
            'provider_down' => 'сбой сервиса',
        ],
    ],

    'products' => [
        'title' => 'О чём спрашивают чаще всего',
        'description' => 'Запросы к каталогу — независимо от того, дошло ли дело до заказа.',
        'empty' => 'Пока никто не спрашивал о товарах.',
        'asked' => ':count запрос(ов)',
        'miss_rate' => 'не найдено в :percent%',
    ],
];
