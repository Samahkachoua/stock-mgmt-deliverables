<?php

// Brouillons de réception et commandes : ils ne changent pas le stock avant d’être
// confirmés ; la confirmation crée une réception / un bon de livraison dans l’entrepôt du brouillon.
return [
    'types' => [
        'receipt' => [
            'title' => 'Brouillons de réception',
            'singular' => 'Brouillon de réception',
            'new' => 'Nouveau brouillon de réception',
            'subtitle' => 'Stock attendu d’une agence du client. Le stock ne change pas tant que le brouillon n’est pas confirmé.',
            'form_subtitle' => 'Indiquez ce qui arrive. Le stock change seulement à la confirmation.',
            'branch' => 'Agence d’origine',
            'branch_hint' => 'L’agence du client d’où viennent les marchandises.',
            'warehouse_hint' => 'L’entrepôt où entrent les marchandises.',
            'confirm' => 'Confirmer et réceptionner',
            'created_heading' => 'Réceptions créées',
            'empty_title' => 'Aucun brouillon de réception',
            'empty_body' => 'Utilisez « Nouveau brouillon de réception » pour noter du stock en route.',
        ],
        'order' => [
            'title' => 'Commandes',
            'singular' => 'Commande',
            'new' => 'Nouvelle commande',
            'subtitle' => 'Stock demandé pour une agence du client. Une commande réserve son stock ; il ne sort qu’à la livraison du bon de livraison.',
            'form_subtitle' => 'Indiquez ce qui doit sortir. L’enregistrer le réserve : rien d’autre ne peut le prendre.',
            'branch' => 'Agence de destination',
            'branch_hint' => 'L’agence du client où vont les marchandises.',
            'delivery_date' => 'Date de livraison',
            'warehouse_hint' => 'L’entrepôt d’où sortent les marchandises.',
            'confirm' => 'Confirmer et livrer',
            'created_heading' => 'Bons de livraison créés',
            'empty_title' => 'Aucune commande pour l’instant',
            'empty_body' => 'Utilisez « Nouvelle commande » pour noter du stock à livrer.',
        ],
    ],

    'status' => [
        'draft' => 'Brouillon',
        'confirmed' => 'Confirmé',
        'cancelled' => 'Annulé',
    ],

    // Orders: how urgent (stock_draft.priority).
    'priority' => [
        'label' => 'Priorité',
        'URGENT' => 'Urgente',
        'NORMAL' => 'Normale',
        'LOW' => 'Basse',
    ],

    'filters' => [
        'branch' => 'Agence',
        'all_branches' => 'Toutes les agences',
        'drafts_first' => 'Tous les statuts, brouillons d’abord',
        'search_placeholder' => 'Rechercher une référence, un client ou une agence…',
    ],

    'list' => [
        'warehouse' => 'Entrepôt',
    ],

    'details' => [
        'lines_heading' => 'Articles',
        'warehouse' => 'Entrepôt',
        'confirmed_by' => 'Confirmé par',
        'confirmed_on' => 'Confirmé le',
        'cancel_reason' => 'Motif de l’annulation',
        'not_in_stock_in' => 'Pas encore en stock. La confirmation crée la réception dans :warehouse.',
        'not_in_stock_out' => 'Réservé à :warehouse. La confirmation crée le bon de livraison, qui le garde réservé jusqu’à la livraison.',
        'waiting_for_confirmation' => 'En attente d’une personne autorisée à le confirmer.',
    ],

    'form' => [
        'edit_title' => 'Modifier :reference',
        'choose_branch' => 'Choisir une agence',
        'no_branches' => 'Ce client n’a pas encore d’agence. Ajoutez-les dans Paramètres › Agences.',
        'lines_heading' => 'Articles',
        'choose_warehouse' => 'Choisir…',
        'notice_in' => 'Enregistrer ne change pas le stock. Une fois confirmé, ce sera une réception.',
        'notice_out' => 'Enregistrer réserve le stock : il reste dans l’entrepôt mais rien d’autre ne peut le prendre. Une fois confirmée, ce sera un bon de livraison.',
    ],

    'actions' => [
        'edit' => 'Modifier',
        'cancel' => 'Annuler :type',
        'save' => 'Enregistrer :type',
        'save_and_confirm' => 'Enregistrer et confirmer',
        'confirming' => 'Confirmation…',
    ],

    'confirm_cancel' => [
        'title' => 'Annuler :type :reference ?',
        'body' => 'Il ne pourra plus être modifié ni confirmé. Le stock n’est pas touché.',
        'body_order' => 'Elle ne pourra plus être modifiée ni confirmée. Le stock qu’elle réservait redevient disponible.',
        'button' => 'Annuler :type',
        'reason' => 'Pourquoi est-il annulé ?',
        'reason_placeholder' => 'ex. L’agence a retiré la commande',
    ],

    'feedback' => [
        'saved' => ':type :reference enregistré',
        'updated' => ':type :reference enregistré',
        'confirmed' => ':type :reference confirmé — :documents',
        'documents_in' => '{1} 1 réception créée|[2,*] :count réceptions créées',
        'documents_out' => '{1} 1 bon de livraison créé|[2,*] :count bons de livraison créés',
        'cancelled' => ':type :reference annulé',
    ],

    'leave' => [
        'title' => 'Abandonner vos modifications de :reference ?',
        'title_new' => 'Abandonner ce nouveau :type ?',
    ],

    // E-mail aux personnes qui peuvent confirmer un brouillon ajouté, modifié ou
    // annulé (App\Mail\DraftEmail) : textes par type de brouillon, puis libellés communs.
    'mail' => [
        'urgent_subject' => 'Urgent : :subject',
        'order' => [
            'subject' => [
                'created' => 'Nouvelle commande :reference de :customer',
                'updated' => 'La commande :reference de :customer a été modifiée',
                'cancelled' => 'La commande :reference de :customer a été annulée',
            ],
            'intro' => [
                'created' => ':name a envoyé une commande. Ouvrez-la pour la vérifier et la confirmer ; le stock ne change qu’à la confirmation.',
                'updated' => ':name a modifié cette commande. Ouvrez-la pour voir les lignes telles qu’elles sont maintenant avant de la confirmer.',
                'cancelled' => ':name a annulé cette commande. Elle ne peut plus être confirmée ; le stock n’est pas touché.',
            ],
            'branch' => 'Vers l’agence',
            'open' => 'Ouvrir la commande',
            'why' => 'Vous recevez cet e-mail car vous pouvez confirmer les commandes de cet entrepôt. Un administrateur peut le désactiver dans Paramètres › Utilisateurs.',
        ],
        'receipt' => [
            'subject' => [
                'created' => 'Nouveau brouillon de réception :reference de :customer',
                'updated' => 'Le brouillon de réception :reference de :customer a été modifié',
                'cancelled' => 'Le brouillon de réception :reference de :customer a été annulé',
            ],
            'intro' => [
                'created' => ':name a indiqué des marchandises en route. Ouvrez le brouillon de réception pour le vérifier et le confirmer à leur arrivée ; le stock ne change qu’à la confirmation.',
                'updated' => ':name a modifié ce brouillon de réception. Ouvrez-le pour voir les lignes telles qu’elles sont maintenant avant de le confirmer.',
                'cancelled' => ':name a annulé ce brouillon de réception. Il ne peut plus être confirmé ; le stock n’est pas touché.',
            ],
            'branch' => 'Depuis l’agence',
            'open' => 'Ouvrir le brouillon de réception',
            'why' => 'Vous recevez cet e-mail car vous pouvez confirmer les brouillons de réception de cet entrepôt. Un administrateur peut le désactiver dans Paramètres › Utilisateurs.',
        ],
        'reason' => 'Motif',
        'customer' => 'Client',
        'warehouse' => 'Entrepôt',
        'date' => 'Date',
        'items' => 'Articles',
        'note' => 'Référence',
    ],

    'errors' => [
        'delivery_date_required' => 'Choisissez une date de livraison.',
        'delivery_date_before' => 'La date de livraison ne peut pas précéder la date de la commande.',
        'priority_required' => 'Choisissez une priorité.',
        'already_confirmed' => 'Ce document a déjà été confirmé par :name à :time.',
        'already_cancelled' => 'Ce document a été annulé.',
        'changed' => ':name a modifié ce document entre-temps. Rouvrez-le pour voir les modifications.',
        'cannot_create' => 'Vous n’avez pas le droit de créer ce document.',
        'cannot_edit' => 'Vous n’avez pas le droit de modifier ce document.',
        'cannot_confirm' => 'Vous n’avez pas le droit de confirmer ce document.',
        'cannot_cancel' => 'Vous n’avez pas le droit d’annuler ce document.',
        'warehouse_not_allowed' => 'Vous ne travaillez pas à :warehouse, vous ne pouvez donc pas confirmer ce document.',
        'branch_required' => 'Choisissez une agence.',
        'branch_not_allowed' => 'Vous ne pouvez pas utiliser cette agence.',
        'choose_warehouse' => 'Choisissez un entrepôt.',
    ],
];
