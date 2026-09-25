<?php

/*
| Russian messages for the rules the API uses. Anything missing falls back to
| English (APP_FALLBACK_LOCALE); add keys here as new rules are introduced.
*/

return [
    'after' => 'Поле «:attribute» должно быть датой после :date.',
    'alpha_dash' => 'Поле «:attribute» может содержать только буквы, цифры, дефис и подчёркивание.',
    'array' => 'Поле «:attribute» должно быть списком.',
    'boolean' => 'Поле «:attribute» должно быть да или нет.',
    'date' => 'Поле «:attribute» должно быть датой.',
    'email' => 'Введите корректный адрес эл. почты.',
    'enum' => 'Выбрано недопустимое значение поля «:attribute».',
    'in' => 'Выбрано недопустимое значение поля «:attribute».',
    'integer' => 'Поле «:attribute» должно быть целым числом.',
    'max' => [
        'array' => 'В поле «:attribute» может быть не более :max элементов.',
        'numeric' => 'Поле «:attribute» не может быть больше :max.',
        'string' => 'Поле «:attribute» может содержать не более :max символов.',
    ],
    'min' => [
        'array' => 'В поле «:attribute» должно быть не менее :min элементов.',
        'numeric' => 'Поле «:attribute» должно быть не меньше :min.',
        'string' => 'Поле «:attribute» должно содержать не менее :min символов.',
    ],
    'password' => [
        'letters' => 'Пароль должен содержать хотя бы одну букву.',
        'mixed' => 'Пароль должен содержать строчные и заглавные буквы.',
        'numbers' => 'Пароль должен содержать хотя бы одну цифру.',
        'symbols' => 'Пароль должен содержать хотя бы один символ.',
        'uncompromised' => 'Этот пароль встречался в утечках данных. Выберите другой.',
    ],
    'present' => 'Поле «:attribute» должно присутствовать.',
    'required' => 'Заполните поле «:attribute».',
    'string' => 'Поле «:attribute» должно быть строкой.',
    'unique' => 'Такое значение поля «:attribute» уже используется.',

    'attributes' => [
        'code' => 'код',
        'count' => 'количество',
        'device_name' => 'имя устройства',
        'duration_days' => 'срок действия',
        'email' => 'эл. почта',
        'expires_at' => 'действует до',
        'name' => 'имя',
        'note' => 'заметка',
        'password' => 'пароль',
        'per_page' => 'размер страницы',
        'plan' => 'тариф',
        'reason' => 'причина',
        'refresh_token' => 'токен обновления',
        'roles' => 'роли',
        'status' => 'статус',
        'token' => 'токен',
    ],
];
