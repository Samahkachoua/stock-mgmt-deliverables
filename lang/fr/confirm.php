<?php

// The confirmation modal (CLAUDE.md "Confirmations"): title, consequence and action button per
// destructive action, and why a record can't be deleted or deactivated.
return [
    'keep' => 'Conserver',
    'reason_required' => 'Indiquez pourquoi.',
    // Quitter un formulaire non enregistré (App\Livewire\Concerns\WarnsBeforeLeaving).
    'discard' => [
        'item' => [
            'title' => 'Abandonner vos modifications de :name ?',
            'title_new' => 'Abandonner ce nouvel article ?',
            'body' => 'Ce que vous avez modifié sur cette page n’est pas enregistré et sera perdu.',
            'button' => 'Abandonner les modifications',
        ],
    ],
    'delete' => [
        'item' => [
            'title' => 'Supprimer l’article :name ?',
            'body' => ':name et ses unités sont supprimés définitivement. Cette action est irréversible.',
            'button' => 'Supprimer l’article',
        ],
        'unit' => [
            'title' => 'Supprimer l’unité :name ?',
            'body' => ':name est supprimée définitivement. Cette action est irréversible.',
            'button' => 'Supprimer l’unité',
        ],
        'role' => [
            'title' => 'Supprimer le rôle :name ?',
            'body' => 'Le rôle et ses autorisations sont supprimés définitivement. Cette action est irréversible.',
            'button' => 'Supprimer le rôle',
        ],
        'limits' => [
            'title' => 'Supprimer les seuils d’approbation de :name ?',
            'body' => 'Les seuils généraux s’appliquent de nouveau à :name. Cette action est irréversible.',
            'button' => 'Supprimer les seuils',
            'done' => 'Seuils d’approbation de :name supprimés',
        ],
    ],
    'deactivate' => [
        'warehouse' => [
            'title' => 'Désactiver l’entrepôt :name ?',
            'body' => ':name ne pourra plus être choisi pour de nouvelles opérations. Vous pourrez le réactiver plus tard.',
            'button' => 'Désactiver l’entrepôt',
        ],
        'customer' => [
            'title' => 'Désactiver le client :name ?',
            'body' => ':name ne pourra plus être choisi pour de nouvelles opérations. Vous pourrez le réactiver plus tard.',
            'button' => 'Désactiver le client',
        ],
        'customer_branch' => [
            'title' => 'Désactiver l’agence :name ?',
            'body' => ':name ne pourra plus être choisie sur les nouveaux brouillons. Vous pourrez la réactiver plus tard.',
            'button' => 'Désactiver l’agence',
        ],
        'item' => [
            'title' => 'Désactiver l’article :name ?',
            'body' => ':name ne pourra plus être choisi dans de nouvelles opérations. Vous pourrez le réactiver plus tard.',
            'button' => 'Désactiver l’article',
        ],
        'user' => [
            'title' => 'Désactiver l’utilisateur :name ?',
            'body' => ':name ne pourra plus se connecter. Vous pourrez réactiver le compte plus tard.',
            'button' => 'Désactiver l’utilisateur',
        ],
    ],
    'cancel' => [
        'title' => 'Annuler :type :reference ?',
        'returns' => 'Cela remet :stock dans le stock de :warehouse. Cette action est irréversible.',
        'removes' => 'Cela retire de nouveau :stock du stock de :warehouse. Cette action est irréversible.',
        'more' => '{1} :first et 1 autre article|[2,*] :first et :count autres articles',
        'stock' => ':qty de :item',
        'button' => 'Annuler :type',
        'reason' => 'Pourquoi l’annuler ?',
        'reason_placeholder' => 'ex. Saisi pour le mauvais client',
    ],
    'reject' => [
        'title' => 'Refuser :type :reference ?',
        'body' => 'Le stock ne changera pas, et :name verra votre motif. Cette action est irréversible.',
        'button' => 'Refuser :type',
        'reason' => 'Pourquoi le refuser ?',
    ],
    'blocked' => [
        'item_on_documents' => '{1} :name figure sur 1 document (:types) et ne peut pas être supprimé. Désactivez « En service » pour le masquer.|[2,*] :name figure sur :count documents (:types) et ne peut pas être supprimé. Désactivez « En service » pour le masquer.',
        'item_has_records' => ':name a des enregistrements de stock et ne peut pas être supprimé. Désactivez « En service » pour le masquer.',
        'unit_in_use' => '{1} :name est utilisée par 1 article et ne peut pas être supprimée.|[2,*] :name est utilisée par :count articles et ne peut pas être supprimée.',
        'unit_on_documents' => ':name est utilisée dans des documents enregistrés et ne peut pas être supprimée.',
        'role_system' => ':name est un rôle intégré et ne peut pas être supprimé.',
        'role_in_use' => '{1} :name est attribué à 1 utilisateur et ne peut pas être supprimé.|[2,*] :name est attribué à :count utilisateurs et ne peut pas être supprimé.',
    ],
];
