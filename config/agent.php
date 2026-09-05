<?php

return [

    /*
    |--------------------------------------------------------------------------
    | LLM provider
    |--------------------------------------------------------------------------
    |
    | Which implementation of LlmProviderInterface the container resolves.
    | Supported: "openai", "gemini". Changing this changes the dimensionality
    | of newly generated embeddings, so run `embeddings:reindex` afterwards —
    | until you do, chunks embedded under the previous provider are skipped
    | by the vector store (see MySqlCosineVectorStore).
    |
    */
    'llm_provider' => env('LLM_PROVIDER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | OpenAI API
    |--------------------------------------------------------------------------
    */
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
        // text-embedding-3-large, truncated to 1536 dimensions. The truncation
        // is free (Matryoshka), so this costs exactly what -small cost in
        // storage and in the PHP cosine loop, and measured better than -small at
        // every length on this corpus — decisively so across languages, which is
        // what -small could not do. A Tajik question about the teachers scored
        // 0.099 against the chunk that answers it under -small and 0.345 under
        // -large; Russian rose 0.372 -> 0.485. Oddly, 1536 dims also beat the
        // full 3072 on every query tested, so there is nothing to buy by raising it.
        //
        // Changing this makes existing vectors incomparable WITHOUT changing
        // their length, so `embedding_model` is recorded per chunk and the
        // vector store skips anything embedded under a different model. Run
        // `php artisan embeddings:reindex` after touching this.
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-large'),
        'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4o-mini'),
        'embedding_dimensions' => env('OPENAI_EMBEDDING_DIMENSIONS', 1536),
        'timeout' => env('OPENAI_TIMEOUT', 30),
        'connect_timeout' => env('OPENAI_CONNECT_TIMEOUT', 10),
        'retry_times' => env('OPENAI_RETRY_TIMES', 3),
        'retry_delay_ms' => env('OPENAI_RETRY_DELAY_MS', 500),
        'embedding_batch_size' => env('OPENAI_EMBEDDING_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gemini API
    |--------------------------------------------------------------------------
    */
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-2.5-flash'),
        'embedding_model' => env('GEMINI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        'vision_model' => env('GEMINI_VISION_MODEL', 'gemini-2.5-flash'),
        'embedding_dimensions' => env('GEMINI_EMBEDDING_DIMENSIONS', 768),
        'timeout' => env('GEMINI_TIMEOUT', 30),
        'connect_timeout' => env('GEMINI_CONNECT_TIMEOUT', 10),
        'retry_times' => env('GEMINI_RETRY_TIMES', 3),
        'retry_delay_ms' => env('GEMINI_RETRY_DELAY_MS', 500),
        'embedding_batch_size' => env('GEMINI_EMBEDDING_BATCH_SIZE', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG pipeline tuning
    |--------------------------------------------------------------------------
    */
    'rag' => [
        'chunk_size_tokens' => env('RAG_CHUNK_SIZE_TOKENS', 700),
        'chunk_overlap_tokens' => env('RAG_CHUNK_OVERLAP_TOKENS', 100),
        'spreadsheet_rows_per_chunk' => env('RAG_SPREADSHEET_ROWS_PER_CHUNK', 20),
        // Products per chunk for parsed XML catalogs (YML / Google Merchant /
        // CommerceML / generic). Mirrors spreadsheet_rows_per_chunk so retrieval
        // granularity stays consistent across tabular sources. UTF-8 files are
        // parsed forward-only with XMLReader; a non-UTF-8 (e.g. windows-1251)
        // source is decoded into memory first, since libxml needs a
        // self-consistent encoding declaration to stream it.
        'xml_products_per_chunk' => env('RAG_XML_PRODUCTS_PER_CHUNK', 20),
        'top_k' => env('RAG_TOP_K', 8),
        // Cosine-similarity floor for a chunk to count as a CONFIDENT match.
        // Provider-sensitive: text-embedding-3-small (the default) clusters
        // relevant matches around ~0.35-0.5, Gemini's gemini-embedding-001 runs
        // higher. If you switch LLM_PROVIDER back to gemini, raise this to ~0.45.
        //
        // Was 0.35, which was measurably too strict: absolute cosine collapses
        // when a short question is compared against a long chunk, and collapses
        // further across languages. "расскажите про учителей" scored at most
        // 0.21 against ANY chunk of a KB that plainly contained the answer, and
        // a Tajik phrasing of the same question topped out at 0.13 — so the
        // floor alone rejected the whole knowledge base and the agent said it
        // had no information.
        'similarity_threshold' => env('RAG_SIMILARITY_THRESHOLD', 0.22),
        // A chunk must ALSO score within this fraction of the best hit to count
        // as confident. The two bars answer different questions — the floor asks
        // "is this on topic at all", the ratio asks "is this comparable to the
        // best thing found" — and a chunk has to clear both, so widening one
        // does not quietly let noise through the other.
        'relative_ratio' => env('RAG_RELATIVE_RATIO', 0.6),
        // When nothing clears the bars above, still hand the model this many of
        // the best-ranked chunks, flagged as unconfirmed. A non-empty knowledge
        // base must never present as an empty one: the model can dismiss a
        // weak chunk, but it cannot use a chunk it was never shown.
        'min_context_chunks' => env('RAG_MIN_CONTEXT_CHUNKS', 3),
        // Skip embedding + vector search entirely when the message is
        // unambiguously a price/stock/availability question AND the agent has the
        // search_products tool, which answers those authoritatively and more
        // currently than any uploaded document. Saves an embedding round trip
        // plus a full cosine pass on exactly the questions the knowledge base
        // cannot help with.
        //
        // The detector (App\Services\Rag\ProductQuestionDetector) is tuned for
        // precision: any hint that the question also needs a business fact
        // ("сколько стоит доставка?") keeps retrieval. Set to false to disable
        // the optimization outright if a phrasing is ever misclassified — the
        // agent then behaves exactly as it did before.
        'skip_for_product_questions' => env('RAG_SKIP_FOR_PRODUCT_QUESTIONS', true),

        'history_length' => env('RAG_HISTORY_LENGTH', 10),
        'cache_ttl_seconds' => env('RAG_CACHE_TTL_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Human-like multi-message replies
    |--------------------------------------------------------------------------
    |
    | A long reply is delivered the way a person types in a messenger: broken
    | into a few consecutive messages with a typing pause between them, rather
    | than one wall of text. Applies to every channel; enabled per agent via
    | the `humanize_replies` column (default on).
    |
    | Splitting is by MEANING only — paragraph first, then sentence boundaries.
    | Text with no boundary to split on is sent as one message, so these numbers
    | are an upper bound on how many messages a reply can become, never a quota
    | the splitter tries to fill.
    |
    */
    'humanize' => [
        // Replies at or below this many characters are always sent as a single
        // message (greetings, one-liners, short factual answers).
        'min_length' => env('AGENT_HUMANIZE_MIN_LENGTH', 200),

        // Hard cap on messages per reply. Keep small — this is anti-spam.
        'max_messages' => env('AGENT_HUMANIZE_MAX_MESSAGES', 3),

        // Pause before each follow-up message, scaled by how long that message
        // is (a longer message reads as taking longer to type), then clamped
        // into [min_delay_ms, max_delay_ms]. The typing indicator is shown for
        // the duration of the pause on channels that support one.
        'min_delay_ms' => env('AGENT_HUMANIZE_MIN_DELAY_MS', 800),
        'max_delay_ms' => env('AGENT_HUMANIZE_MAX_DELAY_MS', 1500),
        'delay_ms_per_char' => env('AGENT_HUMANIZE_DELAY_MS_PER_CHAR', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Incoming voice notes (speech-to-text)
    |--------------------------------------------------------------------------
    |
    | Telegram voice notes are transcribed with Whisper and then fed into the
    | ordinary reply pipeline as if the customer had typed the transcript. The
    | audio is never stored: it is written to a temp file, sent, and deleted.
    |
    | Whisper accepts OGG/Opus (what Telegram sends) directly, so no conversion
    | is normally needed. `ffmpeg_path` is an escape hatch for environments where
    | that is not true — set it and the file is converted to mp3 first.
    |
    */
    'voice' => [
        'model' => env('OPENAI_TRANSCRIBE_MODEL', 'whisper-1'),

        // Anything longer is refused before it is downloaded. A five-minute note
        // is already an unusual thing to send a shop, and the cost and latency
        // of transcribing one is paid by the owner.
        'max_duration_seconds' => env('AGENT_VOICE_MAX_DURATION', 300),

        // Telegram's own getFile ceiling for bots is 20MB, below Whisper's 25MB,
        // so that is the binding limit.
        'max_file_bytes' => env('AGENT_VOICE_MAX_BYTES', 20 * 1024 * 1024),

        // Generous next to a chat completion: Whisper is slower, and the whole
        // thing runs inside the queued job, not the webhook request.
        'timeout' => env('AGENT_VOICE_TIMEOUT', 60),

        // Optional. Null = send the OGG straight to Whisper (the normal path).
        'ffmpeg_path' => env('AGENT_VOICE_FFMPEG_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Escalation to a human
    |--------------------------------------------------------------------------
    |
    | A conversation is flagged "needs human" when the customer is genuinely
    | dissatisfied, asks for a person outright, or the agent had to fall back.
    |
    | The frustration signal is produced by the model as a tool call on the SAME
    | generation that writes the reply, so a calm conversation costs nothing
    | extra — there is no second classification request.
    |
    | Sensitivity sets the confidence the model must report before the flag is
    | raised. The default is deliberately high: a false "angry customer" alert
    | teaches the owner to ignore the bell, which is worse than a missed one.
    | Per agent this is overridden by behavior.escalation.
    |
    */
    'escalation' => [
        'default_sensitivity' => env('AGENT_ESCALATION_SENSITIVITY', 'conservative'),

        // Minimum model-reported confidence per sensitivity level.
        'thresholds' => [
            'conservative' => 0.85,
            'balanced' => 0.70,
            'sensitive' => 0.55,
        ],

        // An explicit "give me a human" is a stated request, not an inference,
        // so it is honoured whatever the confidence — but still only at or above
        // this floor, so a passing mention doesn't trip it.
        'explicit_request_threshold' => 0.5,

        // Don't re-alert on every angry message in the same conversation; the
        // owner has already been told. Re-arms after this many minutes.
        'renotify_after_minutes' => env('AGENT_ESCALATION_RENOTIFY_MINUTES', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Knowledge source uploads
    |--------------------------------------------------------------------------
    */
    'dataset' => [
        // The structured product catalog (Каталог tab). Distinct from the
        // knowledge base above: this is imported row-per-product into a real
        // table and queried exactly, so only tabular formats are accepted.
        'max_file_size_kb' => env('AGENT_DATASET_MAX_FILE_SIZE_KB', 20 * 1024),
        'allowed_extensions' => ['xlsx', 'xls', 'csv'],

        // Ceiling on imported products per agent. A file longer than this is
        // imported up to the cap and the owner is told it was truncated —
        // better than refusing a 60k-row export outright.
        'max_rows' => env('AGENT_DATASET_MAX_ROWS', 50000),

        // Rows shown in the mapping screen, with their values, so the owner can
        // see what they are mapping.
        'sample_rows' => 5,

        // Page size for the owner's catalog browser in the Каталог tab. Purely
        // a UI concern — it does not limit what the agent can find, which is
        // ProductSearchService::MAX_LIMIT's job.
        'preview_rows' => 10,

        // How long an analyzed-but-not-yet-imported upload waits on disk for the
        // owner to confirm its mapping before it is swept.
        'pending_ttl_minutes' => env('AGENT_DATASET_PENDING_TTL_MINUTES', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Live database connections (advanced product source)
    |--------------------------------------------------------------------------
    |
    | The alternative to uploading a catalog: the agent reads the business's own
    | product table on every question. Everything here is a safety budget rather
    | than a feature toggle.
    |
    | `allow_private_hosts` is the SSRF gate. Left off, a connection may only be
    | pointed at a public address — otherwise an owner could dial 127.0.0.1:3306
    | and be handed OUR database, or use the "Test connection" button to scan the
    | internal network. Turn it on ONLY for a self-hosted install where the
    | customer database genuinely sits on the same private network.
    |
    */
    'database' => [
        'connect_timeout_seconds' => env('AGENT_DB_CONNECT_TIMEOUT', 5),

        // Server-side statement timeout. A slow customer database must not be
        // able to hold a reply — and therefore a queue worker — open.
        'query_timeout_seconds' => env('AGENT_DB_QUERY_TIMEOUT', 5),

        // Rows shown beside each column on the mapping screen.
        'sample_rows' => 5,

        // Tables offered in the picker. A shop database with thousands of tables
        // would otherwise put all of them in one response.
        'max_tables' => 500,

        'allow_private_hosts' => env('AGENT_DB_ALLOW_PRIVATE_HOSTS', false),

        // Path to a CA bundle used to VERIFY the customer's TLS certificate.
        // Without it the SSL toggle still encrypts, but cannot authenticate the
        // server — see MySqlDriver::extraOptions().
        'ssl_ca' => env('AGENT_DB_SSL_CA'),

        /*
         * Automatic schema discovery — the deterministic pass that proposes
         * WHERE the products are, so the owner confirms rather than maps.
         *
         * Discovery is decided by declared facts: foreign keys, column types,
         * and a name vocabulary read both in its own languages and through
         * transliteration, so `nazvanie` and `narkh` need no model.
         *
         * `use_llm` is ON, because the case it now covers cannot be solved by
         * any word list we could maintain: a shop whose columns are Uzbek or
         * Tajik words the vocabulary does not carry. It is consulted only when
         * the deterministic pass came up short — never on a scan that already
         * has an answer — and the constraints on it are unchanged: it is given
         * table and column NAMES only (never row data, never credentials), it
         * returns a suggestion, and that suggestion is discarded whole if it
         * names anything the schema does not actually contain or gives a column
         * a type its field cannot have. It can never produce SQL, what it
         * resolves is reported at LOW confidence with the column named, and its
         * answer is still confirmed by the owner before anything is stored.
         *
         * Set AGENT_DB_DISCOVERY_USE_LLM=false to run discovery entirely on the
         * deterministic layers.
         */
        'discovery' => [
            // Tables automatic discovery will read the columns of. Above this,
            // discovery declines and the owner is pointed at the manual picker.
            'max_tables' => env('AGENT_DB_DISCOVERY_MAX_TABLES', 300),

            // Relationship hops a composed query may follow (products →
            // categories is one). Bounded so a join plan can never become an
            // arbitrary walk of the customer's schema graph.
            'max_join_depth' => 2,

            // Rows in the confirmation preview the owner reviews before saving.
            'preview_rows' => 5,

            'use_llm' => env('AGENT_DB_DISCOVERY_USE_LLM', true),
        ],

        // "Test connection" attempts per minute, per user. This endpoint dials
        // an arbitrary host:port on request, so it is rate limited whether or
        // not private ranges are allowed.
        'test_rate_limit' => env('AGENT_DB_TEST_RATE_LIMIT', 10),
    ],

    'uploads' => [
        'max_file_size_kb' => env('AGENT_MAX_FILE_SIZE_KB', 20 * 1024),
        'allowed_extensions' => ['pdf', 'docx', 'xlsx', 'csv', 'xml', 'png', 'jpg', 'jpeg', 'webp'],
        'allowed_mimes' => [
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'application/xml',
            'text/xml',
            'image/png',
            'image/jpeg',
            'image/webp',
        ],
    ],

];
