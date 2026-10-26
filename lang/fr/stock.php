<?php

// Messages from posting and cancelling stock documents (App\Services\Stock).
return [
    'saved' => ':reference a été enregistré. Le stock est à jour.',
    'cancelled' => ':reference a été annulé. Ses mouvements de stock ont été inversés.',
    'sent_for_approval' => ':reference a été envoyé pour approbation. Le stock changera une fois approuvé par un responsable.',
    'approved' => ':reference a été approuvé. Le stock est à jour.',
    'rejected' => ':reference a été refusé. Le stock n’a pas changé.',

    'approval' => [
        'reject_reason' => 'Pourquoi le refuser ?',
        'reject_placeholder' => 'ex. : Recompter à deux',
        'confirm_reject' => 'Refuser',
        'keep' => 'Retour',
        'own' => 'Vous avez envoyé ce document pour approbation : un autre responsable doit l’approuver ou le refuser.',
        'waiting' => 'En attente d’approbation par un responsable. Le stock n’a pas encore changé.',
        'rejection_reason' => 'Motif du refus',
        'approved_by' => 'Approuvé par',
        'rejected_by' => 'Refusé par',
    ],

    'cancel' => [
        'button' => 'Annuler le document',
        'question' => 'Annuler :reference ? Ses mouvements de stock sont inversés et il ne pourra plus être utilisé.',
        'confirm' => 'Oui, l’annuler',
        'keep' => 'Le garder',
    ],

    // A lot: batch number and expiry date (App\Support\Batches).
    'batch' => [
        'label' => ':batch · :date',
        'none' => 'Sans lot',
    ],

    'errors' => [
        'not_enough' => 'Seulement :qty de :item disponible à :warehouse.',
        'not_enough_on_date' => 'Le :date, seulement :qty de :item était disponible à :warehouse. Le stock arrivé plus tard ne peut pas sortir à cette date.',
        'not_enough_plain' => 'Stock insuffisant : seulement :qty (unités de base) disponible.',
        'not_enough_with_expired' => 'Seulement :qty de :item disponible non périmé à :warehouse (:expired de plus est périmé).',
        'not_enough_reserved' => 'Seulement :qty de :item disponible à :warehouse : :reserved est réservé pour des commandes.',
        'count_below_reserved' => ':qty est réservé pour :orders. Modifiez ces commandes avant de compter moins.',
        'count_below_reserved_short' => ':qty est réservé pour des commandes : le comptage ne peut pas être inférieur.',
        'serial_reserved' => 'Le numéro de série :serial est réservé pour une commande.',
        'serial_reserved_missing' => 'Le numéro de série :serial est réservé pour une commande : il ne peut pas être compté comme manquant. Modifiez d’abord cette commande.',
        'only_expired' => 'Le reste de :item à :warehouse (:qty) est périmé. Le stock périmé ne peut pas sortir.',
        'not_exact' => 'Cette quantité ne peut pas être comptée exactement dans l’unité de base. Utilisez un nombre entier d’unités de base.',
        'type_not_supported' => 'Ce type de document ne peut pas encore être enregistré.',
        'warehouse_not_allowed' => 'Vous ne travaillez pas dans cet entrepôt.',
        'warehouse_inactive' => 'Cet entrepôt n’est pas en service.',
        'customer_inactive' => 'Ce client n’est pas en service.',
        'serial_and_expiry' => 'Les articles qui ont à la fois des numéros de série et une date de péremption ne sont pas encore gérés. Modifiez d’abord le suivi de l’article.',
        'serial_count' => 'Saisissez un numéro de série par pièce (:count).',
        'serial_twice' => 'Le numéro de série :serial est saisi deux fois.',
        'serial_in_stock' => 'Le numéro de série :serial est déjà en stock.',
        'serial_not_in_stock' => 'Le numéro de série :serial n’est pas en stock dans cet entrepôt.',
        'serial_not_on_date' => 'Le numéro de série :serial est arrivé dans cet entrepôt après le :date ; il ne peut pas sortir à cette date.',
        'batch_other_date' => 'Le lot :batch de :item périme le :date — saisissez cette date ou un autre numéro de lot.',
        'expiry_expired' => 'Le stock qui expire le :date est périmé. Le stock périmé ne peut pas sortir ainsi.',
        'cannot_cancel_status' => 'Seuls les documents terminés peuvent être annulés.',
        'already_used' => 'Une partie de ce stock est déjà sortie : :item reçu :received, il ne reste que :left. Il ne peut pas être annulé.',
        'serial_already_used' => 'Le numéro de série :serial est déjà sorti : cette réception ne peut pas être annulée.',
        'serial_back_in_stock' => 'Le numéro de série :serial est déjà revenu en stock : ce bon de livraison ne peut pas être annulé.',
        'nothing_to_adjust' => 'Tous les comptages correspondent au système : il n’y a rien à ajuster.',
        'count_changed' => 'Le stock de :item a changé depuis ce comptage refusez-le et recomptez.',
        'not_pending' => 'Ce document a déjà été approuvé ou refusé.',
        'own_document' => 'Vous ne pouvez pas approuver ou refuser une opération que vous avez créée un autre responsable doit décider.',
        'already_rejected' => 'Ce document a déjà été refusé par :name le :time.',
        'already_approved' => 'Ce document a déjà été approuvé par :name le :time.',
        'cancel_reason_required' => 'Indiquez pourquoi il est annulé.',
        'reject_reason_required' => 'Indiquez le motif du refus.',
        'cannot_approve' => 'Vous ne pouvez pas approuver ce type de document.',
    ],
];
