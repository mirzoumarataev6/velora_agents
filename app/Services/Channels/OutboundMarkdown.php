<?php

namespace App\Services\Channels;

/**
 * Converts the Markdown an LLM writes into what each messenger actually renders.
 *
 * THE PROBLEM. The model writes "**OMEN 16** — 14999 сомони" because that is
 * what assistants write. Telegram, WhatsApp and Instagram all received it as
 * raw text, so customers read the asterisks. Nothing was wrong with the reply;
 * it was being posted in the wrong dialect.
 *
 * WHY NOT SIMPLY TURN ON A PARSE MODE. Because each platform speaks a different
 * one, and two of them are booby-trapped:
 *
 *  - Telegram MarkdownV2 requires . ! - ( ) + = | { } # and more to be escaped
 *    ANYWHERE in the text, including inside a product name or a price. One
 *    unescaped "15.6\"" or "OMEN 16 (2024)" and the API rejects the whole
 *    message — the customer gets silence, which is far worse than a stray
 *    asterisk. Telegram HTML needs exactly three characters escaped (& < >),
 *    which is a rule that can be applied correctly every time, so that is what
 *    this produces.
 *  - WhatsApp has no parse mode at all, and its own syntax: *bold* with ONE
 *    asterisk, _italic_, ~strike~. Markdown's ** renders as literal asterisks
 *    around bold-looking text.
 *  - Instagram DMs render nothing. Every marker has to come out.
 *
 * HOW IT WORKS. Each markdown form is first replaced by a private sentinel, and
 * the sentinels are swapped for the channel's real markers in one final pass.
 * That indirection is not decoration: converting straight to the target markers
 * means the next rule reads the previous rule's output, and on WhatsApp — where
 * bold IS a single asterisk — "**OMEN 16**" became bold and then italic. With
 * sentinels no pass can see another pass's work.
 */
class OutboundMarkdown
{
    /**
     * Sentinels for one span of formatting. Control characters, so nothing a
     * model or a product name can contain will ever collide with them.
     */
    protected const OPEN = "\x02";

    protected const CLOSE = "\x03";

    /** Per-channel replacements for each sentinel pair: [open, close]. */
    protected const MARKERS = [
        'html' => [
            'bold' => ['<b>', '</b>'],
            'italic' => ['<i>', '</i>'],
            'code' => ['<code>', '</code>'],
            'strike' => ['<s>', '</s>'],
        ],
        'whatsapp' => [
            'bold' => ['*', '*'],
            'italic' => ['_', '_'],
            // WhatsApp's monospace is a fenced form that reads worse inline than
            // the plain words do.
            'code' => ['', ''],
            'strike' => ['~', '~'],
        ],
        'plain' => [
            'bold' => ['', ''],
            'italic' => ['', ''],
            'code' => ['', ''],
            'strike' => ['', ''],
        ],
    ];

    /**
     * Telegram, to be sent with parse_mode=HTML.
     *
     * HTML-escaping happens FIRST, on the whole text, so a product name holding
     * "<" or "&" is neutralised before any tag exists. Markdown markers are
     * untouched by that escaping, so the conversions still see them.
     */
    public function toTelegramHtml(string $text): string
    {
        $escaped = htmlspecialchars($this->normalize($text), ENT_NOQUOTES, 'UTF-8');

        // Already escaped above; only the quote that would end the attribute
        // needs handling here, and re-escaping the URL would double every &.
        return $this->render(
            $this->markup($escaped, fn (string $label, string $url) => sprintf(
                '<a href="%s">%s</a>',
                str_replace('"', '&quot;', $url),
                $label,
            )),
            'html',
        );
    }

    /** WhatsApp's own markers: single asterisk for bold, no parse mode. */
    public function toWhatsApp(string $text): string
    {
        return $this->render(
            $this->markup($this->normalize($text), fn (string $label, string $url) => "{$label} ({$url})"),
            'whatsapp',
        );
    }

    /**
     * Instagram, and anywhere else that renders no formatting: every marker
     * removed, nothing left behind.
     */
    public function toPlainText(string $text): string
    {
        return $this->render(
            $this->markup($this->normalize($text), fn (string $label, string $url) => "{$label} ({$url})"),
            'plain',
        );
    }

    /**
     * Replace every markdown form with sentinels, in the one order that works:
     * links before anything can eat their punctuation, code before the
     * emphasis rules can format its contents, and bold before italic — or the
     * single-asterisk rule takes one asterisk from each pair and leaves the
     * other stranded.
     *
     * @param  callable(string, string): string  $renderLink
     */
    protected function markup(string $text, callable $renderLink): string
    {
        $text = (string) preg_replace_callback(
            '/\[([^\]\n]+)\]\(\s*(https?:\/\/[^\s)]+)\s*\)/u',
            fn (array $m) => $renderLink($m[1], $m[2]),
            $text,
        );

        $text = (string) preg_replace('/`([^`\n]+)`/u', $this->wrap('code'), $text);

        // **bold** and __bold__.
        $text = (string) preg_replace('/\*\*(?!\s)([^*\n]+?)(?<!\s)\*\*/u', $this->wrap('bold'), $text);
        $text = (string) preg_replace('/__(?!\s)([^_\n]+?)(?<!\s)__/u', $this->wrap('bold'), $text);

        // *italic* and _italic_. The underscore form needs a non-word character
        // on each side, so snake_case in a product code or a URL is left alone.
        $text = (string) preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', $this->wrap('italic'), $text);
        $text = (string) preg_replace('/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/u', $this->wrap('italic'), $text);

        $text = (string) preg_replace('/~~(?!\s)([^~\n]+?)(?<!\s)~~/u', $this->wrap('strike'), $text);

        // No messenger has headings, so they become emphasis.
        $text = (string) preg_replace('/^[ \t]*#{1,6}[ \t]+(.+?)[ \t]*$/mu', $this->wrap('bold'), $text);

        // A line still beginning "* " is an unmatched italic marker on WhatsApp
        // and a literal asterisk everywhere else — the same bug in miniature.
        return (string) preg_replace('/^[ \t]*[-*+][ \t]+/mu', '• ', $text);
    }

    /** The sentinel-wrapped replacement pattern for one span type. */
    protected function wrap(string $type): string
    {
        return self::OPEN.$type.self::CLOSE.'$1'.self::OPEN.'/'.$type.self::CLOSE;
    }

    /**
     * Swap every sentinel for the channel's real marker. Any sentinel left
     * unmatched by the rules above simply disappears, which is the safe
     * outcome — a stray control character must never reach a customer.
     */
    protected function render(string $text, string $channel): string
    {
        $replacements = [];

        foreach (self::MARKERS[$channel] as $type => [$open, $close]) {
            $replacements[self::OPEN.$type.self::CLOSE] = $open;
            $replacements[self::OPEN.'/'.$type.self::CLOSE] = $close;
        }

        return trim(strtr($text, $replacements));
    }

    /**
     * Fenced blocks collapsed to their content, and CRLF normalised.
     *
     * The fences are dropped rather than converted: a chat reply has no use for
     * a code block, and an unclosed one would swallow the rest of the message.
     */
    protected function normalize(string $text): string
    {
        $text = str_replace(["\r\n", self::OPEN, self::CLOSE], ["\n", '', ''], $text);

        return (string) preg_replace('/```[a-z]*\n?(.*?)```/us', '$1', $text);
    }
}
