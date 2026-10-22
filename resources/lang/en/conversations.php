<?php

return [

    'index' => [
        'title' => 'Conversations · Velora',
        'heading' => 'Conversations',
        'subheading' => 'Every customer chat across all your agents and channels.',
        'search_placeholder' => 'Search customers and messages…',
        'all_agents' => 'All agents',
        'all_channels' => 'All channels',
        'date_from' => 'From',
        'date_to' => 'To',
        'invalid_date' => 'Enter a real date in YYYY-MM-DD format.',
        'reset_filters' => 'Reset filters',
        'unread_badge' => 'unread',
        'load_more' => 'Load more',
        'loading' => 'Loading…',
        'results_count' => ':count conversation(s)',
        'resize_handle' => 'Resize the conversation list',
    ],

    'escalation' => [
        'signals' => [
            'frustration' => 'Customer is upset',
            'complaint' => 'Complaint about service or product',
            'explicit_request' => 'Asking for a human',
            'threatening_to_leave' => 'Threatening to leave',
            'fallback' => 'The agent could not answer',
        ],
        'auto_summary' => [
            // Written by the pipeline's own detector rather than by the model,
            // so the owner sees why the handover fired without a summary having
            // to be generated for it.
            'abuse' => 'The customer is abusive towards the assistant',
            'repeated_rudeness' => 'The customer is being rude to the assistant again',
            'asked_for_human' => 'The customer asked to speak to a person',
            'repeated_question' => 'The customer has asked the same question several times',
        ],
        'notify' => [
            'title' => 'A customer needs a human',
            'cta' => 'Open the conversation and reply personally:',
        ],
    ],

    'search' => [
        'clear' => 'Clear search',
        'next_match' => 'Next match',
        'prev_match' => 'Previous match',
        'matches_in_chat' => ':count hits',
        'empty_title' => 'Nothing found',
        'empty_description' => 'No message contains “:term”. Try a different query.',
    ],

    /* One line per filter, shown in the inbox's unopened thread pane.
     * The filters are the only non-obvious part of that screen, and the
     * pane is two thirds of it — so the space explains them instead of
     * staying blank. */
    'filter_help' => [
        'needs_human' => 'The assistant handed the conversation to a person — the customer asked for one, or it went wrong.',
        'has_lead' => 'The conversation produced a lead: a name, a contact and what the person wants.',
        'rated_down' => 'The customer marked an answer unhelpful. The only signal for a confident but wrong reply.',
        'unread' => 'A new message arrived that you have not opened yet.',
    ],

    'filters' => [
        'all' => 'All',
        'unread' => 'Unread',
        'needs_human' => 'Needs human',
        'has_lead' => 'With a lead',
        'rated_down' => 'Rated 👎',
        'rated_up' => 'Rated 👍',
        'closed' => 'Closed',
    ],

    'status' => [
        'ai' => 'Agent replying',
        'human_takeover' => 'You are replying',
        'closed' => 'Closed',
    ],

    'roles' => [
        'user' => 'Customer',
        'assistant' => 'Agent',
        'operator' => 'You',
        'system' => 'System',
    ],

    'thread' => [
        'placeholder_title' => 'Pick a conversation',
        'placeholder_description' => 'Choose a chat on the left to read the full history and reply personally.',
        'started' => 'Started :date',
        'reply_placeholder' => 'Write a reply as a human operator…',
        'send' => 'Send',
        'sending' => 'Sending…',
        'take_over' => 'Take over',
        'hand_back' => 'Hand back to the agent',
        'close' => 'Close conversation',
        'reopen' => 'Reopen',
        'open_agent' => 'Open agent',
        'knowledge_used' => 'Knowledge used',
        'knowledge_unconfirmed' => 'weak match',
        'leads_heading' => 'Captured leads',
        'view_in_leads' => 'View in Leads',
        'rating_up' => '👍',
        'rating_down' => '👎',
        'rating_up_hint' => 'Rated by the customer',
        'rating_down_hint' => 'The customer disliked this reply',
        'voice_note' => 'voice message',
        'delivery_failed' => 'Not delivered to the customer',
        'no_channel' => 'This conversation has no connected channel, so it cannot be replied to.',
        'back' => 'Back to the list',
    ],

    'empty' => [
        'title' => 'No conversations yet',
        'description' => 'As soon as a customer writes to a connected Telegram, WhatsApp or Instagram account, the chat appears here.',
        'filtered_title' => 'Nothing matches these filters',
        'filtered_description' => 'Try a different agent, channel or search term.',
    ],

    'toasts' => [
        'sent' => 'Reply sent',
        'taken_over' => 'You are now replying to this conversation',
        'handed_back' => 'The agent is replying again',
        'closed' => 'Conversation closed',
        'reopened' => 'Conversation reopened',
        'failed' => 'That did not work. Please try again.',
        'network' => 'Network problem — please try again.',
    ],

    'errors' => [
        'no_channel' => 'This conversation has no connected channel to reply through.',
        'delivery_failed' => 'The message could not be delivered to the customer. Nothing was recorded — please try again.',
        'outside_window' => 'More than 24 hours have passed since the customer last wrote — WhatsApp and Instagram do not allow a free-form message after that. Nothing was sent or saved. Wait until they write again.',
    ],

];
