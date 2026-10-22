<?php

namespace App\Services\Rag;

class ProductQuestionDetector
{
    protected const MAX_LENGTH = 120;

    protected const PRODUCT_PATTERNS = [

        'сколько стоит', 'сколько будет стоить', 'какая цена', 'цена ', 'цену', 'цены',
        'стоимость', 'стоит ли', 'почем', 'почём', 'прайс',
        'в наличии', 'наличие', 'есть в', 'остаток', 'остатки', 'осталось', 'остались',
        'ассортимент', 'каталог', 'что продаете', 'что продаёте', 'какие есть', 'что есть из',
        'дешевле', 'дороже', 'до 1', 'до 2', 'до 3', 'до 4', 'до 5', 'до 6', 'до 7', 'до 8', 'до 9',

        'how much', 'what price', 'the price', 'price of', 'cost of', 'how many left',
        'in stock', 'availability', 'available', 'do you sell', 'do you have', 'what do you have',
        'cheaper', 'cheapest', 'more expensive', 'under ',

        'чанд пул', 'чанд сум', 'нархи', 'нарх ', 'арзиш', 'мавҷуд', 'мавчуд', 'дар анбор',
    ];

    protected const KNOWLEDGE_PATTERNS = [

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

        'deliver', 'shipping', 'ship to', 'pickup', 'pick up', 'courier',
        'payment', 'pay by', 'installment', 'instalment', 'credit', 'invoice', 'vat',
        'warrant', 'guarantee', 'return policy', 'refund', 'exchange', 'repair',
        'discount', 'promo', 'coupon', 'gift',
        'opening hours', 'working hours', 'open on', 'schedule', 'holiday',
        'address', 'where are you', 'located', 'how to get', 'branch',
        'contact', 'phone', 'email',
        'service', 'certificate', 'licen', 'contract', 'terms', 'policy', 'review',
        'course', 'teacher', 'lesson', 'booking', 'appointment',

        'расониш', 'интиқол', 'интикол', 'пардохт', 'кафолат', 'баргардонидан',
        'тахфиф', 'вақти кор', 'вакти кор', 'ҷадвал', 'чадвал', 'суроға', 'сурога',
        'тамос', 'телефон', 'хизмат', 'шартнома', 'курс', 'муаллим', 'дарс', 'сабти ном',
    ];

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
