<?php

// Toast messages (CLAUDE.md "Feedback messages"): results of actions, and errors that
// have no field to sit under. Business errors live with their feature (stock.errors, transfers.errors).
return [
    'region' => 'Notifications',
    'close' => 'Fermer',
    'view' => 'Voir',
    'sign_in' => 'Se connecter',
    'saved' => ':type :reference enregistré',
    'dispatched' => 'Transfert :reference envoyé à :warehouse',
    'sent_for_approval' => ':type :reference envoyé pour approbation du responsable',
    'approved' => ':type :reference approuvé le stock est à jour',
    'rejected' => ':type :reference refusé le stock n’a pas changé',
    'received' => 'Transfert :reference réceptionné',
    'received_with_shortage' => 'Transfert :reference réceptionné avec un manque',
    'delivery_dispatched' => 'Bon de livraison :reference expédié',
    'delivered' => 'Bon de livraison :reference livré, le stock est à jour',
    'delivered_short' => 'Bon de livraison :reference livré avec des écarts, listés dans Écarts de livraison',
    'delivery_cancelled' => 'Bon de livraison :reference annulé, son stock réservé est de nouveau disponible',
    'cancelled' => ':type :reference annulé ses mouvements de stock ont été inversés',
    'rows_need_attention' => '{1} 1 ligne à vérifier|[2,*] :count lignes à vérifier',
    'fields_need_attention' => '{1} 1 champ à vérifier|[2,*] :count champs à vérifier',
    'date_order' => 'La date de début doit précéder la date de fin',
    'date_order_hint' => 'Modifiez l’une des dates ou effacez les filtres.',

    'errors' => [
        'unexpected' => 'Un problème est survenu et rien n’a été enregistré. Référence : :reference merci de prévenir votre administrateur.',
        'connection' => 'Connexion perdue vos saisies sont toujours là, réessayez.',
        'session_expired' => 'Votre session a expiré merci de vous reconnecter.',
        'not_allowed' => 'Vous n’avez pas l’autorisation de faire cela.',
    ],
];
