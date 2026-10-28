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

    // Assist, on the visitor's page: the "Get help" button and form, the panel, the questions (:name is the agent).
    'assist' => [
        'dialog' => 'Ayuda del equipo de soporte',
        'getHelp' => 'Pedir ayuda',
        'close' => 'Cerrar',
        'formTitle' => 'Pedir ayuda',
        'formBody' => 'Alguien de nuestro equipo puede ver esta página contigo.',
        'message' => '¿Con qué necesitas ayuda? (opcional)',
        'send' => 'Pedir ayuda',
        'cancel' => 'Cancelar',
        'waiting' => 'Esperando a alguien del equipo de soporte…',
        'joining' => ':name se está uniendo…',
        'queued' => ':name tiene tu solicitud y te responderá.',
        'offer' => ':name quiere ayudarte con esta página.',
        'watch' => ':name quiere ver esta página para ayudarte.',
        'accept' => 'Aceptar',
        'notNow' => 'Ahora no',
        'sees' => ':name ve esta página',
        'seesScreen' => ':name ve tu pantalla',
        'reconnecting' => 'Reconectando…',
        'rejoining' => ':name se ha ausentado y se está reconectando…',
        'controlling' => ':name está controlando esta página. Pulsa Escape para detenerlo.',
        'end' => 'Terminar',
        'endControl' => 'Terminar el control remoto',
        'mute' => 'Silenciar',
        'unmute' => 'Activar sonido',
        'askControl' => ':name quiere controlar esta página: hacer clic, desplazarse y escribir por ti. Puedes detenerlo en cualquier momento con Escape.',
        'askMasked' => ':name quiere escribir en campos que tiene ocultos. ¿Lo permites?',
        'askAudio' => ':name quiere hablar contigo. ¿Permites el micrófono?',
        'askVideo' => ':name quiere verte. ¿Permites la cámara y el micrófono?',
        'askScreen' => ':name quiere ver tu pantalla. Tu navegador te preguntará qué compartir.',
        'allow' => 'Permitir',
        'deny' => 'No permitir',
        'ended' => 'La sesión de ayuda ha terminado.',
        'expired' => 'Nadie estaba disponible a tiempo. Inténtalo de nuevo más tarde.',
        'withdrawn' => 'La oferta de ayuda se ha retirado.',
        'failed' => 'No se pudo enviar tu solicitud. Inténtalo de nuevo.',
        'update' => 'El equipo de soporte usa otra versión de esta página. Vuelve a cargarla.',
        'support' => 'Soporte',
    ],

];
