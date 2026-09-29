<?php

return [
    'title' => 'Analytics — Velora Agents',
    'heading' => 'Analytics',
    'subheading' => 'What your assistants did for you.',

    'greeting' => 'Good afternoon, :name',

    'details' => [
        'title' => 'Detailed analytics',
        'description' => 'Funnel, reply speed, demand and agent performance',
    ],

    'units' => [
        'seconds' => 's',
        'ms' => 'ms',
    ],

    'range' => [
        'label' => 'Period',
    ],

    // The trend pill's accessible name. The pill itself shows only "18%" — the
    // arrow beside it is a decorative glyph that a screen reader skips, so the
    // direction has to be spelled out here or the number means nothing.
    'trend' => [
        'up' => ':percent% more than the previous period',
        'down' => ':percent% less than the previous period',
        'flat' => 'unchanged from the previous period',
    ],

    'agent_filter' => [
        'label' => 'Agent',
        'all' => 'All agents',
    ],

    'empty' => [
        'title' => 'Nothing to show yet',
        'description' => 'Your numbers will appear here once there are enough conversations. Connect a channel and let the assistant work — the first figures show up after a handful of chats.',
        'cta' => 'Go to agents',
    ],
    'no_data' => 'Your numbers will appear here once there are enough conversations.',

    'summary' => [
        'active_conversations' => 'Active conversations',
        'active_conversations_hint' => 'Not closed, and active in the last 24 hours',
        'leads_today' => 'Leads today',
        'leads_week_hint' => 'Leads since the start of the week; the line is per day across the period',
        'leads_week' => 'Leads this week',
        'conversion' => 'Conversation → lead',
        'conversion_hint' => 'Share of conversations that produced a lead',
        'response_time' => 'Average reply time',
        'response_time_hint' => 'How long the assistant spends on one answer',
        'messages' => 'Messages',
        'messages_hint' => 'Every message in the selected period',
        'of_total_conversations' => ':count conversation in total|:count conversations in total',
    ],

    'after_hours' => [
        'title' => 'Working while you sleep',
        'description' => 'Messages and leads handled outside business hours — nights from :from:00 to :to:00, and weekends.',
        'messages' => 'messages handled',
        'leads' => 'leads captured',
        'share' => ':percent% of all messages',
        'empty' => 'No after-hours traffic yet — it will show up here as soon as there is.',
    ],

    'highlight' => [
        'eyebrow' => 'What to do now',
        'price_approvals' => ':count customer(s) are waiting on your price decision',
        'price_approvals_action' => 'Approve a price',
        'thumbs_down' => 'Customers marked :count repl(ies) as unhelpful',
        'thumbs_down_hint' => 'Open the conversation and see what the assistant got wrong.',
        'thumbs_down_action' => 'View conversations',
        'unanswered' => 'Asked :count times with no answer to give: ":subject"',
        'unanswered_hint' => 'Add it to the knowledge base and the assistant answers it next time.',
        'unanswered_action' => 'Add to knowledge base',
        'product_gap' => '":subject" was searched for :count times and never found',
        'product_gap_hint' => 'That looks like demand you are not meeting yet.',
        'product_gap_action' => 'Open the catalog',
        'open_orders' => 'Open leads: :count',
        'open_orders_hint' => 'Work through them while the customers are still waiting.',
        'open_orders_action' => 'Go to leads',
        'clear_title' => 'Nothing needs you',
        'clear' => 'Leads are closed, no questions went unanswered, and no replies were marked wrong.',
        'after_hours' => ':count messages this period were handled at night or at the weekend — :percent% of all of them.',
    ],

    'messages_chart' => [
        'title' => 'Messages',
        'range_7' => '7 days',
        'range_30' => '30 days',
        'total' => 'Total for the period',
        'empty' => 'No messages in this period.',
        'insight_channel' => ':channel brought the most traffic — :percent% of all messages.',
        'insight_peak' => 'The peak was :day — :count messages in a day.',
    ],

    'channels' => [
        'telegram' => 'Telegram',
        'whatsapp' => 'WhatsApp',
        'greenapi' => 'WhatsApp (Green API)',
        'instagram' => 'Instagram',
        'playground' => 'Playground',
    ],

    'pending' => [
        'title' => 'Needs your attention',
        'open_orders' => 'Open leads',
        'open_orders_hint' => 'New and in progress',
        'price_approvals' => 'Price approvals',
        'price_approvals_hint' => 'A customer is waiting on you',
        'view' => 'Open',
        'clear' => 'All clear — nothing is waiting.',
        'negative_ratings' => 'Negative ratings',
        'negative_ratings_hint' => 'Answers customers marked as unhelpful',
        'in_progress' => 'Leads in progress',
        'in_progress_hint' => 'Picked up, not closed yet',
        'view_all' => 'All leads',
    ],

    'funnel' => [
        'title' => 'Funnel',
        'description' => 'How conversations turn into leads and confirmed sales.',
        'conversations' => 'Conversations started',
        'conversations_hint' => 'Every customer conversation, all time',
        'leads' => 'Leads created',
        'leads_hint' => 'Conversations where the customer left an order or a contact',
        'confirmed' => 'Sales closed',
        'confirmed_hint' => 'Leads taken up or closed',
        // Neutral, analytical framing — like Amplitude or Mixpanel: this
        // reports the conversion rate INTO this stage, not a loss.
        'conversion' => 'conversion at this step: :percent%',
        'of_all' => ':percent% of conversations',
    ],

    'ratings' => [
        'title' => 'Reply quality',
        'description' => 'Customers rate the assistant\'s replies right inside Telegram. It is the only signal that catches a confidently wrong answer — the thing no other metric on this page can see.',
        'telegram_only' => 'Telegram',
        'score' => 'Replies rated helpful',
        'total' => 'ratings this period',
        'up' => 'Liked',
        'down' => 'Disliked',
        'trend_title' => 'Day by day',
        'worst_title' => 'Latest replies rated 👎',
        'view_negative' => 'All negative ratings',
        'view_positive' => 'All positive ratings',
        'open_conversation' => 'Open conversation',
        'no_negative' => 'Not a single 👎 this period — customers were happy.',
        'empty_title' => 'No ratings yet',
        'empty' => 'No ratings yet. They appear once customers start rating replies in Telegram.',
        'empty_how_title' => 'How it works',
        'empty_how' => [
            '👍 and 👎 buttons appear under the assistant\'s replies in Telegram.',
            'They are not shown under every reply, only from time to time, so customers are not pestered.',
            'A reply can be rated once; the buttons retire after that.',
        ],
    ],

    'response_trend' => [
        'title' => 'Reply speed',
        'description' => 'Average time per reply, day by day — is the assistant speeding up or slowing down.',
        'empty' => 'Nothing to measure yet — timings appear after the first replies.',
        'cell' => ':day — :value',
        'no_replies' => 'no replies',
    ],

    'leads_status' => [
        'title' => 'Leads by status',
        'description' => 'Where every lead currently sits.',
        'empty' => 'No leads yet.',
        'total' => 'Leads in total',
        'statuses' => [
            'new' => 'New',
            'in_progress' => 'In progress',
            'awaiting_price_approval' => 'Awaiting price approval',
            'done' => 'Done',
        ],
    ],

    'agents_compare' => [
        'title' => 'Load by agent',
        'description' => 'Which assistant is handling more of the conversations this period.',
        'messages' => ':count msg.',
        'leads' => ':count leads',
        'share' => ':percent% of load',
    ],

    'heatmap' => [
        'title' => 'When customers write',
        'description' => 'Message volume by day of week and hour.',
        'timezone_label' => 'Time zone: Dushanbe (UTC+5)',
        'less' => 'less',
        'more' => 'more',
        'days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        'cell' => ':day, :hour:00 — :count msg.',
    ],

    'unanswered' => [
        'title' => 'Unanswered questions',
        'description' => 'What customers ask that the assistant has no answer for. Add it to the knowledge base or the catalog.',
        'empty' => 'The assistant answered everything. Unanswered questions will appear here.',
        'times' => ':count×',
        'reasons' => [
            'catalog_miss' => 'not in the catalog',
            'knowledge_miss' => 'not in the knowledge base',
            'provider_down' => 'service outage',
        ],
    ],

    'products' => [
        'title' => 'Most-asked products',
        'description' => 'Catalog searches — whether or not they ended in an order.',
        'empty' => 'Nobody has asked about a product yet.',
        'asked' => ':count search(es)',
        'miss_rate' => 'nothing found :percent% of the time',
    ],
];
