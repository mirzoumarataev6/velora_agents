<?php

namespace App\Services\Leads;

/**
 * Recognises a reply that TELLS the customer their order has been recorded.
 *
 * WHY THIS IS NEEDED. "Заявка принята" is a sentence, and a language model can
 * write a sentence without doing anything. In one real conversation the
 * assistant said three times that an order had been accepted — "Хуб, заявка
 * қабул шуд", "Хорошо, ваша заявка принята", "Мы все равно оформим вашу заявку"
 * — and the orders table stayed empty. Twice the tool had been called and the
 * write had failed; once the model simply never called it and wrote the
 * confirmation as a conversational move.
 *
 * From the customer's side those three are identical, and all three are lies
 * the business then has to answer for. So the claim is treated as a commitment:
 * whenever a reply makes one, the pipeline checks that a row exists and creates
 * one if it does not. This class only spots the claim; AgentReplyService decides
 * what to do about it.
 *
 * TUNED TOWARDS MISSING SOME. A missed claim leaves today's behaviour; a false
 * one files an order nobody placed, which wastes an owner's time and teaches
 * them to distrust the panel. So it matches whole statements of fact, never a
 * bare verb: "оформить" appears in "чтобы оформить заказ, оставьте номер",
 * which is a REQUEST for details and the opposite of a confirmation.
 */
class OrderConfirmationGuard
{
    /**
     * Phrases that assert an order now exists. Matched against folded text.
     *
     * Future tense is included on purpose ("я оформлю", "мы оформим"): to a
     * customer being told their order will be placed by an assistant that is
     * about to end the turn, that is a promise, and it is broken by exactly the
     * same missing row.
     *
     * @var string[]
     */
    protected const CLAIMS = [
        // Russian
        'заявка принята', 'заявка принят', 'заявку принял', 'заявка оформлена',
        'заказ принят', 'заказ оформлен', 'заказ записан', 'заказ зафиксирован',
        'я оформлю', 'мы оформим', 'оформим вашу заявку', 'оформим ваш заказ',
        'оформил вашу заявку', 'оформила вашу заявку', 'оформили вашу заявку',
        'зафиксирую', 'зафиксировал', 'зафиксировала', 'записал ваш заказ',
        'записала ваш заказ', 'записал вашу заявку', 'принял вашу заявку',
        'передам информацию о вашем заказе', 'ваша заявка принята', 'ваш заказ принят',
        // Tajik
        'қабул шуд', 'кабул шуд', 'қабул кардам', 'кабул кардам', 'қабул карда шуд',
        'кабул карда шуд', 'дархости шумо қабул', 'дархости шумо кабул',
        'фармоиши шумо қабул', 'фармоиши шумо кабул',
        // English
        'order is placed', 'order has been placed', 'order was placed', 'placed your order',
        'order accepted', 'order is accepted', 'recorded your order', 'order recorded',
        'noted your order', 'i have registered your order',
    ];

    /**
     * Whether this reply tells the customer an order now exists.
     */
    public function claimsOrderRecorded(?string $text): bool
    {
        $folded = $this->fold($text);

        if ($folded === '') {
            return false;
        }

        foreach (self::CLAIMS as $claim) {
            if (str_contains($folded, $claim)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lowercase, punctuation dropped, whitespace collapsed — so "Заявка
     * принята!" and "заявка, принята" both match the phrase they contain.
     */
    protected function fold(?string $text): string
    {
        if ($text === null) {
            return '';
        }

        $value = mb_strtolower($text);
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
