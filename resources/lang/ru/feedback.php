<?php

/**
 * Strings a CUSTOMER sees around rating a reply: why they are being asked,
 * and the confirmation once they have. Kept short — the confirmations land
 * inside a Telegram button and a toast, both of which truncate.
 */
return [
    'prompt' => 'Оцените, пожалуйста, ответ — это помогает нам отвечать лучше.',

    'confirm' => [
        'thanks' => 'Спасибо за оценку!',
        'already' => 'Вы уже оценили этот ответ',
        'expired' => 'Оценка больше недоступна',
    ],
];
