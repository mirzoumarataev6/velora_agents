<?php

return [

    'index' => [
        'title' => 'Муколамаҳо · Velora',
        'heading' => 'Муколамаҳо',
        'subheading' => 'Ҳамаи сӯҳбатҳо бо муштариён аз рӯи ҳамаи агентҳо ва каналҳои шумо.',
        'search_placeholder' => 'Ҷустуҷӯ аз рӯи муштариён ва паёмҳо…',
        'all_agents' => 'Ҳамаи агентҳо',
        'all_channels' => 'Ҳамаи каналҳо',
        'date_from' => 'Аз',
        'date_to' => 'То',
        'reset_filters' => 'Тоза кардани филтрҳо',
        'unread_badge' => 'хонданашуда',
        'load_more' => 'Боз нишон додан',
        'loading' => 'Боркунӣ…',
        'results_count' => 'Муколамаҳо: :count',
        'resize_handle' => 'Тағйири паҳноии рӯйхат',
    ],

    'escalation' => [
        'signals' => [
            'frustration' => 'Муштарӣ норозӣ аст',
            'complaint' => 'Шикоят аз хизматрасонӣ ё мол',
            'explicit_request' => 'Одамро талаб мекунад',
            'threatening_to_leave' => 'Таҳдиди рафтан ба рақибон',
            'fallback' => 'Агент ҷавоб дода натавонист',
        ],
        'auto_summary' => [
            // Written by the pipeline's own detector rather than by the model,
            // so the owner sees why the handover fired without a summary having
            // to be generated for it.
            'abuse' => 'Муштарӣ ба ассистент дағалӣ мекунад',
            'repeated_rudeness' => 'Муштарӣ такроран дағалӣ мекунад',
            'asked_for_human' => 'Муштарӣ одами зиндаро талаб мекунад',
            'repeated_question' => 'Муштарӣ якчанд бор як саволро такрор мекунад',
        ],
        'notify' => [
            'title' => 'Ба муштарӣ одам лозим аст',
            'cta' => 'Муколамаро кушоед ва шахсан ҷавоб диҳед:',
        ],
    ],

    'search' => [
        'clear' => 'Тоза кардани ҷустуҷӯ',
        'next_match' => 'Мутобиқати навбатӣ',
        'prev_match' => 'Мутобиқати қаблӣ',
        'matches_in_chat' => ':count мутобиқат',
        'empty_title' => 'Чизе ёфт нашуд',
        'empty_description' => 'Дар ҳеҷ паём «:term» нест. Дархости дигарро санҷед.',
    ],

    'filters' => [
        'all' => 'Ҳама',
        'unread' => 'Хонданашуда',
        'needs_human' => 'Одам лозим аст',
        'has_lead' => 'Бо дархост',
        'closed' => 'Пӯшида',
    ],

    'status' => [
        'ai' => 'Агент ҷавоб медиҳад',
        'human_takeover' => 'Шумо ҷавоб медиҳед',
        'closed' => 'Пӯшида',
    ],

    'roles' => [
        'user' => 'Муштарӣ',
        'assistant' => 'Агент',
        'operator' => 'Шумо',
        'system' => 'Система',
    ],

    'thread' => [
        'placeholder_title' => 'Муколамаро интихоб кунед',
        'placeholder_description' => 'Аз тарафи чап сӯҳбатро кушоед, то таърихи пурраро хонед ва шахсан ҷавоб диҳед.',
        'started' => 'Оғоз :date',
        'reply_placeholder' => 'Ҷавоби худро нависед…',
        'send' => 'Фиристодан',
        'sending' => 'Фиристода истодааст…',
        'take_over' => 'Худам ҷавоб медиҳам',
        'hand_back' => 'Ба агент баргардонед',
        'close' => 'Муколамаро пӯшидан',
        'reopen' => 'Аз нав кушодан',
        'open_agent' => 'Кушодани агент',
        'knowledge_used' => 'Донишҳои истифодашуда',
        'knowledge_unconfirmed' => 'мутобиқати заиф',
        'leads_heading' => 'Дархостҳо аз муколама',
        'view_in_leads' => 'Дар «Дархостҳо» кушоед',
        'voice_note' => 'овозӣ',
        'no_channel' => 'Ба ин муколама канал пайваст нашудааст, ҷавоб додан имконнопазир аст.',
        'back' => 'Ба рӯйхат',
    ],

    'empty' => [
        'title' => 'Ҳоло муколама нест',
        'description' => 'Ҳамин ки муштарӣ ба Telegram, WhatsApp ё Instagram-и пайвастшуда нависад, сӯҳбат дар ин ҷо пайдо мешавад.',
        'filtered_title' => 'Чизе ёфт нашуд',
        'filtered_description' => 'Агенти дигар, канали дигар ё дархости дигарро санҷед.',
    ],

    'toasts' => [
        'sent' => 'Ҷавоб фиристода шуд',
        'taken_over' => 'Акнун шумо дар ин муколама ҷавоб медиҳед',
        'handed_back' => 'Агент боз худаш ҷавоб медиҳад',
        'closed' => 'Муколама пӯшида шуд',
        'reopened' => 'Муколама аз нав кушода шуд',
        'failed' => 'Нашуд. Боз як бор кӯшиш кунед.',
        'network' => 'Мушкилии шабака — боз кӯшиш кунед.',
    ],

    'errors' => [
        'no_channel' => 'Ба ин муколама канали ҷавобдиҳӣ пайваст нашудааст.',
        'delivery_failed' => 'Паём ба муштарӣ расонида нашуд. Он сабт нашуд — боз кӯшиш кунед.',
    ],

];
