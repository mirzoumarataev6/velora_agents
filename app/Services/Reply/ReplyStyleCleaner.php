<?php

namespace App\Services\Reply;

/** Removes generic sign-offs without rewriting facts or ordering instructions. */
class ReplyStyleCleaner
{
    private const INVITATIONS = [
        '/^Если у вас (?:возникнут|будут|появятся) (?:ещ[ёе] |дополнительные )?вопросы,? (?:пишите|обращайтесь|дайте знать|буду рад[а]? помочь)[.!]?$/iu',
        '/^Если вам интересно,? могу помочь с оформлением заказа(?: или ответить на дополнительные вопросы)?[.!]?$/iu',
        '/^Если вас это устраивает,? могу помочь с оформлением заказа[.!]?$/iu',
        '/^Хотите (?:узнать подробнее|узнать больше|оформить заказ)(?:\b.*)?[?]$/iu',
        '/^Как (?:вы )?(?:хотите|желаете) поступить[?]$/iu',
        '/^(?:Would you like|Do you want) (?:to know more|to proceed|to place (?:an|the) order|more information)\b.*[?]$/iu',
        '/^If you have (?:any |more |any more |additional )?questions(?: or need help later)?,? (?:feel free to (?:reach out|ask)|let me know)[.!]?$/iu',
        '/^(?:Feel free to reach out|Let me know if you (?:have any questions|need (?:help|more information|more details)))[.!]?$/iu',
        '/^Take your time(?: to decide)?,? and feel free to reach out(?: if you have (?:any )?questions(?: later)?)?[.!]?$/iu',
        '/^Агар (?:шумо )?ба (?:ягон )?(?:маълумоти дигар|иттилооти бештар)(?: ё (?:кумак|кӯмак))? ниёз дошта бошед,? (?:ман дар хизмати шумо ҳастам|маро огоҳ кунед|ба ман хабар диҳед)[.!]?$/iu',
        '/^Ба ман хабар диҳед[.!]?$/iu',
    ];

    public function clean(string $text): string
    {
        $sentences = preg_split('/(?<=[.!?。！？])\s+|\R+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $kept = array_filter($sentences, fn ($sentence) => ! $this->isGenericInvitation($sentence));

        // Never turn a reply into silence; short courtesy-only answers are valid.
        if ($kept === [] || count($kept) === count($sentences)) {
            return $text;
        }

        return implode(' ', $kept);
    }

    private function isGenericInvitation(string $sentence): bool
    {
        // Numbers, links and concrete ordering details must survive cleanup.
        if (preg_match('/\p{N}|https?:|@|\b(?:телефон\w*|номер\w*|адрес\w*|оплат\w*|достав\w*|phone|address|payment|delivery|рақам\w*|суроға\w*|пардохт\w*)\b/iu', $sentence)) {
            return false;
        }

        foreach (self::INVITATIONS as $pattern) {
            if (preg_match($pattern, trim($sentence))) {
                return true;
            }
        }

        return false;
    }
}
