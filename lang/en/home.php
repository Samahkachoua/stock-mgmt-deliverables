<?php

// The page after signing in (App\Http\Controllers\HomeController).
return [
    'page_title' => 'Home',
    'title' => 'Hello, :name',
    'subtitle' => 'What would you like to do?',
    'waiting' => 'Waiting for you',
    'approvals' => '{0} :type, no approval needed|{1} :type waiting for your approval|[2,*] :type waiting for your approval',
    'arriving' => '{0} No transfers arriving|{1} Transfer arriving here|[2,*] Transfers arriving here',
    'start' => 'Start something new',
    'nothing_to_create' => 'You can look at stock and documents, but not create them. Ask an administrator if you need to.',
];
