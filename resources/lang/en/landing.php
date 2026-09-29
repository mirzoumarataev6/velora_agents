<?php

/*
 * Landing copy. The key tree must stay identical across ru/, tg/ and en/ —
 * the landing embeds all three and swaps them client-side, and
 * LandingContentTest asserts the shapes match.
 */

return [
    /*
     * TONE — calm and specific.
     *
     * Say what the product does; don't say how great it is. No "sells around
     * the clock", no "never miss a customer", no "before your competitor" —
     * that is advertising language, not product language. The reference points
     * are Stripe, Linear and Intercom: a short statement of what the thing IS,
     * one sentence on how it works, then specifics instead of adjectives.
     */

    'meta' => [
        'title' => 'Velora Agents — AI agents for Telegram, WhatsApp and Instagram',
        'description' => 'Velora puts an AI agent in your messaging channels: it answers from your catalog, holds the conversation in your customer’s language, and hands you the lead with the full thread.',
    ],

    'nav' => [
        'product' => 'Product',
        'how' => 'How it works',
        'faq' => 'FAQ',
        'language' => 'Language',
        'menu' => 'Menu',
        'skip' => 'Skip to content',
        'home' => 'Velora Agents — home',
    ],

    'hero' => [
        // Not a promise — a fact about the product: where it runs.
        'badge' => 'Live in Telegram, WhatsApp and Instagram',
        'brand' => 'Velora Agents',
        // Typed in sequence by JS. `title_static` is what assistive technology
        // and no-JS visitors get instead, so it must read as a whole sentence.
        // Each line is a plain statement of what the agent does: short, no
        // superlatives, no comparisons to anyone else.
        'typed' => [
            'answers your customers.',
            'works from your catalog.',
            'hands you the lead.',
            'speaks your customer’s language.',
        ],
        'title_static' => 'Velora Agents answers your customers.',
        'sub' => 'The agent answers product questions from your catalog, holds the conversation in your customer’s language, and hands you the lead with a name, a number and the full thread.',
        'cta_primary' => 'Create your agent',
        'cta_secondary' => 'See an example chat',
        'meta_1' => 'No card required',
        'meta_2' => 'No-code setup',
        'meta_3' => 'Russian · English · Tajik',
    ],

    'demo' => [
        'heading' => 'Example conversation',
        'agent' => 'Atlas Store',
        'status' => 'AI agent · example',
        'composer' => 'Message',
        'side_label' => 'Live from this chat',
        'lead_title' => 'New lead captured',
        'lead_stamp' => 'just now · sent to your Telegram',
        'row_name' => 'Name',
        'row_phone' => 'Phone',
        'row_wants' => 'Wants',
        'row_budget' => 'Budget',
        'grounded' => 'Answer based on the store catalog',
        'speed' => 'Stock information from the catalog',
        'aria' => 'A customer asks in Tajik for a work laptop under 4500 somoni. The agent answers with a matching model from the catalog, confirms the warranty, and captures the customer’s name and phone number as a lead.',
    ],

    'why' => [
        'eyebrow' => 'Why it works',
        'title' => 'An agent that answers from your own data.',
        'sub' => 'Every reply comes from your catalog. Every conversation that shows intent ends as a lead in your inbox.',
        'items' => [
            [
                'title' => 'Answers from your catalog — not from thin air',
                'line' => 'Point it at an XML feed, a CSV, a PDF price list or your website. It retrieves the real item, the real price, the real availability. If it isn’t in your catalog, it doesn’t get invented.',
            ],
            [
                'title' => 'Captures the lead the moment intent appears',
                'line' => 'The agent picks up on intent, asks for a name and a number, and sends you the lead in Telegram while the conversation is still open.',
            ],
            [
                'title' => 'Three channels, one brain',
                'line' => 'Telegram, WhatsApp and Instagram Direct reach the same agent, with the same knowledge and the same tone. Nothing is configured twice.',
            ],
            [
                'title' => 'Proactive, never pushy',
                'line' => 'Dial in how forward the agent is. One suggestion and one question per reply, at most — the line between helpful and irritating, enforced in code.',
            ],
            [
                'title' => 'Speaks your customer’s language',
                'line' => 'Russian, English and Tajik, chosen per message — including the mixed Russian-Tajik people actually write in.',
            ],
            [
                'title' => 'You stay in control',
                'line' => 'Rehearse in the playground, read every transcript, adjust the tone, switch the agent off. No surprises in front of customers.',
            ],
        ],
    ],

    'sources' => [
        'eyebrow' => 'Inputs and outputs',
        'title' => 'Feed it anything. Reach them anywhere.',
        'sub' => 'Two lists that actually matter: what the agent can learn from, and where it can speak.',
        'inputs_label' => 'Knowledge it ingests',
        'channels_label' => 'Channels it answers on',
        'inputs' => [
            ['name' => 'XML feeds', 'note' => 'Re-fetched on a schedule'],
            ['name' => 'CSV catalogs', 'note' => 'Any column layout'],
            ['name' => 'PDF price lists', 'note' => 'Text extracted per page'],
            ['name' => 'Website URLs', 'note' => 'Crawled and kept current'],
        ],
        'channels' => [
            ['name' => 'Telegram', 'note' => 'Connect with a bot token'],
            ['name' => 'WhatsApp', 'note' => 'Business number'],
            ['name' => 'Instagram', 'note' => 'Direct messages'],
        ],
    ],

    'how' => [
        'eyebrow' => 'How it works',
        'title' => 'Three steps. One afternoon.',
        'steps' => [
            ['title' => 'Feed it what you sell', 'line' => 'Drop in a catalog feed, upload a price list, or paste your site’s URL. Velora ingests it and keeps it fresh.'],
            ['title' => 'Shape the agent', 'line' => 'Set the tone, choose how proactive it should be, and rehearse in the playground until it sounds like your shop.'],
            ['title' => 'Connect a channel', 'line' => 'Paste a Telegram bot token — or link WhatsApp and Instagram — and it starts answering.'],
        ],
    ],

    'stats' => [
        'items' => [
            ['value' => '24/7', 'label' => 'The agent is online'],
            ['value' => '3', 'label' => 'Channels, one agent'],
            ['value' => '4', 'label' => 'Languages, chosen per message'],
            ['value' => '1', 'label' => 'CRM for leads and deals'],
        ],
    ],

    'manifesto' => [
        'lead' => 'A customer’s attention lasts',
        'emphasis' => 'seconds.',
        'sig' => 'We’re there in time.',
    ],

    'faq' => [
        'eyebrow' => 'Questions',
        'title' => 'The short answers',
        'items' => [
            [
                'q' => 'Will it make things up about my products?',
                'a' => 'No. Replies are grounded in the catalog and documents you upload. When something isn’t there, the agent says so and offers to pass the question to you rather than guessing a price or a spec.',
            ],
            [
                'q' => 'What do I need to get started?',
                'a' => 'A list of what you sell, in almost any shape — an XML feed, a CSV export, a PDF price list, or just your site’s address. And a Telegram bot token, which takes about a minute to create.',
            ],
            [
                'q' => 'How does a lead reach me?',
                'a' => 'Two ways at once: it appears on your leads page with the full conversation attached, and it is pushed to your Telegram the moment it is captured.',
            ],
            [
                'q' => 'Can I read what it told people?',
                'a' => 'Every conversation on every channel is stored and readable from the agent’s page — so you can audit the tone and improve the catalog accordingly.',
            ],
            [
                'q' => 'Which languages does it handle?',
                'a' => 'Russian, English and Tajik. It detects the language of each incoming message and replies in kind.',
            ],
        ],
    ],

    'cta' => [
        'title' => 'Put your first agent to work',
        'emphasis' => 'today.',
        'sub' => 'Create an agent, feed it your catalog, connect Telegram. For most shops that is one afternoon.',
        'primary' => 'Create your agent',
        'secondary' => 'I already have an account',
    ],

    'footer' => [
        'tagline' => 'AI agents that answer from your catalog in Telegram, WhatsApp and Instagram.',
        'product' => 'Product',
        'overview' => 'Overview',
        'channels' => 'Channels',
        'language' => 'Language',
        'account' => 'Account',
        'login' => 'Log in',
        'register' => 'Create account',
        'built' => 'Built in Dushanbe · answering in three languages',
    ],
];
