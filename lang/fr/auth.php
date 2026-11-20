<?php

return [
    'title' => 'Connexion',
    'subtitle' => 'Utilisez votre e-mail professionnel et votre mot de passe.',
    'email' => 'E-mail',
    'password' => 'Mot de passe',
    'remember' => 'Rester connecté',
    'show_password' => 'Afficher le mot de passe',
    'hide_password' => 'Masquer le mot de passe',
    'submit' => 'Se connecter',
    'signing_in' => 'Connexion…',
    'failed' => 'L’e-mail ou le mot de passe est incorrect.',
    'inactive' => 'Ce compte est désactivé. Contactez votre administrateur.',
    'throttle' => 'Trop de tentatives. Réessayez dans :seconds secondes.',
    'back_to_sign_in' => 'Retour à la connexion',
    'forced_change' => 'Votre administrateur a défini un mot de passe temporaire. Choisissez votre propre mot de passe pour continuer.',

    'forgot' => [
        'link' => 'Mot de passe oublié ?',
        'title' => 'Réinitialiser votre mot de passe',
        'subtitle' => 'Saisissez votre e-mail professionnel : nous vous enverrons un lien pour choisir un nouveau mot de passe.',
        'submit' => 'Envoyer le lien',
        'sending' => 'Envoi…',
        'sent' => 'Si cet e-mail est enregistré, vous recevrez sous peu un lien de réinitialisation du mot de passe.',
    ],

    'reset' => [
        'title' => 'Choisir un nouveau mot de passe',
        'subtitle' => 'Saisissez deux fois votre nouveau mot de passe.',
        'new' => 'Nouveau mot de passe',
        'confirm' => 'Répétez le nouveau mot de passe',
        'signs_out' => 'Vous serez déconnecté de tous les appareils et pourrez ensuite vous connecter avec le nouveau mot de passe.',
        'submit' => 'Enregistrer le mot de passe',
        'done' => 'Votre mot de passe a été modifié. Connectez-vous avec le nouveau mot de passe.',
        'invalid' => 'Ce lien n’est plus valable. Il a peut-être déjà été utilisé, remplacé par un lien plus récent, ou date de plus de :minutes minutes.',
        'request_new' => 'M’envoyer un nouveau lien',
    ],

    'mail' => [
        'greeting' => 'Bonjour :name,',
        'footer' => ':app · Message automatique, merci de ne pas y répondre.',
        'reset' => [
            'subject' => 'Réinitialisez votre mot de passe :app',
            'heading' => 'Réinitialiser votre mot de passe',
            'intro' => 'Nous avons reçu une demande de réinitialisation du mot de passe de votre compte :app. Cliquez sur le bouton ci-dessous pour en choisir un nouveau.',
            'button' => 'Réinitialiser le mot de passe',
            'expires' => 'Ce lien expire dans :minutes minutes et ne peut servir qu’une fois.',
            'fallback' => 'Si le bouton ne fonctionne pas, copiez ce lien dans votre navigateur :',
            'ignore' => 'Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail. Votre mot de passe ne changera pas.',
        ],
        'changed' => [
            'subject' => 'Votre mot de passe :app a été modifié',
            'heading' => 'Votre mot de passe a été modifié',
            'intro' => 'Le mot de passe de votre compte :app a été modifié le :date.',
            'not_you' => 'Si ce n’était pas vous, contactez immédiatement votre administrateur.',
        ],
    ],
];
