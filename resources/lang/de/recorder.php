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

];
