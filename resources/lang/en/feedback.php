<?php

/**
 * Strings a CUSTOMER sees around rating a reply: why they are being asked,
 * and the confirmation once they have. Kept short — the confirmations land
 * inside a Telegram button and a toast, both of which truncate.
 */
return [
    'prompt' => 'Please rate this reply — it helps us answer better.',

    'confirm' => [
        'thanks' => 'Thanks for the feedback!',
        'already' => 'You have already rated this reply',
        'expired' => 'This rating is no longer available',
    ],
];
