<?php

return [

    // The dialog on_error.ask shows on the recorded page after something went wrong.
    'ask' => [
        'title' => 'Etwas ist schiefgelaufen',
        'body' => 'Möchten Sie uns eine Aufzeichnung dessen senden, was gerade passiert ist? Sie zeigt die letzten Momente auf dem Bildschirm und hilft uns, das Problem zu finden. Wenn Sie sie senden, zeichnet dieser Tab weiter auf, solange Sie ihn nutzen.',
        'anonymous' => 'Anonym senden, nicht mit meinem Konto verknüpft',
        'share' => 'Aufzeichnung senden',
        'decline' => 'Nicht senden',
        // Under the title when a failed request put the question up, next to the status code and the error page's title.
        'failed' => 'Die letzte Aktion konnte nicht abgeschlossen werden. Sie können es erneut versuchen oder die Seite neu laden, falls es weiterhin passiert.',
        // A short notice after the replay was sent.
        'sent' => 'Danke, die Aufzeichnung wurde gesendet.',
    ],

    // Assist, on the visitor's page: the "Get help" button and form, the panel, the questions (:name is the agent).
    'assist' => [
        'dialog' => 'Hilfe vom Support',
        'getHelp' => 'Hilfe holen',
        'close' => 'Schließen',
        'formTitle' => 'Um Hilfe bitten',
        'formBody' => 'Jemand aus unserem Team kann sich diese Seite mit Ihnen ansehen.',
        'message' => 'Wobei brauchen Sie Hilfe? (optional)',
        'send' => 'Um Hilfe bitten',
        'cancel' => 'Abbrechen',
        'waiting' => 'Warten auf jemanden vom Support…',
        'joining' => ':name kommt dazu…',
        'queued' => ':name hat Ihre Anfrage und meldet sich bei Ihnen.',
        'offer' => ':name möchte Ihnen bei dieser Seite helfen.',
        'watch' => ':name möchte diese Seite sehen, um Ihnen zu helfen.',
        'accept' => 'Annehmen',
        'notNow' => 'Nicht jetzt',
        'sees' => ':name sieht diese Seite',
        'seesScreen' => ':name sieht Ihren Bildschirm',
        'reconnecting' => 'Verbindung wird wiederhergestellt…',
        'rejoining' => ':name ist kurz weg und verbindet sich neu…',
        'controlling' => ':name steuert diese Seite. Mit Escape beenden.',
        'end' => 'Beenden',
        'endControl' => 'Fernsteuerung beenden',
        'mute' => 'Stumm',
        'unmute' => 'Ton an',
        'askControl' => ':name möchte diese Seite steuern: für Sie klicken, scrollen und tippen. Sie können das jederzeit mit Escape beenden.',
        'askMasked' => ':name möchte in Felder tippen, die für sie oder ihn verborgen sind. Erlauben?',
        'askAudio' => ':name möchte mit Ihnen sprechen. Mikrofon erlauben?',
        'askVideo' => ':name möchte Sie sehen. Kamera und Mikrofon erlauben?',
        'askScreen' => ':name möchte Ihren Bildschirm sehen. Ihr Browser fragt, was Sie teilen möchten.',
        'allow' => 'Erlauben',
        'deny' => 'Nicht erlauben',
        'ended' => 'Die Hilfe-Sitzung ist beendet.',
        'expired' => 'Leider war niemand rechtzeitig verfügbar. Bitte versuchen Sie es später erneut.',
        'withdrawn' => 'Das Hilfsangebot wurde zurückgezogen.',
        'failed' => 'Ihre Anfrage konnte nicht gesendet werden. Bitte versuchen Sie es erneut.',
        'update' => 'Der Support nutzt eine andere Version dieser Seite. Bitte laden Sie sie neu.',
        'support' => 'Support',
    ],

];
