<?php

return [

    // The dialog on_error.ask shows on the recorded page after something went wrong.
    'ask' => [
        'title' => 'Что-то пошло не так',
        'body' => 'Хотите отправить нам запись того, что только что произошло? Она показывает последние моменты на экране и помогает нам найти проблему. Если вы её отправите, эта вкладка продолжит запись, пока вы ею пользуетесь.',
        'anonymous' => 'Отправить анонимно, без привязки к моему аккаунту',
        'share' => 'Отправить запись',
        'decline' => 'Не отправлять',
        // Under the title when a failed request put the question up, next to the status code and the error page's title.
        'failed' => 'Последнее действие не удалось выполнить. Вы можете попробовать ещё раз или перезагрузить страницу, если это повторится.',
        // A short notice after the replay was sent.
        'sent' => 'Спасибо, запись отправлена.',
    ],

];
