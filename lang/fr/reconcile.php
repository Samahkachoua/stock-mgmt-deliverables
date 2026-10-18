<?php

// The nightly stock check email (stock:reconcile).
return [
    'mail' => [
        'subject' => ':count solde(s) de stock ne correspondent pas aux mouvements :app',
        'intro' => 'La vérification nocturne du stock a trouvé :count solde(s) différents de la somme de leurs mouvements (entrepôt / client / article / date de péremption) :',
        'line' => 'solde :balance, total des mouvements :movements',
        'more' => '… et :count de plus (voir le journal de l’application).',
        'what_next' => 'Rien n’a été modifié. Merci de vérifier avant de vous fier à ces quantités : un tel écart signifie que le stock a changé en dehors des écrans habituels.',
    ],
];
