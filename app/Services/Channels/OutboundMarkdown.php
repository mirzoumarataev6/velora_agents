<?php

namespace App\Services\Channels;

class OutboundMarkdown
{
    protected const OPEN = "\x02";

    protected const CLOSE = "\x03";

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

    // Преобразует markdown-разметку текста в HTML, понятный Telegram
    public function toTelegramHtml(string $text): string
    {
        $escaped = htmlspecialchars($this->normalize($text), ENT_NOQUOTES, 'UTF-8');

        return $this->render(
            $this->markup($escaped, fn (string $label, string $url) => sprintf(
                '<a href="%s">%s</a>',
                str_replace('"', '&quot;', $url),
                $label,
            )),
            'html',
        );
    }

    // Преобразует markdown-разметку текста в форматирование WhatsApp
    public function toWhatsApp(string $text): string
    {
        return $this->render(
            $this->markup($this->normalize($text), fn (string $label, string $url) => "{$label} ({$url})"),
            'whatsapp',
        );
    }

    // Убирает markdown-разметку, оставляя обычный текст
    public function toPlainText(string $text): string
    {
        return $this->render(
            $this->markup($this->normalize($text), fn (string $label, string $url) => "{$label} ({$url})"),
            'plain',
        );
    }

    protected function markup(string $text, callable $renderLink): string
    {
        $text = (string) preg_replace_callback(
            '/\[([^\]\n]+)\]\(\s*(https?:\/\/[^\s)]+)\s*\)/u',
            fn (array $m) => $renderLink($m[1], $m[2]),
            $text,
        );

        $text = (string) preg_replace('/`([^`\n]+)`/u', $this->wrap('code'), $text);

        $text = (string) preg_replace('/\*\*(?!\s)([^*\n]+?)(?<!\s)\*\*/u', $this->wrap('bold'), $text);
        $text = (string) preg_replace('/__(?!\s)([^_\n]+?)(?<!\s)__/u', $this->wrap('bold'), $text);

        $text = (string) preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', $this->wrap('italic'), $text);
        $text = (string) preg_replace('/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/u', $this->wrap('italic'), $text);

        $text = (string) preg_replace('/~~(?!\s)([^~\n]+?)(?<!\s)~~/u', $this->wrap('strike'), $text);

        $text = (string) preg_replace('/^[ \t]*#{1,6}[ \t]+(.+?)[ \t]*$/mu', $this->wrap('bold'), $text);

        return (string) preg_replace('/^[ \t]*[-*+][ \t]+/mu', '• ', $text);
    }

    protected function wrap(string $type): string
    {
        return self::OPEN.$type.self::CLOSE.'$1'.self::OPEN.'/'.$type.self::CLOSE;
    }

    protected function render(string $text, string $channel): string
    {
        $replacements = [];

        foreach (self::MARKERS[$channel] as $type => [$open, $close]) {
            $replacements[self::OPEN.$type.self::CLOSE] = $open;
            $replacements[self::OPEN.'/'.$type.self::CLOSE] = $close;
        }

        return trim(strtr($text, $replacements));
    }

    protected function normalize(string $text): string
    {
        $text = str_replace(["\r\n", self::OPEN, self::CLOSE], ["\n", '', ''], $text);

        return (string) preg_replace('/```[a-z]*\n?(.*?)```/us', '$1', $text);
    }
}
