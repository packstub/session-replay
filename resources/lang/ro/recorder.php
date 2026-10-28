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

    // Assist, on the visitor's page: the "Get help" button and form, the panel, the questions (:name is the agent).
    'assist' => [
        'dialog' => 'Ajutor de la suport',
        'getHelp' => 'Cere ajutor',
        'close' => 'Închide',
        'formTitle' => 'Cere ajutor',
        'formBody' => 'Cineva din echipa noastră poate privi această pagină împreună cu tine.',
        'message' => 'Cu ce ai nevoie de ajutor? (opțional)',
        'send' => 'Cere ajutor',
        'cancel' => 'Anulează',
        'waiting' => 'Așteptăm pe cineva de la suport…',
        'joining' => ':name se alătură…',
        'queued' => ':name a preluat cererea ta și îți va răspunde.',
        'offer' => ':name ar vrea să te ajute cu această pagină.',
        'watch' => ':name ar vrea să vadă această pagină ca să te ajute.',
        'accept' => 'Acceptă',
        'notNow' => 'Nu acum',
        'sees' => ':name vede această pagină',
        'seesScreen' => ':name îți vede ecranul',
        'reconnecting' => 'Se reconectează…',
        'controlling' => ':name controlează această pagină. Apasă Escape ca să oprești.',
        'end' => 'Încheie',
        'endControl' => 'Oprește controlul de la distanță',
        'mute' => 'Fără sunet',
        'unmute' => 'Cu sunet',
        'askControl' => ':name ar vrea să controleze această pagină: să dea clic, să deruleze și să scrie pentru tine. Poți opri oricând cu Escape.',
        'askMasked' => ':name ar vrea să scrie în câmpuri care îi sunt ascunse. Permiți?',
        'askAudio' => ':name ar vrea să vorbească cu tine. Permiți microfonul?',
        'askVideo' => ':name ar vrea să te vadă. Permiți camera și microfonul?',
        'askScreen' => ':name ar vrea să-ți vadă ecranul. Browserul te va întreba ce vrei să partajezi.',
        'allow' => 'Permite',
        'deny' => 'Nu permite',
        'ended' => 'Sesiunea de ajutor s-a încheiat.',
        'expired' => 'Nimeni nu a fost disponibil la timp. Încearcă din nou mai târziu.',
        'withdrawn' => 'Oferta de ajutor a fost retrasă.',
        'failed' => 'Cererea ta nu a putut fi trimisă. Încearcă din nou.',
        'update' => 'Suportul folosește altă versiune a acestei pagini. Reîncarcă pagina.',
        'support' => 'Suport',
    ],

];
