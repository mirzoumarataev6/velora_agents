<?php

/**
 * Canned replies a CUSTOMER sees when the owner has not configured their own.
 */
return [
    'greeting' => 'Hello! How can I help you?',
    'photo' => [
        'sent' => 'Photo of :model sent.',
        'missing' => 'I checked for a photo of :model. The available sources currently have no suitable image of this model.',
        'clarify' => 'Which exact model would you like to see? Please send its name or price so I show the right product.',
        'choose' => 'Which model would you like to see? Reply with its number or name:',
        'failed' => 'I could not send a photo of :model right now. Please try again shortly.',
        'sent_all' => 'Sent photos of all the offered options.',
        'sent_some' => 'Sent photos of the options a matching image was found for.',
        'none_found' => 'There is no suitable photo yet for the offered options in the available sources.',
    ],
];
