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

];
