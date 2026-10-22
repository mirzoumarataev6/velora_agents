<?php

namespace App\Services\Leads;

class OrderConfirmationGuard
{
    protected const CLAIMS = [

        'заявка принята', 'заявка принят', 'заявку принял', 'заявка оформлена',
        'заказ принят', 'заказ оформлен', 'заказ записан', 'заказ зафиксирован',
        'я оформлю', 'мы оформим', 'оформим вашу заявку', 'оформим ваш заказ',
        'оформил вашу заявку', 'оформила вашу заявку', 'оформили вашу заявку',
        'зафиксирую', 'зафиксировал', 'зафиксировала', 'записал ваш заказ',
        'записала ваш заказ', 'записал вашу заявку', 'принял вашу заявку',
        'передам информацию о вашем заказе', 'ваша заявка принята', 'ваш заказ принят',

        'қабул шуд', 'кабул шуд', 'қабул кардам', 'кабул кардам', 'қабул карда шуд',
        'кабул карда шуд', 'дархости шумо қабул', 'дархости шумо кабул',
        'фармоиши шумо қабул', 'фармоиши шумо кабул',

        'order is placed', 'order has been placed', 'order was placed', 'placed your order',
        'order accepted', 'order is accepted', 'recorded your order', 'order recorded',
        'noted your order', 'i have registered your order',
    ];

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
