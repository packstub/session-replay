<?php

return [

    // The dialog on_error.ask shows on the recorded page after something went wrong.
    'ask' => [
        'title' => 'Ceva nu a mers bine',
        'body' => 'Vrei să ne trimiți o înregistrare a ceea ce tocmai s-a întâmplat? Arată ultimele momente de pe ecran și ne ajută să găsim problema. Dacă o trimiți, această filă continuă să înregistreze cât timp o folosești.',
        'anonymous' => 'Trimite anonim, fără legătură cu contul meu',
        'share' => 'Trimite înregistrarea',
        'decline' => 'Nu trimite',
        // Under the title when a failed request put the question up, next to the status code and the error page's title.
        'failed' => 'Ultima acțiune nu a putut fi finalizată. Poți încerca din nou sau reîncărca pagina dacă se repetă.',
        // A short notice after the replay was sent.
        'sent' => 'Mulțumim, înregistrarea a fost trimisă.',
    ],

];
