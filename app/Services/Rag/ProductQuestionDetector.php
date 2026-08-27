<?php

namespace App\Services\Rag;

/**
 * Whether a customer message is PURELY a product/price/stock question — one that
 * the search_products tool answers completely, so embedding it and searching the
 * knowledge base is latency spent for nothing.
 *
 * Tuned hard for PRECISION, not recall. Missing a skippable question costs a few
 * hundred milliseconds; skipping retrieval on a question that genuinely needed
 * the knowledge base costs a wrong or evasive answer. So every rule below is
 * built to say "no" when unsure:
 *
 *  1. The message must match a product/price/stock pattern.
 *  2. It must NOT match ANY "this needs the knowledge base" pattern. This is the
 *     important half: "сколько стоит доставка?" is a price question whose answer
 *     is a policy, and it is the canonical way a naive keyword check goes wrong.
 *     One veto word anywhere in the message is enough to keep retrieval.
 *  3. It must be short. A long message is usually several questions at once, and
 *     only one of them has to need the knowledge base.
 *
 * Even when it is wrong, the failure is bounded rather than silent: the prompt
 * tells the model the knowledge base was not searched (not that it is empty), so
 * the worst case is "let me check with a colleague" rather than an invented fact
 * or a denial that the business has any information.
 *
 * Covers ru/en/tg, the three locales this product ships in.
 */
class ProductQuestionDetector
{
    /**
     * Longer than this and the message is assumed to be asking more than one
     * thing. Deliberately generous towards keeping retrieval.
     */
    protected const MAX_LENGTH = 120;

    /**
     * Price, stock, availability and "what do you sell" phrasings.
     */
    protected const PRODUCT_PATTERNS = [
        // Russian
        'сколько стоит', 'сколько будет стоить', 'какая цена', 'цена ', 'цену', 'цены',
        'стоимость', 'стоит ли', 'почем', 'почём', 'прайс',
        'в наличии', 'наличие', 'есть в', 'остаток', 'остатки', 'осталось', 'остались',
        'ассортимент', 'каталог', 'что продаете', 'что продаёте', 'какие есть', 'что есть из',
        'дешевле', 'дороже', 'до 1', 'до 2', 'до 3', 'до 4', 'до 5', 'до 6', 'до 7', 'до 8', 'до 9',
        // English
        'how much', 'what price', 'the price', 'price of', 'cost of', 'how many left',
        'in stock', 'availability', 'available', 'do you sell', 'do you have', 'what do you have',
        'cheaper', 'cheapest', 'more expensive', 'under ',
        // Tajik
        'чанд пул', 'чанд сум', 'нархи', 'нарх ', 'арзиш', 'мавҷуд', 'мавчуд', 'дар анбор',
    ];

    /**
     * Anything that makes the knowledge base relevant. One hit vetoes the skip,
     * however product-ish the rest of the message looks.
     *
     * Kept broad on purpose. A false veto costs latency; a missed veto costs an
     * answer.
     */
    protected const KNOWLEDGE_PATTERNS = [
        // Russian — logistics, money policy, guarantees, the business itself
        'доставк', 'достав', 'самовывоз', 'привез', 'привоз', 'отправ', 'курьер',
        'оплат', 'оплач', 'платеж', 'платёж', 'рассрочк', 'кредит', 'наличными', 'картой', 'перевод',
        'гарант', 'возврат', 'обмен', 'брак', 'ремонт', 'сервис',
        'скидк', 'акци', 'промокод', 'бонус', 'подар',
        'график', 'режим', 'часы работы', 'время работы', 'работаете', 'выходн', 'праздн',
        'адрес', 'где вы', 'где нах', 'как добраться', 'филиал', 'магазин наход',
        'контакт', 'телефон', 'whatsapp', 'вайбер', 'инстаграм', 'почта',
        'услуг', 'сертификат', 'лиценз', 'документ', 'договор', 'счет', 'счёт', 'ндс',
        'курс', 'препода', 'учител', 'занят', 'урок', 'расписан', 'запис', 'брон',
        'о компании', 'о вас', 'кто вы', 'отзыв', 'условия', 'политик', 'правил',
        // English
        'deliver', 'shipping', 'ship to', 'pickup', 'pick up', 'courier',
        'payment', 'pay by', 'installment', 'instalment', 'credit', 'invoice', 'vat',
        'warrant', 'guarantee', 'return policy', 'refund', 'exchange', 'repair',
        'discount', 'promo', 'coupon', 'gift',
        'opening hours', 'working hours', 'open on', 'schedule', 'holiday',
        'address', 'where are you', 'located', 'how to get', 'branch',
        'contact', 'phone', 'email',
        'service', 'certificate', 'licen', 'contract', 'terms', 'policy', 'review',
        'course', 'teacher', 'lesson', 'booking', 'appointment',
        // Tajik
        'расониш', 'интиқол', 'интикол', 'пардохт', 'кафолат', 'баргардонидан',
        'тахфиф', 'вақти кор', 'вакти кор', 'ҷадвал', 'чадвал', 'суроға', 'сурога',
        'тамос', 'телефон', 'хизмат', 'шартнома', 'курс', 'муаллим', 'дарс', 'сабти ном',
    ];

    /**
     * True only when retrieval can be skipped with confidence.
     */
    public function isPureProductQuestion(string $message): bool
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $message)));

        if ($text === '' || mb_strlen($text) > self::MAX_LENGTH) {
            return false;
        }

        if ($this->matchesAny($text, self::KNOWLEDGE_PATTERNS)) {
            return false;
        }

        return $this->matchesAny($text, self::PRODUCT_PATTERNS);
    }

    /**
     * @param  string[]  $needles
     */
    protected function matchesAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
