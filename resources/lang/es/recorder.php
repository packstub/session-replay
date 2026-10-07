<?php

return [

    // The dialog on_error.ask shows on the recorded page after something went wrong.
    'ask' => [
        'title' => 'Algo salió mal',
        'body' => '¿Quieres enviarnos una grabación de lo que acaba de pasar? Muestra los últimos momentos en pantalla y nos ayuda a encontrar el problema. Si la envías, esta pestaña sigue grabando mientras la uses.',
        'anonymous' => 'Enviarla de forma anónima, sin vincularla a mi cuenta',
        'share' => 'Enviar grabación',
        'decline' => 'No enviar',
        // Under the title when a failed request put the question up, next to the status code and the error page's title.
        'failed' => 'La última acción no se pudo completar. Puedes intentarlo de nuevo o recargar la página si sigue ocurriendo.',
        // A short notice after the replay was sent.
        'sent' => 'Gracias, la grabación se ha enviado.',
    ],

];
