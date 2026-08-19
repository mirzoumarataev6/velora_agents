<?php

return [
    'index' => [
        'title' => 'Leads — Velora Agents',
        'heading' => 'Leads',
        'subheading' => 'Orders and requests your assistants captured from customers.',
        'all_agents' => 'All agents',
        'no_name' => 'Unnamed customer',
        'items_label' => 'Wants',
        'note_label' => 'Note',
        'delete' => 'Delete lead',
        'updated' => 'Lead updated.',
        'deleted' => 'Lead deleted.',
        'action_failed' => 'Something went wrong. Please try again.',
        'network_error' => 'Network error. Please try again.',
        'delete_confirm' => 'Delete this lead? This cannot be undone.',
        'empty_title' => 'No leads yet',
        'empty_description' => 'When a customer places an order or leaves their contact details, the assistant records it here.',
        'empty_filtered_title' => 'Nothing found',
        'empty_filtered_description' => 'Try a different search, status or agent.',
        'search_placeholder' => 'Search by name, contact or order…',
        'search_results' => 'Results for “:term”',
        'search_clear' => 'Clear',
    ],

    'filters' => [
        'all' => 'All',
        'new' => 'New',
        'in_progress' => 'In progress',
        'done' => 'Done',
    ],

    'status' => [
        'new' => 'New',
        'in_progress' => 'In progress',
        'done' => 'Done',
    ],

    'source' => [
        'telegram' => 'Telegram',
        'whatsapp' => 'WhatsApp',
        'instagram' => 'Instagram',
        'playground' => 'Playground',
    ],

    'bell' => [
        'label' => 'Notifications',
        'title' => 'New leads',
        'mark_seen' => 'Mark all read',
        'empty' => 'No leads yet.',
        'no_name' => 'Unnamed customer',
        'view_all' => 'View all leads',
    ],

    'notify' => [
        'heading' => 'Telegram alerts for new leads',
        'description' => 'Link your personal Telegram to get a message the moment a new lead comes in.',
        'telegram_required' => 'Connect a Telegram bot for this agent first (Channels tab), then you can link your Telegram here.',
        'linked_as' => 'Alerts go to',
        'unlink' => 'Unlink',
        'link_button' => 'Link my Telegram',
        'waiting' => 'Waiting for you to tap Start in Telegram…',
        'open_telegram' => 'Open Telegram',
        'unlink_confirm' => 'Stop sending lead alerts to this Telegram chat?',
        'unlink_confirm_inline' => 'Unlink?',
        'unlink_yes' => 'Yes',
        'unlink_no' => 'No',
        'toasts' => [
            'linked' => 'Telegram linked — you will get alerts for new leads.',
            'unlinked' => 'Telegram unlinked.',
            'failed' => 'Something went wrong. Please try again.',
            'network' => 'Network error. Please try again.',
        ],
    ],
];
