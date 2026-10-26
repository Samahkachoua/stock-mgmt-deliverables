<?php

// Étapes du bon de livraison : Confirmé (depuis une commande, stock réservé) -> Expédié -> Livré.
return [
    'reasons' => [
        'NOT_AVAILABLE' => 'Introuvable dans l’entrepôt',
        'REFUSED' => 'Refusé par le client',
        'DAMAGED' => 'Abîmé',
        'REPLACED' => 'Un autre article livré à la place',
    ],

    'dispatch' => [
        'button' => 'Expédier',
        'title' => 'Expédier le bon de livraison :reference ?',
        'hint' => 'Qui le transporte ? Tous les champs sont facultatifs.',
        'driver_name' => 'Nom du chauffeur',
        'driver_phone' => 'Téléphone du chauffeur',
        'truck_no' => 'Numéro du camion',
        'confirm' => 'Expédier',
        'keep' => 'Pas maintenant',
    ],

    'deliver' => [
        'heading' => 'Confirmer ce qui a été livré',
        'hint' => 'Saisissez la quantité livrée pour chaque ligne. Si moins a été livré que commandé, choisissez pourquoi.',
        'ordered' => 'Commandé',
        'delivered' => 'Livré',
        'reason' => 'Motif de l’écart',
        'choose_reason' => 'Choisir un motif',
        'difference' => ':qty en moins',
        'instead' => 'Livré à la place',
        'instead_item' => 'Article livré à la place de :item',
        'instead_qty' => 'Quantité livrée à la place',
        'instead_expiry' => 'Date de péremption',
        'confirm' => 'Confirmer la livraison',
        'final_hint' => 'Les quantités livrées sortent du stock maintenant. Ce qui n’a pas été livré redevient disponible.',
        'serials_hint' => 'Décochez les numéros de série qui n’ont pas été livrés.',
        'reserved_notice' => 'Le stock de ce bon de livraison est réservé : rien d’autre ne peut le prendre tant qu’il n’est pas livré ou annulé.',
        'read_only' => 'Seules les personnes qui livrent depuis :warehouse peuvent confirmer la livraison.',
        'dispatch_first' => 'Expédiez d’abord ce bon de livraison ; une fois parti, confirmez ici ce qui a été livré.',
    ],

    'list' => [
        'short' => 'Incomplet',
    ],

    'details' => [
        'dispatched_on' => 'Expédié le',
        'delivered_on' => 'Livré le',
        'driver' => 'Chauffeur',
        'driver_phone' => 'Téléphone du chauffeur',
        'truck' => 'Camion',
        'short' => 'En moins : :qty, :reason',
        'instead_of' => 'Livré à la place de :item',
    ],

    'differences' => [
        'columns' => [
            'delivered_on' => 'Livré le',
            'order' => 'Commande',
            'ordered' => 'Commandé',
            'delivered' => 'Livré',
            'short' => 'En moins',
            'reason' => 'Motif',
            'instead' => 'Livré à la place',
            'delivered_by' => 'Confirmé par',
        ],
    ],

    // Email to the order's branch manager once its Delivery note is delivered (App\Mail\DeliveryConfirmedEmail).
    'mail' => [
        'subject' => 'Livré : commande :order (:reference) pour :branch',
        'intro' => 'Les marchandises de la commande :order pour :branch (:customer) ont été livrées le :date.',
        'customer' => 'Client',
        'branch' => 'Agence',
        'order' => 'Commande',
        'delivery_note' => 'Bon de livraison',
        'warehouse' => 'Depuis l’entrepôt',
        'delivered_on' => 'Livré le',
        'confirmed_by' => 'Confirmé par',
        'driver' => 'Chauffeur',
        'truck' => 'Camion',
        'note' => 'Note de référence',
        'item' => 'Article',
        'ordered' => 'Commandé',
        'delivered' => 'Livré',
        'instead_of' => 'Livré à la place de :item',
        'why' => 'Vous recevez cet e-mail en tant que responsable de l’agence :branch de :customer.',
    ],

    'errors' => [
        'already_dispatched' => 'Ce bon de livraison a déjà été expédié par :name à :time.',
        'not_dispatched' => 'Expédiez ce bon de livraison avant de confirmer la livraison.',
        'already_delivered' => 'Ce bon de livraison a déjà été livré : confirmé par :name à :time.',
        'cancelled' => 'Ce bon de livraison a été annulé.',
        'not_allowed' => 'Vous n’avez pas le droit d’expédier ou de livrer des bons de livraison.',
        'delivered_required' => 'Saisissez la quantité livrée.',
        'more_than_ordered' => 'Plus que la quantité commandée (:qty).',
        'serial_not_ordered' => 'Le numéro de série :serial n’est pas sur cette ligne.',
        'reason_required' => 'Choisissez pourquoi moins a été livré.',
        'instead_needs_replaced' => 'Choisissez « Un autre article livré à la place » comme motif.',
        'instead_required' => 'Choisissez l’article livré à la place.',
    ],
];
