<?php

return [

    // The dialog on_error.ask shows on the recorded page after something went wrong.
    'ask' => [
        'title' => 'Something went wrong',
        'body' => 'Would you like to send us a replay of what just happened? It shows the last moments on screen and helps us find the problem. If you send it, this tab keeps recording while you use it.',
        'anonymous' => 'Send it anonymously, not linked to my account',
        'share' => 'Send replay',
        'decline' => 'Don’t send',
        // Under the title when a failed request put the question up, next to the status code and the error page's title.
        'failed' => 'The last action could not be completed. You can try again, or reload the page if it keeps happening.',
        // A short notice after the replay was sent.
        'sent' => 'Thanks, the replay was sent.',
    ],

    // Assist, on the visitor's page: the "Get help" button and form, the panel, the questions (:name is the agent).
    'assist' => [
        'dialog' => 'Help from support',
        'getHelp' => 'Get help',
        'close' => 'Close',
        'formTitle' => 'Ask for help',
        'formBody' => 'Someone from our team can look at this page with you.',
        'message' => 'What do you need help with? (optional)',
        'send' => 'Ask for help',
        'cancel' => 'Cancel',
        'waiting' => 'Waiting for someone from support…',
        'joining' => ':name is joining…',
        'queued' => ':name has your request and will get back to you.',
        'offer' => ':name would like to help you with this page.',
        'watch' => ':name would like to see this page to help you.',
        'accept' => 'Accept',
        'notNow' => 'Not now',
        'sees' => ':name sees this page',
        'seesScreen' => ':name sees your screen',
        'reconnecting' => 'Reconnecting…',
        'controlling' => ':name is controlling this page. Press Escape to stop.',
        'end' => 'End',
        'endControl' => 'End remote control',
        'mute' => 'Mute',
        'unmute' => 'Unmute',
        'askControl' => ':name would like to control this page: click, scroll and type for you. You can stop it at any time with Escape.',
        'askMasked' => ':name would like to type into fields that are hidden from them. Allow it?',
        'askAudio' => ':name would like to talk with you. Allow your microphone?',
        'askVideo' => ':name would like to see you. Allow your camera and microphone?',
        'askScreen' => ':name would like to see your screen. Your browser will ask what to share.',
        'allow' => 'Allow',
        'deny' => 'Don’t allow',
        'ended' => 'The help session has ended.',
        'expired' => 'Nobody was available in time. Please try again later.',
        'withdrawn' => 'The offer of help was withdrawn.',
        'failed' => 'Your request could not be sent. Please try again.',
        'update' => 'Support uses another version of this page. Please reload it.',
        'support' => 'Support',
    ],

];
