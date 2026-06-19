# Git Manager

Interface web pour gérer un dépôt Git sans ligne de commande.

## Fichiers

| Fichier | Description |
|---------|-------------|
| `git-manager.html` | Interface principale |
| `git-api.php` | Point d'entrée API — dispatche vers les modules dans `api/` |
| `git-config.example.php` | Modèle de configuration (à copier en `git-config.php`) |
| `git-config.php` | Configuration personnelle — non versionné (identité Git, clé SSH) |
| `git-auth.html` | Configuration de l'authentification SSH / Token |
| `git-clone.html` | Clonage d'un dépôt distant |
| `git-reset-remote.html` | Réinitialisation du remote origin |
| `git-help.html` | Documentation d'aide (chargée dans l'interface principale) |
| `index.html` | Page d'accueil du dossier git-manager |
| `flavicon.svg` | Icône SVG du projet |

### Modules backend (`api/`)

| Fichier | Actions gérées |
|---------|----------------|
| `api/branches.php` | branches, switchBranch, createBranch, renameBranch, deleteBranch, mergeBranch, pushBranch, deleteRemoteBranch |
| `api/commits.php` | log, fileLog, stageFiles, unstageFiles, commit, commitAndPush, revertCommit, amendLastCommit, showCommit, diff |
| `api/files.php` | checkout, discardAll, checkoutCommit, removeFromRepo, untrackFile, fileDiff |
| `api/gitignore.php` | getGitignore, addToGitignore, removeFromGitignore |
| `api/setup.php` | checkRepo, init, clone, listFolders, listRemoteBranches, resetRemote |
| `api/stash.php` | stashList, stashSave, stashApply, stashDrop |
| `api/status.php` | status, repoInfo, addRemote, removeRemote |
| `api/sync.php` | push, pull, fetch |
| `api/tags.php` | listTags, createTag, deleteTag, pushTag, deleteRemoteTag |
| `api/repos.php` | listRepos |

### Feuilles de style (`css/`)

| Fichier | Description |
|---------|-------------|
| `css/common.css` | Variables CSS partagées (`:root`, `.dark-mode`) |
| `css/git-manager.css` | Styles de l'interface principale |
| `css/git-help.css` | Styles de la modale d'aide |
| `css/git-auth.css` | Styles de la page authentification |
| `css/git-clone.css` | Styles de la page clonage |
| `css/git-reset-remote.css` | Styles de la page réinitialisation |
| `css/index.css` | Styles de la page d'accueil |

### Scripts frontend (`js/`)

| Fichier | Description |
|---------|-------------|
| `js/git-manager.js` | Logique de l'interface principale |
| `js/git-auth.js` | Logique de la page authentification |
| `js/git-clone.js` | Logique de la page clonage |
| `js/git-reset-remote.js` | Logique de la page réinitialisation |

## Fonctionnalités

### Initialisation et configuration

- **Initialiser un dépôt** : Créer un nouveau dépôt Git (`git init -b main`)
- **Lier à GitHub** : Configurer le remote origin vers un dépôt GitHub
- **Modifier/Supprimer le remote** : Changer ou retirer la liaison GitHub
- **Authentification SSH** : Générer et configurer une clé SSH pour GitHub

### Statut du dépôt

- Branche courante
- Nombre de fichiers suivis (total des fichiers trackés par Git)
- Fichiers modifiés (M), stagés (A), supprimés (D), non suivis (U)
- Commits en avance/retard par rapport au remote
- Nombre de stashes en attente
- Détection du HEAD détaché

### Fichiers modifiés — Diff & Commit

- **Sélection des fichiers** : Cocher les fichiers à inclure
- **Tout sélectionner** : Sélectionner tous les fichiers d'un coup
- **Diff visuel** : Cliquer sur le nom d'un fichier M, A ou D ouvre une modale avec les modifications ligne par ligne :
  - Tableau à 3 colonnes : numéro ancien / numéro nouveau / contenu
  - Lignes supprimées en rouge, ajoutées en vert
  - Fichiers M et A : deux onglets **Non stagé** / **Stagé** si les deux zones sont remplies simultanément
  - Bouton **Copier** pour copier le diff brut dans le presse-papiers
- **Commit** : Enregistrer les modifications localement
- **Commit & Push** : Commit + envoi vers GitHub en une action
- **Amend** : Modifier le dernier commit — trois usages combinables :
  - Ajouter des fichiers oubliés (cocher avant de cliquer Amend)
  - Changer le message (saisir un nouveau message dans le champ)
  - Inclure des fichiers déjà stagés (pris automatiquement, sans re-sélection)
  - Charger le message actuel via le bouton crayon dans l'historique pour l'éditer
  - Avertissement automatique si le commit a déjà été pushé (push --force requis)
- **Historique** : Liste des 20 derniers commits avec hash, message, auteur et date
- **Détail d'un commit** : Affiche les fichiers modifiés et les statistiques (`git show --stat`)
- **Revert** : Annuler un commit en créant un nouveau commit inverse
- **Checkout sur un commit** : Se placer sur un ancien commit (HEAD détaché)
- **Créer une branche depuis un HEAD détaché** : Sauvegarder un état exploré dans une nouvelle branche

### Tags

- **Créer** : Tag léger (nom seul) ou annoté (nom + message). Convention recommandée : `MAJOR.MINOR.PATCH`
- **Push** : Envoyer le tag vers GitHub (nécessaire pour créer une release). Sans effet si déjà publié.
- **Supprimer** : Suppression locale + tentative distante automatique (ignorée si jamais pushé)
- Tags accessibles depuis l'onglet **Tags** de la card Historique & Tags

### Gestion des branches

| Action | Description |
|--------|-------------|
| Créer | Nouvelle branche à partir de la branche courante ou d'une autre branche |
| Branche orpheline | Branche vide sans fichiers et sans historique (conserve git-manager/) |
| Nouveau départ | Branche avec les fichiers actuels mais sans historique |
| Renommer | Changer le nom d'une branche (local et distant si publiée). La branche par défaut (main/master) ne peut pas être renommée |
| Basculer | Changer de branche active (préserve les fichiers non suivis) |
| Fusionner | Intégrer une branche dans la branche courante |
| Publier | Envoyer une branche locale vers GitHub |
| Supprimer | Supprimer localement (et optionnellement sur GitHub) |

### Mettre de côté (Stash)

- **Mettre de côté** : Sauvegarde temporairement les modifications en cours sans commit
- **Pop** : Restaure les modifications et supprime le stash
- **Appliquer** : Restaure les modifications en conservant le stash
- **Supprimer** : Supprime un stash définitivement
- Option pour inclure ou exclure les fichiers non suivis
- Message descriptif optionnel

### Synchronisation

- **Push** : Envoyer les commits vers GitHub (détecte automatiquement le premier push)
- **Pull** : Récupérer et fusionner les modifications depuis GitHub (option `--rebase`)
- **Fetch** : Vérifier les nouveautés sans modifier les fichiers locaux

### Récupération de fichiers

- Accès rapide : HEAD, HEAD~1, HEAD~2, origin/main
- Historique complet : liste des commits du fichier sélectionné
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

### Multi-dépôts

Permet de basculer entre plusieurs dépôts Git depuis le même navigateur, sans changer de virtual host.

- **Activation** : définir `reposRoot` dans `git-config.php` (chemin absolu du dossier contenant vos projets)
- **Sélecteur** : menu déroulant affiché dans le header à côté du nom du dépôt courant
- **Scan** : liste uniquement les sous-dossiers directs de `reposRoot` qui contiennent un `.git/`
- **Sécurité** : chaque chemin est validé avec `realpath()` pour éviter toute traversée de répertoire
- Si `reposRoot` est vide ou absent, le sélecteur n'est pas affiché

### Interface

- **Mode sombre** : Thème clair/sombre avec mémorisation
- **Terminal** : Affichage des commandes Git exécutées
- **Notifications** : Toasts en bas à droite (succès, erreur, avertissement)
- **Aide intégrée** : Documentation accessible depuis l'interface

## Pages auxiliaires

### git-auth.html - Authentification SSH / Token HTTPS

Guide de configuration pour accéder à GitHub (push, pull, fetch). Deux méthodes disponibles :

**SSH** (recommandé pour un usage régulier) :
- Génération de clé RSA ou ED25519
- Ajout de la clé à l'agent SSH et à GitHub
- Test de connexion (`ssh -T git@github.com`)

**Token HTTPS** (usage occasionnel, CI/CD) :
- Création d'un Personal Access Token (PAT) sur GitHub
- Utilisation du token dans l'URL ou via le Credential Manager

### git-clone.html - Clonage de dépôt

Clone un dépôt distant dans le dossier courant :
- Supporte les URLs HTTPS et SSH
- Copie automatiquement les fichiers git-manager/ (`api/`, `css/`, `js/` inclus)
- Préserve les fichiers existants non conflictuels

### git-help.html - Documentation d'aide

Contenu HTML de l'aide intégrée, chargé dynamiquement dans la modale de `git-manager.html`. Couvre :
- Synchronisation (remote, push, pull, fetch, option `--rebase`)
- Statuts des fichiers (M, A, D, U)
- Diff visuel (lecture du tableau, en-tête @@, deux onglets stagé/non stagé)
- Tags (tag léger/annoté, push, suppression, convention MAJOR.MINOR.PATCH)
- Récupération de fichiers et retour en arrière
- Commits en avance / en retard
- Stash (mettre de côté)
- Actions dangereuses
- Fusion (merge) et résolution de conflits
- Fork et contribution
- Gestion des branches
- HEAD détaché
- Clonage, réinitialisation du remote, authentification
- Multi-dépôts (sélecteur de dépôt)
- Workflow typique

### git-reset-remote.html - Réinitialisation du remote

Crée une nouvelle branche vide (sans historique) et supprime les branches sélectionnées sur GitHub. **Irréversible.**

## Configuration

1. Renommez `git-config.example.php` en `git-config.php`
2. Renseigner vos informations :

```php
<?php
return [
    'userName' => 'votre-username',
    'userEmail' => 'votre-email@example.com',
    'sshKeyPath' => 'C:/Users/votre-nom/.ssh/id_rsa',  // ou id_ed25519

    // Multi-dépôts (facultatif) — chemin absolu du dossier contenant vos projets
    // Laisser vide pour désactiver le sélecteur de dépôt
    'reposRoot' => 'C:/Users/votre-nom/projets',

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

**Mise à jour** : Pour passer à une nouvelle version, supprimez l'ancien dossier `git-manager/` et remplacez-le par le nouveau. Votre fichier `git-config.php` étant ignoré par Git, il n'est pas inclus dans les releases — recopiez-le manuellement après la mise à jour.

```
mon-projet/                    <- Dépôt Git géré
├── .git/                      <- Dossier Git (créé automatiquement par git init)
├── git-manager/               <- Sous-dossier de l'interface (non versionné)
│   ├── api/                   <- Modules PHP backend
│   │   ├── branches.php
│   │   ├── commits.php
│   │   ├── files.php
│   │   ├── gitignore.php
│   │   ├── setup.php
│   │   ├── stash.php
│   │   ├── status.php
│   │   ├── sync.php
│   │   ├── tags.php
│   │   └── repos.php
│   ├── css/                   <- Feuilles de style
│   │   ├── common.css
│   │   ├── git-auth.css
│   │   ├── git-clone.css
│   │   ├── git-help.css
│   │   ├── git-manager.css
│   │   ├── git-reset-remote.css
│   │   └── index.css
│   ├── js/                    <- Scripts frontend
│   │   ├── git-auth.js
│   │   ├── git-clone.js
│   │   ├── git-manager.js
│   │   └── git-reset-remote.js
│   ├── git-api.php
│   ├── git-auth.html
│   ├── git-clone.html
│   ├── git-config.example.php
│   ├── git-config.php
│   ├── git-help.html
│   ├── git-manager.html
│   ├── git-reset-remote.html
│   ├── flavicon.svg
│   └── index.html
├── index.html                 (M) modifié
├── style.css                  (A) stagé
├── script.js                  (U) non suivi
└── .gitignore
```

**Note** : Le dossier `git-manager/` est automatiquement ajouté au `.gitignore` pour ne pas être versionné.

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

*Version : v1.1.0 — Juin 2026*
