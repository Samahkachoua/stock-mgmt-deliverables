<?php

return [
    'tagline' => 'WMS Core',
    'skip_to_content' => 'Aller au contenu',
    'select_warehouse' => 'Choisir l’entrepôt',
    'language' => 'Langue',
    'theme' => 'Thème',
    'theme_light' => 'Clair',
    'theme_dark' => 'Sombre',
    'theme_system' => 'Système',
    'theme_invalid' => 'Choisissez Clair, Sombre ou Système.',
    'user_menu' => 'Menu du compte',
    'sign_out' => 'Se déconnecter',
    'no_warehouses_title' => 'Aucun entrepôt attribué',
    'no_warehouses_body' => 'Votre compte n’est rattaché à aucun entrepôt. Demandez à votre administrateur de vous en attribuer un.',
    'forbidden_title' => 'Vous n’avez pas accès à cette page',
    'forbidden_body' => 'Votre compte n’est pas autorisé à ouvrir cette page. Demandez à votre administrateur si vous en avez besoin.',
    'go_to_start' => 'Aller à la page d’accueil',

    // Loading states (CLAUDE.md "Loading states"): shared by every form, list and export.
    'loading' => [
        'saving' => 'Enregistrement…',
        'list' => 'Chargement…',
        'preparing_file' => 'Préparation du fichier…',
    ],

    // Error pages (resources/views/errors). Shown even when the app is failing.
    'errors' => [
        'home' => 'Aller à la page d’accueil',
        'reference' => 'Référence : :reference',
        'back' => 'Revenir',
        'not_found' => ['title' => 'Page introuvable', 'body' => 'Cette adresse ne mène nulle part. Elle a peut-être été mal saisie, ou la page a été déplacée.'],
        'expired' => ['title' => 'La page a expiré', 'body' => 'Vous êtes resté absent un moment : la page doit être rechargée. Revenez en arrière et réessayez rien n’a été enregistré.'],
        'too_many' => ['title' => 'Trop de tentatives', 'body' => 'Merci de patienter une minute, puis de réessayer.'],
        'server' => ['title' => 'Un problème est survenu', 'body' => 'Le problème a été enregistré. Réessayez dans un instant ; si cela continue, prévenez votre administrateur.'],
        'maintenance' => ['title' => 'De retour dans quelques minutes', 'body' => 'Le système est en cours de mise à jour. Merci de réessayer bientôt.'],
    ],
];
