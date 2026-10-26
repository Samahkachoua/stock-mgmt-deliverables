<?php

// The nightly stock check email (stock:reconcile).
return [
    'mail' => [
        'subject' => ':count stock balance(s) don’t match their movements :app',
        'intro' => 'The nightly stock check found :count stock balance(s) that are different from the sum of their movements (warehouse / customer / item / expiry date):',
        'line' => 'balance :balance, movements add up to :movements',
        'line_reserved' => 'reserved :balance, order reservations add up to :movements',
        'more' => '… and :count more (see the application log).',
        'what_next' => 'Nothing was changed. Please look into it before people rely on these quantities: such a difference means something changed stock outside the normal screens.',
    ],
];
