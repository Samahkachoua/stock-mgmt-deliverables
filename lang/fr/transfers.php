<?php

return [
    'from_warehouse' => 'Depuis l’entrepôt',
    'in_transit_notice' => 'Le stock quitte cet entrepôt maintenant et reste en transit jusqu’à ce que :warehouse confirme ce qui est arrivé.',
    'send_to' => 'Envoyer à :warehouse',
    'dispatched' => ':reference a été envoyé. Il reste en transit jusqu’à la confirmation de réception par l’autre entrepôt.',
    'received' => ':reference a été réceptionné. Le stock est à jour.',
    'received_with_shortage' => ':reference a été réceptionné avec un manque. Il figure dans Manques de transfert.',

    'direction' => [
        'label' => 'Sens',
        'all' => 'Entrants et sortants',
        'incoming' => 'Entrants',
        'outgoing' => 'Sortants',
    ],

    'reasons' => [
        'DAMAGED_IN_TRANSIT' => 'Abîmé pendant le transport',
        'MISSING' => 'Manquant',
        'COUNTING_ERROR' => 'Erreur de comptage',
    ],

    'receive' => [
        'heading' => 'Vérifier ce qui est arrivé',
        'hint' => 'Saisissez la quantité arrivée pour chaque ligne. S’il en est arrivé moins que prévu, choisissez pourquoi.',
        'received' => 'Reçu',
        'reason' => 'Motif de l’écart',
        'choose_reason' => 'Choisissez un motif',
        'difference' => 'Manque : :qty',
        'confirm' => 'Confirmer la réception',
        'final_hint' => 'Une fois confirmé, le stock est ajouté ici et ce transfert ne peut plus être modifié ni annulé.',
        'serials_hint' => 'Décochez les numéros de série qui ne sont pas arrivés.',
        'read_only' => 'Seul :warehouse peut confirmer ce qui est arrivé.',
    ],

    'details' => [
        'dispatched_on' => 'Envoyé le',
        'received_on' => 'Reçu le',
        'shortage' => 'Manque : :qty :reason',
    ],

    'shortages' => [
        'link' => 'Manques de transfert',
        'columns' => [
            'received_on' => 'Reçu le',
            'sent' => 'Envoyé',
            'received' => 'Reçu',
            'short' => 'Manque',
            'reason' => 'Motif',
            'received_by' => 'Reçu par',
        ],
    ],

    'errors' => [
        'already_received' => 'Ce transfert a déjà été réceptionné par :name le :time.',
        'not_in_transit' => 'Ce transfert n’est plus en transit.',
        'not_destination' => 'Seules les personnes de l’entrepôt de destination peuvent confirmer ce qui est arrivé.',
        'received_required' => 'Saisissez la quantité arrivée.',
        'more_than_sent' => 'Plus que ce qui a été envoyé (:qty).',
        'serial_not_sent' => 'Le numéro de série :serial n’a pas été envoyé avec cette ligne.',
        'reason_required' => 'Choisissez pourquoi il en est arrivé moins.',
        'received_cannot_cancel' => 'Ce transfert a déjà été réceptionné par :name le :time : il ne peut plus être annulé.',
        'only_sender_cancels' => 'Seul l’entrepôt expéditeur peut annuler un transfert.',
    ],
];
