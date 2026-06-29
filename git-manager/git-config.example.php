<?php

/**
 * Configuration du gestionnaire Git
 *
 * 1. Renommez ce fichier en "git-config.php"
 * 2. Modifiez les valeurs selon votre environnement
 * 3. Ne partagez jamais git-config.php (il est dans le .gitignore)
 */

return [
    // Identité Git pour les commits
    'userName' => 'votre-username',
    'userEmail' => 'votre-email@example.com',

    // Clé SSH pour l'authentification GitHub (facultatif)
    // Indiquez le chemin complet vers votre clé privée SSH
    // Laissez vide si vous utilisez HTTPS avec token
    //
    // Clé RSA :
    //   Windows : 'C:/Users/votre-nom/.ssh/id_rsa'
    //   Linux   : '/home/votre-nom/.ssh/id_rsa'
    //   Mac     : '/Users/votre-nom/.ssh/id_rsa'
    //
    // Clé ED25519 :
    //   Windows : 'C:/Users/votre-nom/.ssh/id_ed25519'
    //   Linux   : '/home/votre-nom/.ssh/id_ed25519'
    //   Mac     : '/Users/votre-nom/.ssh/id_ed25519'
    'sshKeyPath' => 'C:/Users/votre-nom/.ssh/id_rsa',

    // Chemin absolu du dossier contenant vos repos Git
    // Pour un seul projet : indiquez le dossier parent du projet
    //   Windows : 'C:/Users/votre-nom/repos'
    //   Linux   : '/home/votre-nom/repos'
    'reposRoot' => 'C:/Users/votre-nom/repos',

    // Options d'affichage (facultatif)
    'options' => [
        'maxHistoryItems' => 20,      // Nombre de commits dans l'historique
        'maxFileLogItems' => 10,      // Nombre de commits par fichier
    ]
];

/**
 * maxHistoryItems => 20 : C'est le nombre de commits affichés dans la section "Historique des commits".
 * Par défaut, les 20 derniers commits sont affichés. Si vous en voulez plus ou moins, vous changez cette valeur.
 * maxFileLogItems => 10 : C'est le nombre de commits affichés dans le menu déroulant de la section "Récupération de fichiers".
 * Quand vous sélectionnez un fichier pour le restaurer, vous voyez les 10 derniers commits où ce fichier a été modifié.
 * Ça vous permet de choisir à quelle version revenir.
 * Plus le nombre est élevé, plus le chargement sera long sur les gros dépôts,
 */