<?php

// The page after signing in (App\Http\Controllers\HomeController).
return [
    'page_title' => 'Accueil',
    'title' => 'Bonjour, :name',
    'subtitle' => 'Que souhaitez-vous faire ?',
    'waiting' => 'En attente de vous',
    'approvals' => '{0} :type aucune approbation requise|{1} :type en attente de votre approbation|[2,*] :type en attente de votre approbation',
    'arriving' => '{0} Aucun transfert en arrivée|{1} Transfert en arrivée ici|[2,*] Transferts en arrivée ici',
    'start' => 'Commencer quelque chose',
    'nothing_to_create' => 'Vous pouvez consulter le stock et les documents, mais pas en créer. Demandez à un administrateur si besoin.',
];
