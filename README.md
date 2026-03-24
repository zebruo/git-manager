# Git Manager

Interface web pour gérer un dépôt Git sans ligne de commande.

## Fichiers

| Fichier | Description |
|---------|-------------|
| `git-manager.html` | Interface principale (HTML/CSS/JS) |
| `git-api.php` | API backend pour exécuter les commandes Git |
| `git-config.example.php` | Modèle de configuration (à copier en `git-config.php`) |
| `git-config.php` | Configuration personnelle — non versionné (identité Git, clé SSH) |
| `git-auth.html` | Configuration de l'authentification SSH |
| `git-clone.html` | Clonage d'un dépôt distant |
| `git-reset-remote.html` | Réinitialisation du remote origin |
| `git-help.html` | Documentation d'aide (chargée dans l'interface principale) |
| `index.html` | Page d'accueil du dossier git-manager |
| `flavicon.svg` | Icône SVG du projet (logo Git en #45b7af) |
| `README.md` | Documentation du projet Git Manager |

## Fonctionnalités

### Initialisation et configuration

- **Initialiser un dépôt** : Créer un nouveau dépôt Git (`git init -b main`)
- **Lier à GitHub** : Configurer le remote origin vers un dépôt GitHub
- **Modifier/Supprimer le remote** : Changer ou retirer la liaison GitHub
- **Authentification SSH** : Générer et configurer une clé SSH pour GitHub

### Statut du dépôt

- Branche courante
- Nombre de fichiers suivis (total des fichiers trackés par Git)
- Fichiers modifiés (M)
- Fichiers stagés (A)
- Fichiers supprimés (D)
- Fichiers non suivis (?)
- Commits en avance/retard par rapport au remote
- Nombre de stashes en attente
- Détection du HEAD détaché

### Gestion des commits

- **Sélection des fichiers** : Cocher les fichiers à inclure
- **Tout sélectionner** : Sélectionner tous les fichiers d'un coup
- **Commit** : Enregistrer les modifications localement
- **Commit & Push** : Commit + envoi vers GitHub en une action
- **Amend** : Ajouter les fichiers sélectionnés au dernier commit sans changer son message (`git commit --amend --no-edit`). Compatible avec les suppressions déjà stagées (fichiers D).
- **Historique** : Liste des 20 derniers commits avec hash, message, auteur et date
- **Détail d'un commit** : Affiche les fichiers modifiés et les statistiques d'un commit dans le terminal (bouton loupe, `git show --stat`)
- **Checkout sur un commit** : Se placer sur un ancien commit (HEAD détaché) depuis l'historique
- **Créer une branche depuis un HEAD détaché** : Sauvegarder un état exploré dans une nouvelle branche

### Gestion des branches

| Action | Description |
|--------|-------------|
| Créer | Nouvelle branche à partir de la branche courante ou d'une autre branche |
| Branche orpheline | Branche vide sans fichiers et sans historique (conserve git-manager/) |
| Nouveau départ | Branche avec les fichiers actuels mais sans historique |
| Renommer | Changer le nom d'une branche (local et distant si publiée). La branche par défaut (main/master) ne peut pas être renommée (crayon grisé) |
| Basculer | Changer de branche active (préserve les fichiers non suivis) |
| Fusionner | Intégrer une branche dans la branche courante |
| Publier | Envoyer une branche locale vers GitHub |
| Supprimer | Supprimer localement (et optionnellement sur GitHub) |

### Types de branches spéciales

- **Branche orpheline** : Crée une branche totalement vide, sans historique, idéale pour démarrer un projet indépendant. Le dossier git-manager/ est conservé pour garder l'interface accessible.
- **Nouveau départ** : Crée une branche avec tous les fichiers actuels mais sans l'historique des commits. Utile pour "nettoyer" l'historique tout en conservant le code.

### Mettre de côté (Stash)

- **Mettre de côté** : Sauvegarde temporairement les modifications en cours sans commit
- **Pop** : Restaure les modifications et supprime le stash
- **Appliquer** : Restaure les modifications en conservant le stash (réutilisable sur plusieurs branches)
- **Supprimer** : Supprime un stash définitivement
- Option pour inclure ou exclure les fichiers non suivis
- Message descriptif optionnel

### Synchronisation

- **Push** : Envoyer les commits vers GitHub (détecte automatiquement le premier push)
- **Pull** : Récupérer et fusionner les modifications depuis GitHub (option `--rebase` pour rejouer les commits locaux après les commits distants, sans merge commit)
- **Fetch** : Vérifier les nouveautés sans modifier les fichiers locaux

### Récupération de fichiers

- Accès rapide : HEAD, HEAD~1, HEAD~2, origin/main
- Historique complet : liste des commits du fichier sélectionné (limite configurable dans `git-config.php` → `maxFileLogItems`, par défaut 10)
- Annuler toutes les modifications locales

### Suppression de fichiers

- **Supprimer (dépôt + disque)** : Supprime le fichier ou le dossier complètement (`git rm -r`)
- **Arrêter le suivi** : Retire du dépôt mais conserve sur le disque (`git rm --cached -r`)
- Sélection par fichier individuel ou par dossier entier

### Gestion du .gitignore

- Voir la liste des patterns ignorés
- Ajouter un fichier non suivi au .gitignore
- Ajouter un pattern personnalisé (ex: `*.log`, `temp/`)
- Retirer un pattern du .gitignore

### Interface

- **Mode sombre** : Thème clair/sombre avec mémorisation
- **Terminal** : Affichage des commandes Git exécutées
- **Alertes** : Messages de succès/erreur
- **Aide intégrée** : Documentation accessible depuis l'interface

## Pages auxiliaires

### git-auth.html - Authentification SSH / Token HTTPS

Guide de configuration pour accéder à GitHub (push, pull, fetch). Deux méthodes disponibles :

**SSH** (recommandé pour un usage régulier) :
- Génération de clé RSA ou ED25519
- Ajout de la clé à l'agent SSH et à GitHub
- Test de connexion (`ssh -T git@github.com`)
- Authentification automatique, pas de mot de passe à chaque opération

**Token HTTPS** (usage occasionnel, CI/CD) :
- Création d'un Personal Access Token (PAT) sur GitHub
- Utilisation du token dans l'URL ou via le Credential Manager
- Token à renouveler périodiquement

### git-clone.html - Clonage de dépôt

Clone un dépôt distant dans le dossier courant :
- Supporte les URLs HTTPS et SSH
- Copie automatiquement les fichiers git-manager/ pour conserver l'interface
- Préserve les fichiers existants non conflictuels

### git-help.html - Documentation d'aide

Contenu HTML de l'aide intégrée, chargé dynamiquement dans la modale de `git-manager.html`. Couvre :
- Synchronisation (remote, push, pull, fetch, option `--rebase`)
- Statuts des fichiers (M, A, D, ?)
- Récupération de fichiers et retour en arrière
- Commits en avance / en retard
- Stash (mettre de côté)
- Actions dangereuses
- Fusion (merge) et résolution de conflits
- Fork et contribution
- Gestion des branches (création, récupération distante, renommage, suppression)
- HEAD détaché
- Clonage, réinitialisation du remote, authentification
- Workflow typique

### git-reset-remote.html - Réinitialisation du remote

Permet de reconfigurer complètement le remote origin :
- Supprimer le remote existant
- Ajouter un nouveau remote
- Utile en cas de changement de dépôt ou de méthode d'authentification

## Configuration

1. Renommez `git-config.example.php` en `git-config.php`
2. Renseigner vos informations :

```php
<?php
return [
    'userName' => 'votre-username',
    'userEmail' => 'votre-email@example.com',
    'sshKeyPath' => 'C:/Users/votre-nom/.ssh/id_rsa',  // ou id_ed25519
    'options' => [
        'maxHistoryItems' => 20,    // Nombre de commits dans l'historique
        'maxFileLogItems' => 10,    // Nombre de commits par fichier (récupération)
    ]
];
```

> **Note :** `git-config.php` contient des données personnelles et est automatiquement ajouté au `.gitignore`. Ne le partagez pas.

## Installation

1. Créer un sous-dossier `git-manager/` dans votre projet
2. Copier tous les fichiers dans ce sous-dossier
3. Renommez `git-config.example.php` en `git-config.php` et renseigner vos informations
4. Accéder à `http://votre-site/git-manager/git-manager.html`

**Important** : Les fichiers doivent être dans un **sous-dossier** du projet à gérer. L'API gère automatiquement le dossier parent comme dépôt Git.

```
mon-projet/              <- Dépôt Git géré
├── .git/                <- Dossier Git (créé automatiquement par git init)
├── git-manager/         <- Sous-dossier de l'interface
│   ├── git-manager.html
│   ├── git-api.php
│   ├── git-config.example.php
│   ├── git-config.php
│   ├── git-auth.html
│   ├── git-clone.html
│   ├── git-reset-remote.html
│   ├── git-help.html
│   ├── index.html
│   ├── flavicon.svg
│   └── README.md
├── index.html               (M) modifié
├── style.css                (A) stagé
├── script.js                (U) non suivi
├── images/
│   ├── logo.png             (M) modifié
│   └── banner.jpg           (U) non suivi
└── .gitignore
```

**Note** : Le dossier `git-manager/` est automatiquement ajouté au `.gitignore` pour ne pas être versionné. Lors de la création de branches orphelines, le dossier est préservé pour que l'interface reste accessible.

## Prérequis

- Serveur web avec PHP
- Git installé sur le serveur
- Droits d'écriture sur le dossier du projet
- (Optionnel) OpenSSH pour l'authentification par clé SSH

## Compatibilité testée

- **PHP** : 8.x
- **Navigateurs** : Chrome, Firefox, Edge, Safari (dernières versions)
- **OS** : Windows 10/11, macOS, Linux

## Licence

Usage personnel — Tous droits réservés.

---

## A propos

Git Manager est une interface web pour gérer un dépôt Git sans ligne de commande. Ce projet est opérationnel, à usage personnel, sans contributions externes. Il a été développé avec l'assistance de [Claude](https://claude.ai) (Anthropic).

**Il est recommandé de faire un premier essai sur un dépôt test avant de l'utiliser sur un projet existant.**

*Version : 1.0 — Février 2025*