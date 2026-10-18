<?php

return [
    /*
    | Who receives the nightly stock check (stock:reconcile) when it finds
    | balances that don't match the movements: comma-separated addresses.
    | Empty = every active user with the ADMIN role.
    */
    'reconcile_mail_to' => env('RECONCILE_MAIL_TO', ''),
];
