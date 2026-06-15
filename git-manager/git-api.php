<?php
/**
 * Git API - Backend pour la gestion Git
 * Permet d'exécuter des commandes Git depuis l'interface web
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Configuration
$repoPath = dirname(__DIR__); // Chemin vers la racine du dépôt

// Charger la configuration externe
$configFile = __DIR__ . '/git-config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
    $gitUserName = $config['userName'] ?? '';
    $gitUserEmail = $config['userEmail'] ?? '';
} else {
    // Valeurs par défaut si pas de fichier de config
    $gitUserName = '';
    $gitUserEmail = '';
}

// Sécurité: vérifier que nous sommes dans un dépôt Git
// (sauf pour les actions spéciales checkRepo et init gérées plus bas)
$isGitRepoCheck = is_dir($repoPath . '/.git');

// Changer vers le répertoire du dépôt
chdir($repoPath);

// Configurer Git pour accepter ce répertoire (sécurité Windows)
$safeRepoPath = str_replace('\\', '/', $repoPath);
putenv("GIT_DIR={$repoPath}/.git");
putenv("GIT_WORK_TREE={$repoPath}");

// Ajouter le répertoire comme sûr pour cette session
exec("git config --global --add safe.directory \"{$safeRepoPath}\" 2>&1");

// Configurer SSH pour l'authentification GitHub (si clé spécifiée)
$sshKeyPath = $config['sshKeyPath'] ?? '';
if (!empty($sshKeyPath) && file_exists($sshKeyPath)) {
    $sshKeyPath = str_replace('\\', '/', $sshKeyPath);
    // Définir HOME pour que SSH trouve known_hosts
    $homeDir = dirname(dirname($sshKeyPath)); // ex: C:/Users/NomUtilisateur
    putenv("HOME={$homeDir}");
    // Null device selon l'OS (NUL sur Windows, /dev/null sur Linux/Mac)
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    putenv("GIT_SSH_COMMAND=ssh -i \"{$sshKeyPath}\" -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=\"{$homeDir}/.ssh/known_hosts\"");
}

// Configurer l'identité Git pour les commits (si spécifiée)
if (!empty($gitUserName)) {
    exec("git -C \"{$safeRepoPath}\" config user.name \"{$gitUserName}\" 2>&1");
}
if (!empty($gitUserEmail)) {
    exec("git -C \"{$safeRepoPath}\" config user.email \"{$gitUserEmail}\" 2>&1");
}

// Fonctions utilitaires pour copier/supprimer récursivement des dossiers
function recurseCopy($src, $dst) {
    if (!is_dir($src)) return false;
    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }
    $dir = opendir($src);
    while (($file = readdir($dir)) !== false) {
        if ($file === '.' || $file === '..') continue;
        $srcPath = $src . DIRECTORY_SEPARATOR . $file;
        $dstPath = $dst . DIRECTORY_SEPARATOR . $file;
        if (is_dir($srcPath)) {
            recurseCopy($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath);
        }
    }
    closedir($dir);
    return true;
}

function recurseDelete($dir) {
    if (!is_dir($dir)) return false;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_dir($path)) {
            recurseDelete($path);
        } else {
            unlink($path);
        }
    }
    return rmdir($dir);
}

// Supprimer les dossiers vides laissés par git après un checkout
function cleanEmptyDirs($dir, $excludeDirs = ['.git', 'git-manager']) {
    if (!is_dir($dir)) return;

    // Fichiers système à supprimer (empêchent la détection de dossiers vides)
    $systemFiles = ['Thumbs.db', 'desktop.ini', '.DS_Store', '.localized', '.gitkeep'];

    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !in_array($item, $excludeDirs)) {
            // Nettoyer récursivement les sous-dossiers d'abord
            cleanEmptyDirs($path, $excludeDirs);

            // Supprimer les fichiers système dans ce dossier
            $subItems = @scandir($path);
            if ($subItems !== false) {
                foreach ($subItems as $subItem) {
                    if (in_array($subItem, $systemFiles)) {
                        @unlink($path . DIRECTORY_SEPARATOR . $subItem);
                    }
                }
            }

            // Supprimer le dossier s'il est vide
            $remaining = @scandir($path);
            if ($remaining !== false) {
                $remaining = array_diff($remaining, ['.', '..']);
                if (empty($remaining)) {
                    @rmdir($path);
                }
            }
        }
    }

    // Deuxième passe pour les dossiers qui sont devenus vides après suppression des sous-dossiers
    $items = array_diff(scandir($dir), ['.', '..']);
    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path) && !in_array($item, $excludeDirs)) {
            $remaining = @scandir($path);
            if ($remaining !== false) {
                $remaining = array_diff($remaining, ['.', '..']);
                if (empty($remaining)) {
                    @rmdir($path);
                }
            }
        }
    }
}


// Récupérer la requête
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Actions spéciales qui ne nécessitent pas un dépôt Git existant
$isGitRepo = is_dir($repoPath . '/.git');

if ($action === 'checkRepo') {
    echo json_encode([
        'success' => true,
        'data' => [
            'isGitRepo' => $isGitRepo,
            'path' => $repoPath
        ]
    ]);
    exit;
}

if ($action === 'init') {
    if ($isGitRepo) {
        echo json_encode(['success' => false, 'error' => 'Ce dossier est déjà un dépôt Git']);
        exit;
    }

    chdir($repoPath);
    $safeRepoPath = str_replace('\\', '/', $repoPath);

    // Initialiser le dépôt avec la branche main par défaut
    $output = [];
    $returnCode = 0;
    exec("git init -b main 2>&1", $output, $returnCode);

    if ($returnCode === 0) {
        // Configurer l'identité Git si spécifiée
        if (!empty($gitUserName)) {
            exec("git config user.name \"{$gitUserName}\" 2>&1");
        }
        if (!empty($gitUserEmail)) {
            exec("git config user.email \"{$gitUserEmail}\" 2>&1");
        }
        // Ajouter comme répertoire sûr
        exec("git config --global --add safe.directory \"{$safeRepoPath}\" 2>&1");

        // Ajouter git-manager/ au .gitignore
        $gitignorePath = $repoPath . '/.gitignore';
        $gitignoreEntry = 'git-manager/';
        $gitignoreContent = '';
        if (file_exists($gitignorePath)) {
            $gitignoreContent = file_get_contents($gitignorePath);
        }
        if (strpos($gitignoreContent, $gitignoreEntry) === false) {
            $newContent = rtrim($gitignoreContent);
            $newContent .= ($newContent ? "\n\n" : '') . "# Interface de gestion Git\n{$gitignoreEntry}\n";
            file_put_contents($gitignorePath, $newContent);
        }

        echo json_encode([
            'success' => true,
            'output' => 'Dépôt Git initialisé avec succès dans ' . $repoPath
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => implode("\n", $output)
        ]);
    }
    exit;
}

if ($action === 'clone') {
    // Augmenter le temps d'exécution pour les gros dépôts (10 minutes max)
    set_time_limit(600);
    ini_set('max_execution_time', 600);

    // Cloner un dépôt distant
    $url = $input['url'] ?? '';
    $targetDir = $input['targetDir'] ?? '';
    $copyGitManager = $input['copyGitManager'] ?? true;

    if (empty($url)) {
        echo json_encode(['success' => false, 'error' => 'URL du dépôt requise']);
        exit;
    }

    // Valider l'URL (GitHub, GitLab, Bitbucket, ou URL git générique)
    if (!preg_match('/^(https?:\/\/|git@)/', $url)) {
        echo json_encode(['success' => false, 'error' => 'URL invalide. Utilisez une URL HTTPS ou SSH.']);
        exit;
    }

    // Déterminer le dossier de destination
    $parentDir = dirname($repoPath);

    if (empty($targetDir)) {
        // Extraire le nom du repo depuis l'URL
        // Nettoyer l'URL : enlever trailing slash et .git
        $cleanUrl = rtrim($url, '/');
        $cleanUrl = preg_replace('/\.git$/i', '', $cleanUrl);

        // Extraire le dernier segment (nom du repo)
        if (preg_match('/\/([^\/]+)$/', $cleanUrl, $matches)) {
            $targetDir = $matches[1];
        } else {
            $targetDir = 'cloned-repo';
        }
    }

    // Sécurité: valider le nom du dossier
    $targetDir = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $targetDir);
    if (empty($targetDir)) {
        echo json_encode(['success' => false, 'error' => 'Nom de dossier invalide']);
        exit;
    }

    $fullTargetPath = $parentDir . DIRECTORY_SEPARATOR . $targetDir;
    $safeTargetPath = str_replace('\\', '/', $fullTargetPath);

    // Vérifier que le dossier n'existe pas déjà
    if (file_exists($fullTargetPath)) {
        echo json_encode(['success' => false, 'error' => "Le dossier '{$targetDir}' existe déjà"]);
        exit;
    }

    // Exécuter git clone
    // Important: supprimer les variables d'environnement GIT_DIR et GIT_WORK_TREE
    // qui pointent vers le dépôt actuel et interfèrent avec le clone
    putenv("GIT_DIR");
    putenv("GIT_WORK_TREE");

    $urlEscaped = escapeshellarg($url);
    $pathEscaped = escapeshellarg($fullTargetPath);

    $output = [];
    $returnCode = 0;
    exec("git clone {$urlEscaped} {$pathEscaped} 2>&1", $output, $returnCode);

    if ($returnCode !== 0) {
        echo json_encode([
            'success' => false,
            'error' => implode("\n", $output)
        ]);
        exit;
    }

    // Ajouter comme répertoire sûr
    exec("git config --global --add safe.directory \"{$safeTargetPath}\" 2>&1");

    // Configurer l'identité Git si spécifiée
    if (!empty($gitUserName)) {
        exec("git -C \"{$safeTargetPath}\" config user.name \"{$gitUserName}\" 2>&1");
    }
    if (!empty($gitUserEmail)) {
        exec("git -C \"{$safeTargetPath}\" config user.email \"{$gitUserEmail}\" 2>&1");
    }

    // Copier les fichiers git-manager si demandé
    $copiedFiles = [];
    $copyErrors = [];
    $subfolder = $input['subfolder'] ?? 'git-manager';
    $copyDestination = '';

    if ($copyGitManager) {
        // Le sous-dossier est obligatoire (toujours git-manager)
        $subfolder = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $subfolder);
        if (empty($subfolder)) {
            $subfolder = 'git-manager'; // Valeur par défaut
        }

        $filesToCopy = ['git-manager.html', 'git-api.php', 'git-config.example.php', 'git-clone.html', 'git-reset-remote.html', 'git-auth.html', 'index.html', 'git-help.html', 'flavicon.svg'];
        $adminDir = __DIR__;

        $copyDestination = $fullTargetPath . DIRECTORY_SEPARATOR . $subfolder;

        // Créer le sous-dossier s'il n'existe pas
        if (!file_exists($copyDestination)) {
            if (!@mkdir($copyDestination, 0755, true)) {
                $copyErrors[] = "Impossible de créer le dossier: {$copyDestination}";
            }
        }

        if (empty($copyErrors)) {
            foreach ($filesToCopy as $file) {
                $sourcePath = $adminDir . DIRECTORY_SEPARATOR . $file;
                $destPath = $copyDestination . DIRECTORY_SEPARATOR . $file;

                if (file_exists($sourcePath)) {
                    if (@copy($sourcePath, $destPath)) {
                        $copiedFiles[] = $file;
                    } else {
                        $copyErrors[] = "Échec copie: {$file}";
                    }
                } else {
                    $copyErrors[] = "Source introuvable: {$file}";
                }
            }
        }
    }

    // Ajouter git-manager/ au .gitignore du dépôt cloné
    if ($copyGitManager) {
        $gitignorePath = $fullTargetPath . '/.gitignore';
        $gitignoreEntry = $subfolder . '/';
        $gitignoreContent = '';
        if (file_exists($gitignorePath)) {
            $gitignoreContent = file_get_contents($gitignorePath);
        }
        if (strpos($gitignoreContent, $gitignoreEntry) === false) {
            $newContent = rtrim($gitignoreContent);
            $newContent .= ($newContent ? "\n\n" : '') . "# Interface de gestion Git\n{$gitignoreEntry}\n";
            file_put_contents($gitignorePath, $newContent);
        }
    }

    $message = "Dépôt cloné avec succès dans '{$targetDir}'";
    if (!empty($copiedFiles)) {
        $location = !empty($subfolder) ? $subfolder . '/' : '';
        $message .= "\nFichiers copiés dans {$location} : " . implode(', ', $copiedFiles);
    }
    if (!empty($copyErrors)) {
        $message .= "\nErreurs : " . implode(', ', $copyErrors);
    }

    echo json_encode([
        'success' => true,
        'output' => $message,
        'data' => [
            'path' => $fullTargetPath,
            'folderName' => $targetDir,
            'subfolder' => $subfolder,
            'copiedFiles' => $copiedFiles,
            'copyErrors' => $copyErrors
        ]
    ]);
    exit;
}

if ($action === 'listFolders') {
    // Lister les dossiers du répertoire parent (pour l'interface de clone)
    $parentDir = dirname($repoPath);
    $folders = [];

    if (is_dir($parentDir)) {
        $items = scandir($parentDir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $itemPath = $parentDir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($itemPath)) {
                $isGit = is_dir($itemPath . DIRECTORY_SEPARATOR . '.git');
                $folders[] = [
                    'name' => $item,
                    'isGitRepo' => $isGit
                ];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'parentDir' => $parentDir,
            'folders' => $folders
        ]
    ]);
    exit;
}

if ($action === 'listRemoteBranches') {
    // Lister les branches distantes
    if (!$isGitRepoCheck) {
        echo json_encode(['success' => false, 'error' => 'Ce dossier n\'est pas un dépôt Git']);
        exit;
    }

    // Faire un fetch pour avoir la liste à jour
    $fetchOutput = [];
    $fetchCode = 0;
    exec("git -C \"{$safeRepoPath}\" fetch --prune 2>&1", $fetchOutput, $fetchCode);
    $fetchError = ($fetchCode !== 0) ? implode("\n", $fetchOutput) : null;

    // Récupérer les branches distantes
    $output = [];
    exec("git -C \"{$safeRepoPath}\" branch -r --format=\"%(refname:short)\" 2>&1", $output, $returnCode);

    $branches = [];
    foreach ($output as $line) {
        $line = trim($line);
        if (empty($line)) continue;
        if (strpos($line, '/HEAD') !== false) continue; // Ignorer HEAD
        // Extraire le nom de branche sans le préfixe origin/
        if (preg_match('/^origin\/(.+)$/', $line, $matches)) {
            $branches[] = $matches[1];
        }
    }

    $response = [
        'success' => true,
        'data' => [
            'branches' => $branches
        ]
    ];

    // Ajouter l'erreur de fetch si elle existe (pour diagnostic SSH)
    if ($fetchError) {
        $response['fetchError'] = $fetchError;
    }

    echo json_encode($response);
    exit;
}

if ($action === 'resetRemote') {
    // Réinitialiser le dépôt distant (méthode branche orpheline)
    // ATTENTION: Cette action est IRRÉVERSIBLE et supprime tout l'historique distant

    if (!$isGitRepoCheck) {
        echo json_encode(['success' => false, 'error' => 'Ce dossier n\'est pas un dépôt Git']);
        exit;
    }

    $branchName = $input['branchName'] ?? 'main';

    // Valider le nom de branche
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $branchName)) {
        echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']);
        exit;
    }

    // Vérifier qu'un remote origin existe
    $remoteUrl = trim(shell_exec("git -C \"{$safeRepoPath}\" config --get remote.origin.url 2>&1") ?? '');
    if (empty($remoteUrl) || strpos($remoteUrl, 'fatal:') !== false) {
        echo json_encode(['success' => false, 'error' => 'Aucun remote origin configuré']);
        exit;
    }

    $branchEscaped = escapeshellarg($branchName);
    $output = [];
    $allOutput = [];

    // Étape 1: Créer une branche orpheline
    $allOutput[] = "→ Création de la branche orpheline '{$branchName}'...";
    exec("git -C \"{$safeRepoPath}\" checkout --orphan {$branchEscaped} 2>&1", $output, $returnCode);
    $allOutput[] = implode("\n", $output);

    if ($returnCode !== 0) {
        echo json_encode([
            'success' => false,
            'error' => "Erreur lors de la création de la branche orpheline:\n" . implode("\n", $output),
            'output' => implode("\n", $allOutput)
        ]);
        exit;
    }

    // Étape 2: Retirer tous les fichiers de l'index Git
    $output = [];
    $allOutput[] = "\n→ Suppression des fichiers de l'index Git...";
    exec("git -C \"{$safeRepoPath}\" rm -rf --cached . 2>&1", $output, $returnCode);
    $allOutput[] = implode("\n", $output);

    // Note: git rm --cached peut retourner un code d'erreur si certains fichiers ne sont pas suivis,
    // mais ce n'est pas bloquant

    // Étape 3: Créer un commit vide initial
    $output = [];
    $allOutput[] = "\n→ Création du commit initial vide...";
    exec("git -C \"{$safeRepoPath}\" commit --allow-empty -m \"Initial commit (reset repository)\" 2>&1", $output, $returnCode);
    $allOutput[] = implode("\n", $output);

    if ($returnCode !== 0) {
        echo json_encode([
            'success' => false,
            'error' => "Erreur lors du commit:\n" . implode("\n", $output),
            'output' => implode("\n", $allOutput)
        ]);
        exit;
    }

    // Étape 4: Force push vers origin
    $output = [];
    $allOutput[] = "\n→ Force push vers origin/{$branchName}...";
    exec("git -C \"{$safeRepoPath}\" push -f origin {$branchEscaped} 2>&1", $output, $returnCode);
    $allOutput[] = implode("\n", $output);

    if ($returnCode !== 0) {
        echo json_encode([
            'success' => false,
            'error' => "Erreur lors du push:\n" . implode("\n", $output),
            'output' => implode("\n", $allOutput)
        ]);
        exit;
    }

    // Étape 5 (optionnelle): Supprimer les autres branches distantes
    $deleteOtherBranches = $input['deleteOtherBranches'] ?? false;
    $branchesToDelete = $input['branchesToDelete'] ?? [];

    if ($deleteOtherBranches && !empty($branchesToDelete)) {
        $allOutput[] = "\n→ Suppression des autres branches distantes...";
        $deletedBranches = [];
        $failedBranches = [];

        foreach ($branchesToDelete as $branch) {
            // Ne pas supprimer la branche qu'on vient de créer
            if ($branch === $branchName) continue;

            $branchToDeleteEscaped = escapeshellarg($branch);
            $output = [];
            exec("git -C \"{$safeRepoPath}\" push origin --delete {$branchToDeleteEscaped} 2>&1", $output, $returnCode);

            if ($returnCode === 0) {
                $deletedBranches[] = $branch;
                $allOutput[] = "  ✓ Branche '{$branch}' supprimée";
            } else {
                $failedBranches[] = $branch;
                $allOutput[] = "  ✗ Échec suppression '{$branch}': " . implode(" ", $output);
            }
        }

        if (!empty($deletedBranches)) {
            $allOutput[] = "\n" . count($deletedBranches) . " branche(s) supprimée(s) du dépôt distant.";
        }
    }

    $allOutput[] = "\n✓ Dépôt distant réinitialisé avec succès!";
    $allOutput[] = "La branche '{$branchName}' sur origin ne contient plus qu'un commit vide.";
    $allOutput[] = "\nATTENTION: Vos fichiers locaux sont toujours présents mais non suivis par Git.";
    $allOutput[] = "Vous pouvez maintenant les ajouter avec 'git add' pour créer un nouveau commit.";

    echo json_encode([
        'success' => true,
        'output' => implode("\n", $allOutput)
    ]);
    exit;
}

// Pour toutes les autres actions, vérifier qu'on est dans un dépôt Git
if (!$isGitRepoCheck) {
    echo json_encode(['success' => false, 'error' => 'Ce dossier n\'est pas un dépôt Git', 'needsInit' => true]);
    exit;
}

// Fonction pour exécuter une commande Git de manière sécurisée
function execGit($command) {
    global $repoPath;
    $output = [];
    $returnCode = 0;

    // Extraire la sous-commande git (après "git ")
    $safeRepoPath = str_replace('\\', '/', $repoPath);

    if (strpos($command, 'git ') === 0) {
        $gitSubCommand = substr($command, 4); // Enlève "git " du début
        $fullCommand = "git -C \"{$safeRepoPath}\" {$gitSubCommand}";
    } else {
        $fullCommand = $command;
    }

    exec($fullCommand . ' 2>&1', $output, $returnCode);
    return [
        'output' => implode("\n", $output),
        'code' => $returnCode
    ];
}

// Fonction pour valider un nom de fichier (sécurité)
function isValidFile($file) {
    // Empêcher les attaques par traversée de répertoire
    $file = str_replace(['..', '\\'], ['', '/'], $file);
    if (strpos($file, '..') !== false) {
        return false;
    }
    return true;
}

// Fonction pour échapper les arguments shell
function escapeArg($arg) {
    return escapeshellarg($arg);
}

// Fonction pour filtrer les avertissements Git de la liste des fichiers
function filterGitWarnings($lines) {
    return array_filter($lines, function($line) {
        $line = trim($line);
        if (empty($line)) return false;
        // Filtrer les avertissements courants de Git
        if (stripos($line, 'warning:') === 0) return false;
        if (stripos($line, 'error:') === 0) return false;
        if (stripos($line, 'fatal:') === 0) return false;
        if (stripos($line, 'The file will have') !== false) return false;
        if (stripos($line, 'LF will be replaced') !== false) return false;
        if (stripos($line, 'CRLF will be replaced') !== false) return false;
        return true;
    });
}

// Router les actions
switch ($action) {
    case 'status':
        // Obtenir le statut du dépôt
        $branch = trim(execGit('git branch --show-current')['output']);

        // Détecter HEAD détaché
        $isDetached = empty($branch);
        $detachedAt = '';
        if ($isDetached) {
            $detachedAt = trim(execGit('git rev-parse --short HEAD')['output']);
        }

        // Fichiers modifiés (tracked)
        $modifiedResult = execGit('git diff --name-only');
        $modified = filterGitWarnings(explode("\n", $modifiedResult['output']));

        // Fichiers staged pour ajout/modification
        $stagedResult = execGit('git diff --cached --name-only --diff-filter=AM');
        $staged = filterGitWarnings(explode("\n", $stagedResult['output']));

        // Fichiers staged pour suppression
        $stagedDeletedResult = execGit('git diff --cached --name-only --diff-filter=D');
        $stagedDeleted = filterGitWarnings(explode("\n", $stagedDeletedResult['output']));

        // Fichiers non suivis
        $untrackedResult = execGit('git ls-files --others --exclude-standard');
        $untracked = filterGitWarnings(explode("\n", $untrackedResult['output']));

        // Commits en avance/retard (seulement si on est sur une branche)
        $ahead = 0;
        $behind = 0;
        if (!$isDetached) {
            $aheadBehind = execGit('git rev-list --left-right --count origin/' . $branch . '...' . $branch);
            $counts = preg_split('/\s+/', trim($aheadBehind['output']));
            $behind = isset($counts[0]) ? intval($counts[0]) : 0;
            $ahead = isset($counts[1]) ? intval($counts[1]) : 0;
        }

        // Tous les fichiers du dépôt
        $allFilesResult = execGit('git ls-files');
        $allFiles = filterGitWarnings(explode("\n", $allFilesResult['output']));

        // Nombre de stashes
        $stashCountResult = execGit('git stash list');
        $stashCount = 0;
        if ($stashCountResult['code'] === 0 && !empty(trim($stashCountResult['output']))) {
            $stashCount = count(array_filter(explode("\n", $stashCountResult['output'])));
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'branch' => $branch,
                'isDetached' => $isDetached,
                'detachedAt' => $detachedAt,
                'modified' => array_values($modified),
                'staged' => array_values($staged),
                'stagedDeleted' => array_values($stagedDeleted),
                'untracked' => array_values($untracked),
                'ahead' => $ahead,
                'behind' => $behind,
                'allFiles' => array_values($allFiles),
                'stashCount' => $stashCount
            ]
        ]);
        break;

    case 'repoInfo':
        // Obtenir les informations du dépôt
        $remoteUrl = trim(execGit('git config --get remote.origin.url')['output']);

        // Supprimer les identifiants de l'URL (format: https://user:token@github.com/...)
        $remoteUrl = preg_replace('/^(https?:\/\/)[^@]+@/', '$1', $remoteUrl);

        // Extraire le nom du repo depuis l'URL
        $repoName = '';
        if (preg_match('/github\.com[\/:]([^\/]+\/[^\/\.]+)/', $remoteUrl, $matches)) {
            $repoName = $matches[1];
        } elseif (preg_match('/([^\/]+\/[^\/\.]+)(\.git)?$/', $remoteUrl, $matches)) {
            $repoName = $matches[1];
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'url' => $remoteUrl,
                'name' => $repoName,
                'path' => $repoPath,
                'hasRemote' => !empty($remoteUrl)
            ]
        ]);
        break;

    case 'addRemote':
        // Ajouter un remote origin
        $url = $input['url'] ?? '';

        if (empty($url)) {
            echo json_encode(['success' => false, 'error' => 'URL du dépôt requise']);
            break;
        }

        // Vérifier si un remote existe déjà
        $existingRemote = trim(execGit('git config --get remote.origin.url')['output']);
        if (!empty($existingRemote)) {
            // Modifier le remote existant
            $result = execGit("git remote set-url origin \"{$url}\"");
            $message = 'Remote origin mis à jour';
        } else {
            // Ajouter un nouveau remote
            $result = execGit("git remote add origin \"{$url}\"");
            $message = 'Remote origin ajouté';
        }

        if ($result['code'] === 0) {
            // Faire un fetch pour récupérer les branches distantes
            $fetchResult = execGit("git fetch origin 2>&1");

            $response = [
                'success' => true,
                'output' => $message . ' : ' . $url
            ];

            // Avertir si le fetch a échoué (problème SSH probable)
            if ($fetchResult['code'] !== 0) {
                $response['fetchError'] = $fetchResult['output'];
                $response['warning'] = 'Remote ajouté mais impossible de récupérer les branches (vérifiez l\'authentification SSH ou HTTPS)';
            }

            echo json_encode($response);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $result['output']
            ]);
        }
        break;

    case 'removeRemote':
        // Supprimer le remote origin
        $result = execGit('git remote remove origin');

        if ($result['code'] === 0) {
            echo json_encode([
                'success' => true,
                'output' => 'Remote origin supprimé'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $result['output']
            ]);
        }
        break;

    case 'log':
        // Obtenir l'historique des commits
        $logResult = execGit('git log --oneline -20 --format="%h|%s|%an|%ad" --date=short');
        $commits = [];

        foreach (explode("\n", $logResult['output']) as $line) {
            if (empty(trim($line))) continue;
            $parts = explode('|', $line, 4);
            if (count($parts) >= 4) {
                $commits[] = [
                    'hash' => $parts[0],
                    'message' => $parts[1],
                    'author' => $parts[2],
                    'date' => $parts[3]
                ];
            }
        }

        echo json_encode(['success' => true, 'data' => $commits]);
        break;

    case 'fileLog':
        // Obtenir l'historique des commits pour un fichier spécifique
        $file = $input['file'] ?? '';

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']);
            exit;
        }

        $fileEscaped = escapeArg($file);
        $logResult = execGit("git log --oneline -10 --format=\"%h|%s|%ad\" --date=short -- {$fileEscaped}");
        $commits = [];

        foreach (explode("\n", $logResult['output']) as $line) {
            if (empty(trim($line))) continue;
            $parts = explode('|', $line, 3);
            if (count($parts) >= 3) {
                $commits[] = [
                    'hash' => $parts[0],
                    'message' => $parts[1],
                    'date' => $parts[2]
                ];
            }
        }

        echo json_encode(['success' => true, 'data' => $commits]);
        break;

    case 'stageFiles':
        // Stager des fichiers (git add) sans committer
        $files = $input['files'] ?? [];

        if (empty($files)) {
            echo json_encode(['success' => false, 'error' => 'Aucun fichier sélectionné']);
            exit;
        }

        // Valider les fichiers
        foreach ($files as $file) {
            if (!isValidFile($file)) {
                echo json_encode(['success' => false, 'error' => 'Nom de fichier invalide: ' . $file]);
                exit;
            }
        }

        // Ajouter les fichiers
        $filesEscaped = implode(' ', array_map('escapeArg', $files));
        $addResult = execGit('git add ' . $filesEscaped);

        if ($addResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Erreur git add: ' . $addResult['output']]);
            exit;
        }

        $count = count($files);
        echo json_encode([
            'success' => true,
            'output' => "{$count} fichier(s) stagé(s) pour le prochain commit"
        ]);
        break;

    case 'unstageFiles':
        // Désindexer des fichiers (git reset) sans supprimer
        $files = $input['files'] ?? [];

        if (empty($files)) {
            echo json_encode(['success' => false, 'error' => 'Aucun fichier sélectionné']);
            exit;
        }

        // Valider les fichiers
        foreach ($files as $file) {
            if (!isValidFile($file)) {
                echo json_encode(['success' => false, 'error' => 'Nom de fichier invalide: ' . $file]);
                exit;
            }
        }

        // Désindexer les fichiers
        $filesEscaped = implode(' ', array_map('escapeArg', $files));
        $resetResult = execGit('git reset HEAD -- ' . $filesEscaped);

        if ($resetResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Erreur git reset: ' . $resetResult['output']]);
            exit;
        }

        $count = count($files);
        echo json_encode([
            'success' => true,
            'output' => "{$count} fichier(s) retiré(s) du staging"
        ]);
        break;

    case 'commit':
        // Faire un commit
        $files = $input['files'] ?? [];
        $message = $input['message'] ?? '';
        $hasStaged = $input['hasStaged'] ?? false; // Indique s'il y a des fichiers déjà stagés

        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Message de commit requis']);
            exit;
        }

        if (empty($files) && !$hasStaged) {
            echo json_encode(['success' => false, 'error' => 'Fichiers requis']);
            exit;
        }

        // Valider et ajouter les fichiers (si fournis)
        if (!empty($files)) {
            foreach ($files as $file) {
                if (!isValidFile($file)) {
                    echo json_encode(['success' => false, 'error' => 'Nom de fichier invalide']);
                    exit;
                }
            }

            // Ajouter les fichiers
            $filesEscaped = implode(' ', array_map('escapeArg', $files));
            $addResult = execGit('git add ' . $filesEscaped);

            if ($addResult['code'] !== 0) {
                echo json_encode(['success' => false, 'error' => 'Erreur git add: ' . $addResult['output']]);
                exit;
            }
        }

        // Faire le commit
        $messageEscaped = escapeArg($message);
        $commitResult = execGit('git commit -m ' . $messageEscaped);

        if ($commitResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Erreur git commit: ' . $commitResult['output']]);
            exit;
        }

        echo json_encode(['success' => true, 'output' => $commitResult['output']]);
        break;

    case 'commitAndPush':
        // Commit et push en une seule opération
        $files = $input['files'] ?? [];
        $message = $input['message'] ?? '';
        $hasStaged = $input['hasStaged'] ?? false;

        if (empty($message)) {
            echo json_encode(['success' => false, 'error' => 'Message de commit requis']);
            exit;
        }

        if (empty($files) && !$hasStaged) {
            echo json_encode(['success' => false, 'error' => 'Fichiers requis']);
            exit;
        }

        // Valider et ajouter les fichiers (si fournis)
        if (!empty($files)) {
            foreach ($files as $file) {
                if (!isValidFile($file)) {
                    echo json_encode(['success' => false, 'error' => 'Nom de fichier invalide']);
                    exit;
                }
            }

            // Ajouter les fichiers
            $filesEscaped = implode(' ', array_map('escapeArg', $files));
            $addResult = execGit('git add ' . $filesEscaped);

            if ($addResult['code'] !== 0) {
                echo json_encode(['success' => false, 'error' => 'Erreur git add: ' . $addResult['output']]);
                exit;
            }
        }

        // Faire le commit
        $messageEscaped = escapeArg($message);
        $commitResult = execGit('git commit -m ' . $messageEscaped);

        if ($commitResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Erreur git commit: ' . $commitResult['output']]);
            exit;
        }

        // Push - vérifier si upstream existe
        $currentBranch = trim(execGit('git branch --show-current')['output']);
        $upstream = trim(execGit("git config --get branch.{$currentBranch}.remote")['output']);

        if (empty($upstream)) {
            // Premier push - utiliser -u pour configurer l'upstream
            $pushResult = execGit("git push -u origin {$currentBranch}");
        } else {
            $pushResult = execGit('git push origin');
        }

        if ($pushResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Commit OK, mais erreur push: ' . $pushResult['output']]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'output' => "Commit:\n" . $commitResult['output'] . "\n\nPush:\n" . $pushResult['output']
        ]);
        break;

    case 'revertCommit':
        $hash = $input['hash'] ?? '';

        if (empty($hash) || !preg_match('/^[a-f0-9]{4,40}$/i', $hash)) {
            echo json_encode(['success' => false, 'error' => 'Hash invalide']);
            exit;
        }

        $result = execGit('git revert ' . escapeshellarg($hash) . ' --no-edit');

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
            exit;
        }

        echo json_encode(['success' => true, 'output' => $result['output']]);
        break;

    case 'amendLastCommit':
        $files = $input['files'] ?? [];
        $hasStaged = $input['hasStaged'] ?? false;
        $message = trim($input['message'] ?? '');

        // Vérifie si des fichiers sont déjà stagés dans git (git diff --cached)
        $alreadyStaged = trim(execGit('git diff --cached --name-only')['output']) !== '';

        if (empty($files) && !$hasStaged && $message === '' && !$alreadyStaged) {
            echo json_encode(['success' => false, 'error' => 'Fichiers ou message requis']);
            exit;
        }

        // Valider et ajouter les fichiers (si fournis)
        if (!empty($files)) {
            foreach ($files as $file) {
                if (!isValidFile($file)) {
                    echo json_encode(['success' => false, 'error' => 'Nom de fichier invalide']);
                    exit;
                }
            }

            $filesEscaped = implode(' ', array_map('escapeArg', $files));
            $addResult = execGit('git add ' . $filesEscaped);

            if ($addResult['code'] !== 0) {
                echo json_encode(['success' => false, 'error' => 'Erreur git add: ' . $addResult['output']]);
                exit;
            }
        }

        // Amender : avec ou sans changement de message
        if ($message !== '') {
            $amendResult = execGit('git commit --amend -m ' . escapeArg($message));
        } else {
            $amendResult = execGit('git commit --amend --no-edit');
        }

        if ($amendResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Erreur git commit --amend: ' . $amendResult['output']]);
            exit;
        }

        echo json_encode(['success' => true, 'output' => $amendResult['output']]);
        break;

    case 'push':
        // Obtenir la branche courante
        $currentBranch = trim(execGit('git branch --show-current')['output']);

        if (empty($currentBranch)) {
            echo json_encode(['success' => false, 'error' => 'HEAD détaché - impossible de push']);
            break;
        }

        // Vérifier si la branche a au moins un commit
        $hasCommits = execGit('git rev-parse HEAD');
        if ($hasCommits['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Aucun commit sur la branche — faites un premier commit avant de push']);
            break;
        }

        // Vérifier si la branche a un upstream configuré
        $upstream = trim(execGit("git config --get branch.{$currentBranch}.remote")['output']);

        if (empty($upstream)) {
            // Premier push - utiliser -u pour configurer l'upstream
            $result = execGit("git push -u origin {$currentBranch}");
            $message = "Branche '{$currentBranch}' publiée et push effectué";
        } else {
            // Push normal
            $result = execGit('git push origin');
            $message = 'Push effectué';
        }

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output'] ?: $message]);
        }
        break;

    case 'pull':
        $rebase = !empty($input['rebase']);
        $cmd = $rebase ? 'git pull --rebase origin' : 'git pull origin';
        $result = execGit($cmd);

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output']]);
        }
        break;

    case 'fetch':
        $result = execGit('git fetch origin');

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output'] ?: 'Fetch effectué']);
        }
        break;

    case 'checkout':
        // Récupérer un fichier spécifique
        $file = $input['file'] ?? '';
        $source = $input['source'] ?? 'HEAD';

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']);
            exit;
        }

        // Valider la source (HEAD, HEAD~N, origin/main, ou hash de commit)
        $allowedSources = ['HEAD', 'origin/main', 'origin/master'];
        $isValidSource = in_array($source, $allowedSources)
            || preg_match('/^HEAD~[0-9]+$/', $source)  // HEAD~1, HEAD~2, etc.
            || preg_match('/^[a-f0-9]{7,40}$/', $source);  // Hash de commit

        if (!$isValidSource) {
            $source = 'HEAD';
        }

        $fileEscaped = escapeArg($file);
        $sourceEscaped = escapeArg($source);
        $result = execGit("git checkout {$source} -- {$fileEscaped}");

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output'] ?: 'Fichier récupéré']);
        }
        break;

    case 'discardAll':
        // Annuler toutes les modifications
        $result = execGit('git checkout -- .');

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => 'Modifications annulées']);
        }
        break;

    case 'checkoutCommit':
        // Checkout sur un commit spécifique (HEAD détaché)
        $hash = $input['hash'] ?? '';

        if (empty($hash) || !preg_match('/^[a-f0-9]{7,40}$/', $hash)) {
            echo json_encode(['success' => false, 'error' => 'Hash de commit invalide']);
            break;
        }

        $hashEscaped = escapeArg($hash);

        // Sauvegarder git-manager/ avant le checkout
        $gitManagerPath = $repoPath . DIRECTORY_SEPARATOR . 'git-manager';
        $backupPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git-manager-backup-' . uniqid();
        $backupCreated = false;

        if (is_dir($gitManagerPath)) {
            recurseCopy($gitManagerPath, $backupPath);
            $backupCreated = true;
        }

        $result = execGit("git checkout {$hashEscaped} 2>&1");

        // Restaurer git-manager/ s'il a disparu
        if ($backupCreated) {
            $gitApiFile = $gitManagerPath . DIRECTORY_SEPARATOR . 'git-api.php';
            if (!is_dir($gitManagerPath) || !file_exists($gitApiFile)) {
                recurseCopy($backupPath, $gitManagerPath);
            }
            if (is_dir($backupPath)) {
                recurseDelete($backupPath);
            }
        }

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode([
                'success' => true,
                'output' => "HEAD détaché sur le commit {$hash}"
            ]);
        }
        break;

    case 'showCommit':
        // Voir le détail d'un commit (git show --stat)
        $hash = $input['hash'] ?? 'HEAD';

        if (!preg_match('/^[a-f0-9]{7,40}$/', $hash) && $hash !== 'HEAD') {
            echo json_encode(['success' => false, 'error' => 'Hash de commit invalide']);
            break;
        }

        $hashEscaped = escapeArg($hash);
        $result = execGit('git show --stat ' . $hashEscaped);

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output']]);
        }
        break;

    case 'diff':
        // Voir les différences d'un fichier
        $file = $input['file'] ?? '';

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']);
            exit;
        }

        $fileEscaped = escapeArg($file);
        $result = execGit("git diff {$fileEscaped}");

        echo json_encode(['success' => true, 'output' => $result['output']]);
        break;

    case 'getGitignore':
        // Lire le contenu de .gitignore
        $gitignorePath = $repoPath . '/.gitignore';
        $patterns = [];

        if (file_exists($gitignorePath)) {
            $content = file_get_contents($gitignorePath);
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                $line = trim($line);
                // Ignorer les lignes vides et les commentaires
                if (!empty($line) && $line[0] !== '#') {
                    $patterns[] = $line;
                }
            }
        }

        // Récupérer aussi les fichiers non suivis
        $untrackedResult = execGit('git ls-files --others --exclude-standard');
        $untracked = filterGitWarnings(explode("\n", $untrackedResult['output']));

        echo json_encode([
            'success' => true,
            'data' => [
                'patterns' => $patterns,
                'untracked' => array_values($untracked)
            ]
        ]);
        break;

    case 'addToGitignore':
        // Ajouter un pattern au .gitignore
        $pattern = $input['pattern'] ?? '';

        if (empty($pattern)) {
            echo json_encode(['success' => false, 'error' => 'Pattern requis']);
            exit;
        }

        // Sécurité: empêcher l'injection
        $pattern = trim($pattern);
        if (strpos($pattern, '..') !== false) {
            echo json_encode(['success' => false, 'error' => 'Pattern invalide']);
            exit;
        }

        $gitignorePath = $repoPath . '/.gitignore';
        $content = '';

        if (file_exists($gitignorePath)) {
            $content = file_get_contents($gitignorePath);
            // Vérifier si le pattern existe déjà
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                if (trim($line) === $pattern) {
                    echo json_encode(['success' => false, 'error' => 'Pattern déjà présent']);
                    exit;
                }
            }
            // Ajouter une nouvelle ligne si nécessaire
            if (!empty($content) && substr($content, -1) !== "\n") {
                $content .= "\n";
            }
        }

        $content .= $pattern . "\n";

        if (file_put_contents($gitignorePath, $content) !== false) {
            echo json_encode(['success' => true, 'output' => 'Pattern ajouté']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur d\'écriture']);
        }
        break;

    case 'removeFromGitignore':
        // Retirer un pattern du .gitignore
        $pattern = $input['pattern'] ?? '';

        if (empty($pattern)) {
            echo json_encode(['success' => false, 'error' => 'Pattern requis']);
            exit;
        }

        $gitignorePath = $repoPath . '/.gitignore';

        if (!file_exists($gitignorePath)) {
            echo json_encode(['success' => false, 'error' => 'Fichier .gitignore inexistant']);
            exit;
        }

        $content = file_get_contents($gitignorePath);
        $lines = explode("\n", $content);
        $newLines = [];
        $found = false;

        foreach ($lines as $line) {
            if (trim($line) === $pattern) {
                $found = true;
            } else {
                $newLines[] = $line;
            }
        }

        if (!$found) {
            echo json_encode(['success' => false, 'error' => 'Pattern non trouvé']);
            exit;
        }

        // Supprimer les lignes vides à la fin
        while (!empty($newLines) && trim(end($newLines)) === '') {
            array_pop($newLines);
        }

        $newContent = implode("\n", $newLines);
        if (!empty($newContent)) {
            $newContent .= "\n";
        }

        if (file_put_contents($gitignorePath, $newContent) !== false) {
            echo json_encode(['success' => true, 'output' => 'Pattern retiré']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur d\'écriture']);
        }
        break;

    case 'removeFromRepo':
        // Supprimer un fichier ou dossier du dépôt ET du disque (git rm)
        $file = $input['file'] ?? '';
        $isDir = $input['isDir'] ?? false;

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']);
            exit;
        }

        $fileEscaped = escapeArg($file);
        $flag = $isDir ? '-r ' : '';
        $result = execGit("git rm {$flag}{$fileEscaped}");

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            $label = $isDir ? "Dossier '{$file}'" : "Fichier '{$file}'";
            echo json_encode(['success' => true, 'output' => $result['output'] ?: "{$label} supprimé du dépôt et du disque"]);
        }
        break;

    case 'untrackFile':
        // Arrêter le suivi d'un fichier ou dossier sans le supprimer du disque (git rm --cached)
        $file = $input['file'] ?? '';
        $isDir = $input['isDir'] ?? false;

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']);
            exit;
        }

        $fileEscaped = escapeArg($file);
        $flag = $isDir ? '-r ' : '';
        $result = execGit("git rm --cached {$flag}{$fileEscaped}");

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            $label = $isDir ? "Dossier '{$file}'" : "Fichier '{$file}'";
            echo json_encode(['success' => true, 'output' => $result['output'] ?: "{$label} retiré du suivi Git (conservé sur le disque)"]);
        }
        break;

    case 'branches':
        // Lister toutes les branches
        $currentBranch = trim(execGit('git branch --show-current')['output']);

        // Détecter l'état "HEAD détaché"
        $isDetached = empty($currentBranch);
        $detachedAt = '';
        if ($isDetached) {
            // Récupérer sur quoi le HEAD est détaché
            $headRef = trim(execGit('git rev-parse --short HEAD')['output']);
            $detachedAt = $headRef;
        }

        // Branches locales
        $localResult = execGit('git branch --format="%(refname:short)|%(upstream:short)|%(upstream:track)"');
        $localBranches = [];
        foreach (explode("\n", $localResult['output']) as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            // Ignorer la ligne HEAD détaché (ce n'est pas une branche)
            if (strpos($line, '(HEAD') === 0) continue;
            $parts = explode('|', $line);
            $name = $parts[0];
            $upstream = $parts[1] ?? '';
            $track = $parts[2] ?? '';

            // Parser le tracking (ahead/behind)
            $ahead = 0;
            $behind = 0;
            if (preg_match('/ahead (\d+)/', $track, $m)) $ahead = (int)$m[1];
            if (preg_match('/behind (\d+)/', $track, $m)) $behind = (int)$m[1];

            $localBranches[] = [
                'name' => $name,
                'current' => ($name === $currentBranch),
                'upstream' => $upstream,
                'ahead' => $ahead,
                'behind' => $behind
            ];
        }

        // Si aucune branche listée mais HEAD sur une branche (cas après git init sans commit)
        if (empty($localBranches) && !empty($currentBranch)) {
            $localBranches[] = [
                'name' => $currentBranch,
                'current' => true,
                'upstream' => '',
                'ahead' => 0,
                'behind' => 0
            ];
        }

        // Branches distantes
        $remoteResult = execGit('git branch -r --format="%(refname:short)"');
        $remoteBranches = filterGitWarnings(explode("\n", $remoteResult['output']));
        // Filtrer HEAD et les branches déjà trackées
        $remoteBranches = array_filter($remoteBranches, function($b) use ($localBranches) {
            if (strpos($b, '/HEAD') !== false) return false;
            foreach ($localBranches as $local) {
                if ($local['upstream'] === $b) return false;
            }
            return true;
        });

        echo json_encode([
            'success' => true,
            'data' => [
                'current' => $currentBranch,
                'local' => $localBranches,
                'remote' => array_values($remoteBranches),
                'isDetached' => $isDetached,
                'detachedAt' => $detachedAt
            ]
        ]);
        break;

    case 'switchBranch':
        // Changer de branche
        $branch = $input['branch'] ?? '';
        $force = $input['force'] ?? false;

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']);
            exit;
        }

        // Valider le nom de branche (sécurité)
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']);
            exit;
        }

        $forceFlag = $force ? '-f ' : '';

        // Sauvegarder git-manager/ avant le checkout
        $gitManagerPath = $repoPath . DIRECTORY_SEPARATOR . 'git-manager';
        $backupPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git-manager-backup-' . uniqid();
        $backupCreated = false;

        if (is_dir($gitManagerPath)) {
            recurseCopy($gitManagerPath, $backupPath);
            $backupCreated = true;
        }

        // Sauvegarder TOUS les fichiers non suivis avant le checkout
        // (ils pourraient être écrasés ou supprimés lors du changement de branche)
        $allUntrackedFiles = [];
        $untrackedTempBackup = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git-untracked-switch-' . uniqid();
        $untrackedTempCreated = false;

        // Fichiers non suivis normaux
        $untrackedResult = execGit("git ls-files --others --exclude-standard");
        if ($untrackedResult['code'] === 0 && !empty(trim($untrackedResult['output']))) {
            $allUntrackedFiles = array_merge($allUntrackedFiles, array_filter(explode("\n", trim($untrackedResult['output']))));
        }

        // Fichiers ignorés par .gitignore (sauf git-manager/)
        $ignoredResult = execGit("git ls-files --others --ignored --exclude-standard");
        if ($ignoredResult['code'] === 0 && !empty(trim($ignoredResult['output']))) {
            $allUntrackedFiles = array_merge($allUntrackedFiles, array_filter(explode("\n", trim($ignoredResult['output']))));
        }

        // Sauvegarder ces fichiers
        if (!empty($allUntrackedFiles)) {
            mkdir($untrackedTempBackup, 0755, true);
            foreach ($allUntrackedFiles as $file) {
                $file = trim($file);
                if (empty($file)) continue;
                // Exclure git-manager/ car géré séparément
                if (strpos($file, 'git-manager/') === 0 || strpos($file, 'git-manager\\') === 0) continue;

                $srcFile = $repoPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
                $dstFile = $untrackedTempBackup . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);

                if (file_exists($srcFile) && is_file($srcFile)) {
                    $dstDir = dirname($dstFile);
                    if (!is_dir($dstDir)) {
                        mkdir($dstDir, 0755, true);
                    }
                    copy($srcFile, $dstFile);
                    $untrackedTempCreated = true;
                }
            }
        }

        // Vérifier si c'est une branche distante (origin/xxx)
        if (preg_match('/^origin\/(.+)$/', $branch, $matches)) {
            $localBranchName = $matches[1];
            $localBranchEscaped = escapeArg($localBranchName);
            $remoteBranchEscaped = escapeArg($branch);

            // Vérifier si la branche locale existe déjà
            $checkResult = execGit("git branch --list {$localBranchEscaped}");
            $localExists = !empty(trim($checkResult['output']));

            if ($localExists) {
                $result = execGit("git checkout {$forceFlag}{$localBranchEscaped}");
                // Si échec à cause de fichiers, forcer
                if ($result['code'] !== 0 && (strpos($result['output'], 'overwritten') !== false || strpos($result['output'], 'would be overwritten') !== false || strpos($result['output'], 'écrasé') !== false)) {
                    $result = execGit("git checkout -f {$localBranchEscaped}");
                }
                $message = "Basculé sur la branche '{$localBranchName}'";
            } else {
                $result = execGit("git checkout {$forceFlag}-b {$localBranchEscaped} --track {$remoteBranchEscaped}");
                $message = "Branche locale '{$localBranchName}' créée depuis '{$branch}'";
            }
        } else {
            $branchEscaped = escapeArg($branch);
            $result = execGit("git checkout {$forceFlag}{$branchEscaped}");
            // Si échec à cause de fichiers, forcer
            if ($result['code'] !== 0 && (strpos($result['output'], 'overwritten') !== false || strpos($result['output'], 'would be overwritten') !== false || strpos($result['output'], 'écrasé') !== false)) {
                $result = execGit("git checkout -f {$branchEscaped}");
            }
            $message = "Basculé sur la branche '{$branch}'";
        }

        // TOUJOURS restaurer git-manager/ s'il a disparu ou est incomplet
        $gitManagerRestored = false;
        if ($backupCreated) {
            $gitApiFile = $gitManagerPath . DIRECTORY_SEPARATOR . 'git-api.php';
            if (!is_dir($gitManagerPath) || !file_exists($gitApiFile)) {
                // Copier depuis le backup (sans supprimer car le script peut tourner dedans)
                recurseCopy($backupPath, $gitManagerPath);
                $gitManagerRestored = true;
            }
            // Nettoyer le backup temporaire
            if (is_dir($backupPath)) {
                recurseDelete($backupPath);
            }
        }

        // Ajouter info sur restauration git-manager
        if ($gitManagerRestored) {
            $message .= " (git-manager restauré)";
        }

        // Si checkout réussi, vérifier si c'est une branche "vide" (orpheline avec seulement git-manager/)
        if ($result['code'] === 0) {
            $trackedFiles = execGit("git ls-files");
            $trackedList = array_filter(explode("\n", trim($trackedFiles['output'])));
            $isEmptyBranch = true;
            foreach ($trackedList as $file) {
                if (strpos($file, 'git-manager/') !== 0) {
                    $isEmptyBranch = false;
                    break;
                }
            }

            if ($isEmptyBranch && !empty($trackedList)) {
                // Branche vide: nettoyer les fichiers non suivis qui restent
                // (sauf ceux sauvegardés dans $untrackedTempBackup qui seront restaurés plus tard)
                execGit("git clean -fd -e git-manager/ -e .git/");
            }

            // Restaurer TOUS les fichiers non suivis sauvegardés avant le checkout
            if ($untrackedTempCreated && is_dir($untrackedTempBackup)) {
                recurseCopy($untrackedTempBackup, $repoPath);
                recurseDelete($untrackedTempBackup);
            }

            // Nettoyer les dossiers vides
            cleanEmptyDirs($repoPath);
        } else {
            // Checkout échoué, nettoyer le backup
            if ($untrackedTempCreated && is_dir($untrackedTempBackup)) {
                recurseDelete($untrackedTempBackup);
            }
        }

        if ($result['code'] !== 0) {
            // Détecter si l'erreur est due à des fichiers non suivis
            $needsForce = strpos($result['output'], 'untracked working tree files would be overwritten') !== false
                       || strpos($result['output'], 'Please move or remove them') !== false;
            echo json_encode([
                'success' => false,
                'error' => $result['output'],
                'needsForce' => $needsForce
            ]);
        } else {
            echo json_encode(['success' => true, 'output' => $message]);
        }
        break;

    case 'createBranch':
        // Créer une nouvelle branche (optionnellement depuis une branche source ou orpheline)
        $branch = $input['branch'] ?? '';
        $checkout = $input['checkout'] ?? true;
        $sourceBranch = $input['sourceBranch'] ?? ''; // Branche source optionnelle
        $orphan = $input['orphan'] ?? false; // Branche orpheline (vide, sans historique)
        $freshStart = $input['freshStart'] ?? false; // Nouveau départ (fichiers sans historique)

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']);
            exit;
        }

        // Valider le nom de branche
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide. Utilisez uniquement lettres, chiffres, tirets, underscores et points.']);
            exit;
        }

        // Valider la branche source si fournie
        if (!empty($sourceBranch) && !preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $sourceBranch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche source invalide']);
            exit;
        }

        $branchEscaped = escapeArg($branch);
        $sourceEscaped = !empty($sourceBranch) ? escapeArg($sourceBranch) : '';

        // Branche orpheline (vide, sans historique, mais avec git-manager/)
        if ($orphan) {
            // Sauvegarder la branche actuelle pour y revenir après
            $currentBranchResult = execGit("git branch --show-current");
            $originalBranch = trim($currentBranchResult['output']);
            if (empty($originalBranch)) {
                $headResult = execGit("git rev-parse --abbrev-ref HEAD");
                $originalBranch = trim($headResult['output']);
            }

            // Créer une branche orpheline
            $result = execGit("git checkout --orphan {$branchEscaped}");
            if ($result['code'] !== 0) {
                echo json_encode(['success' => false, 'error' => $result['output']]);
                break;
            }

            // Retirer tous les fichiers de l'index (mais les garder sur le disque)
            execGit("git rm -rf --cached . 2>&1");

            // Ré-ajouter le dossier git-manager/ pour garder l'interface accessible
            // -f pour forcer l'ajout même si le dossier est dans .gitignore
            execGit("git add -f git-manager/ 2>&1");

            // Vérifier si quelque chose a été ajouté
            $statusResult = execGit("git status --porcelain");
            $hasStaged = !empty(trim($statusResult['output']));

            // Créer le commit initial avec git-manager/
            if ($hasStaged) {
                $commitResult = execGit("git commit -m \"Initial commit (branche vide avec git-manager)\"");
            } else {
                $commitResult = execGit("git commit --allow-empty -m \"Initial commit (branche vide)\"");
            }

            if ($commitResult['code'] !== 0) {
                echo json_encode(['success' => false, 'error' => "Branche créée mais erreur lors du commit initial: " . $commitResult['output']]);
                break;
            }

            // Revenir automatiquement à la branche d'origine
            $returnMessage = "";
            if (!empty($originalBranch) && $originalBranch !== 'HEAD') {
                // Sauvegarder git-manager/ avant le checkout
                // (nécessaire si git-manager est dans .gitignore sur la branche cible)
                $gitManagerPath = $repoPath . DIRECTORY_SEPARATOR . 'git-manager';
                $backupPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git-manager-backup-' . uniqid();
                $backupCreated = false;

                if (is_dir($gitManagerPath)) {
                    recurseCopy($gitManagerPath, $backupPath);
                    $backupCreated = true;
                }

                // Checkout avec force
                $checkoutBack = execGit("git checkout -f " . escapeArg($originalBranch) . " 2>&1");

                // TOUJOURS restaurer git-manager/ s'il a disparu ou est incomplet
                if ($backupCreated) {
                    $gitApiFile = $gitManagerPath . DIRECTORY_SEPARATOR . 'git-api.php';
                    if (!is_dir($gitManagerPath) || !file_exists($gitApiFile)) {
                        // Copier depuis le backup (sans supprimer car le script tourne dedans)
                        recurseCopy($backupPath, $gitManagerPath);
                    }
                    // Nettoyer le backup temporaire
                    if (is_dir($backupPath)) {
                        recurseDelete($backupPath);
                    }
                }

                // Nettoyer les dossiers vides laissés par git
                cleanEmptyDirs($repoPath);

                if ($checkoutBack['code'] === 0) {
                    $returnMessage = "\n✓ Retour sur '{$originalBranch}'";
                } else {
                    $returnMessage = "\n⚠️ Impossible de revenir sur '{$originalBranch}'";
                }
            }

            $message = $hasStaged
                ? "Branche '{$branch}' créée (vide avec git-manager/)" . $returnMessage
                : "Branche '{$branch}' créée (vide) - git-manager/ non trouvé" . $returnMessage;

            echo json_encode([
                'success' => true,
                'output' => $message
            ]);
            break;
        }

        // Nouveau départ (fichiers actuels sans historique)
        if ($freshStart) {
            // Sauvegarder la branche actuelle pour y revenir après
            $currentBranchResult = execGit("git branch --show-current");
            $originalBranch = trim($currentBranchResult['output']);
            if (empty($originalBranch)) {
                $headResult = execGit("git rev-parse --abbrev-ref HEAD");
                $originalBranch = trim($headResult['output']);
            }

            // Sauvegarder TOUS les fichiers non suivis :
            // - les fichiers non suivis normaux (seront ajoutés par git add -A puis perdus au checkout retour)
            // - les fichiers ignorés par .gitignore (ne seront pas ajoutés par git add -A)
            $allUntracked = [];

            // Fichiers non suivis normaux (pas dans .gitignore)
            $untrackedResult = execGit("git ls-files --others --exclude-standard");
            if ($untrackedResult['code'] === 0 && !empty(trim($untrackedResult['output']))) {
                $allUntracked = array_merge($allUntracked, array_filter(explode("\n", trim($untrackedResult['output']))));
            }

            // Fichiers ignorés par .gitignore
            $ignoredResult = execGit("git ls-files --others --ignored --exclude-standard");
            if ($ignoredResult['code'] === 0 && !empty(trim($ignoredResult['output']))) {
                $allUntracked = array_merge($allUntracked, array_filter(explode("\n", trim($ignoredResult['output']))));
            }

            // Créer un backup de tous les fichiers non suivis
            $untrackedBackupPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git-untracked-backup-' . uniqid();
            $untrackedBackupCreated = false;
            if (!empty($allUntracked)) {
                mkdir($untrackedBackupPath, 0755, true);
                foreach ($allUntracked as $file) {
                    $file = trim($file);
                    if (empty($file)) continue;
                    // Exclure git-manager/ car il sera géré séparément
                    if (strpos($file, 'git-manager/') === 0 || strpos($file, 'git-manager\\') === 0) continue;

                    $srcFile = $repoPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
                    $dstFile = $untrackedBackupPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);

                    if (file_exists($srcFile)) {
                        $dstDir = dirname($dstFile);
                        if (!is_dir($dstDir)) {
                            mkdir($dstDir, 0755, true);
                        }
                        copy($srcFile, $dstFile);
                        $untrackedBackupCreated = true;
                    }
                }
            }

            // Créer une branche orpheline
            $result = execGit("git checkout --orphan {$branchEscaped}");
            if ($result['code'] !== 0) {
                // Nettoyer le backup en cas d'erreur
                if ($untrackedBackupCreated && is_dir($untrackedBackupPath)) {
                    recurseDelete($untrackedBackupPath);
                }
                echo json_encode(['success' => false, 'error' => $result['output']]);
                break;
            }

            // Ajouter tous les fichiers (sauf ceux dans .gitignore)
            execGit("git add -A");

            // Créer le commit initial avec tous les fichiers
            $commitResult = execGit("git commit -m \"Fresh start - nouveau départ sans historique\"");
            if ($commitResult['code'] !== 0) {
                // Nettoyer le backup en cas d'erreur
                if ($untrackedBackupCreated && is_dir($untrackedBackupPath)) {
                    recurseDelete($untrackedBackupPath);
                }
                echo json_encode(['success' => false, 'error' => "Branche créée mais erreur lors du commit: " . $commitResult['output']]);
                break;
            }

            // Revenir automatiquement à la branche d'origine
            $returnMessage = "";
            if (!empty($originalBranch) && $originalBranch !== 'HEAD') {
                // Sauvegarder git-manager/ avant le checkout
                $gitManagerPath = $repoPath . DIRECTORY_SEPARATOR . 'git-manager';
                $backupPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git-manager-backup-' . uniqid();
                $backupCreated = false;

                if (is_dir($gitManagerPath)) {
                    recurseCopy($gitManagerPath, $backupPath);
                    $backupCreated = true;
                }

                // Checkout avec force
                $checkoutBack = execGit("git checkout -f " . escapeArg($originalBranch) . " 2>&1");

                // TOUJOURS restaurer git-manager/ s'il a disparu ou est incomplet
                if ($backupCreated) {
                    $gitApiFile = $gitManagerPath . DIRECTORY_SEPARATOR . 'git-api.php';
                    if (!is_dir($gitManagerPath) || !file_exists($gitApiFile)) {
                        recurseCopy($backupPath, $gitManagerPath);
                    }
                    if (is_dir($backupPath)) {
                        recurseDelete($backupPath);
                    }
                }

                // Restaurer les fichiers non suivis
                if ($untrackedBackupCreated && is_dir($untrackedBackupPath)) {
                    recurseCopy($untrackedBackupPath, $repoPath);
                    recurseDelete($untrackedBackupPath);
                }

                // Nettoyer les dossiers vides
                cleanEmptyDirs($repoPath);

                if ($checkoutBack['code'] === 0) {
                    $returnMessage = "\n✓ Retour sur '{$originalBranch}'";
                } else {
                    $returnMessage = "\n⚠️ Impossible de revenir sur '{$originalBranch}'";
                }
            } else {
                // Pas de retour, nettoyer le backup des fichiers non suivis
                if ($untrackedBackupCreated && is_dir($untrackedBackupPath)) {
                    recurseDelete($untrackedBackupPath);
                }
            }

            echo json_encode([
                'success' => true,
                'output' => "Branche '{$branch}' créée avec tous les fichiers (sans historique)" . $returnMessage
            ]);
            break;
        }

        // Branche normale
        if ($checkout) {
            if (!empty($sourceEscaped)) {
                $result = execGit("git checkout -b {$branchEscaped} {$sourceEscaped}");
                $message = "Branche '{$branch}' créée depuis '{$sourceBranch}' et activée";
            } else {
                $result = execGit("git checkout -b {$branchEscaped}");
                $message = "Branche '{$branch}' créée et activée";
            }
        } else {
            if (!empty($sourceEscaped)) {
                $result = execGit("git branch {$branchEscaped} {$sourceEscaped}");
                $message = "Branche '{$branch}' créée depuis '{$sourceBranch}'";
            } else {
                $result = execGit("git branch {$branchEscaped}");
                $message = "Branche '{$branch}' créée";
            }
        }

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $message]);
        }
        break;

    case 'renameBranch':
        // Renommer une branche
        $oldName = $input['oldName'] ?? '';
        $newName = $input['newName'] ?? '';

        if (empty($oldName) || empty($newName)) {
            echo json_encode(['success' => false, 'error' => 'Ancien et nouveau nom requis']);
            break;
        }

        // Valider les noms de branche
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $oldName) || !preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $newName)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']);
            break;
        }

        $oldNameEscaped = escapeArg($oldName);
        $newNameEscaped = escapeArg($newName);

        // Vérifier si c'est la branche courante
        $currentBranch = trim(execGit('git branch --show-current')['output']);

        // Vérifier si la branche a un upstream (est publiée) AVANT de la renommer
        $upstreamResult = execGit("git config --get branch.{$oldNameEscaped}.remote");
        $hasUpstream = !empty(trim($upstreamResult['output']));

        if ($currentBranch === $oldName) {
            // Renommer la branche courante
            $result = execGit("git branch -m {$newNameEscaped}");
        } else {
            // Renommer une autre branche
            $result = execGit("git branch -m {$oldNameEscaped} {$newNameEscaped}");
        }

        if ($result['code'] !== 0) {
            echo json_encode([
                'success' => false,
                'error' => $result['output']
            ]);
            break;
        }

        // Si la branche était publiée, mettre à jour sur origin
        if ($hasUpstream) {
            // Pousser la nouvelle branche sur origin
            $pushResult = execGit("git push -u origin {$newNameEscaped}");
            if ($pushResult['code'] !== 0) {
                echo json_encode([
                    'success' => true,
                    'output' => "Branche '{$oldName}' renommée en '{$newName}' localement.\n⚠️ Erreur lors de la publication: " . $pushResult['output']
                ]);
                break;
            }

            // Supprimer l'ancienne branche sur origin
            $deleteResult = execGit("git push origin --delete {$oldNameEscaped}");
            if ($deleteResult['code'] !== 0) {
                echo json_encode([
                    'success' => true,
                    'output' => "Branche '{$oldName}' renommée en '{$newName}' et publiée.\n⚠️ L'ancienne branche '{$oldName}' n'a pas pu être supprimée sur origin: " . $deleteResult['output']
                ]);
                break;
            }

            echo json_encode([
                'success' => true,
                'output' => "Branche '{$oldName}' renommée en '{$newName}' (local et distant)"
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'output' => "Branche '{$oldName}' renommée en '{$newName}'"
            ]);
        }
        break;

    case 'deleteBranch':
        // Supprimer une branche
        $branch = $input['branch'] ?? '';
        $force = $input['force'] ?? false;

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']);
            exit;
        }

        // Valider le nom de branche
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']);
            exit;
        }

        // Empêcher la suppression de main/master
        $protectedBranches = ['main', 'master'];
        if (in_array($branch, $protectedBranches)) {
            echo json_encode(['success' => false, 'error' => "Impossible de supprimer la branche '{$branch}' (protégée)"]);
            exit;
        }

        $branchEscaped = escapeArg($branch);
        $flag = $force ? '-D' : '-d';
        $result = execGit("git branch {$flag} {$branchEscaped}");

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => "Branche '{$branch}' supprimée"]);
        }
        break;

    case 'mergeBranch':
        // Fusionner une branche dans la branche courante
        $branch = $input['branch'] ?? '';

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']);
            exit;
        }

        // Valider le nom de branche
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']);
            exit;
        }

        $branchEscaped = escapeArg($branch);
        $result = execGit("git merge {$branchEscaped}");

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output'] ?: "Branche '{$branch}' fusionnée"]);
        }
        break;

    case 'pushBranch':
        // Publier une branche locale sur le dépôt distant
        $branch = $input['branch'] ?? '';

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']);
            exit;
        }

        // Valider le nom de branche
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']);
            exit;
        }

        // Vérifier si la branche a au moins un commit
        $hasCommits = execGit('git rev-parse HEAD');
        if ($hasCommits['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Aucun commit sur la branche — faites un premier commit avant de publier']);
            break;
        }

        $branchEscaped = escapeArg($branch);
        $result = execGit("git push -u origin {$branchEscaped}");

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => "Branche '{$branch}' publiée sur origin"]);
        }
        break;

    case 'deleteRemoteBranch':
        // Supprimer une branche sur le dépôt distant
        $branch = $input['branch'] ?? '';

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']);
            exit;
        }

        // Valider le nom de branche
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']);
            exit;
        }

        // Empêcher la suppression de main/master
        $protectedBranches = ['main', 'master'];
        if (in_array($branch, $protectedBranches)) {
            echo json_encode(['success' => false, 'error' => "Impossible de supprimer la branche '{$branch}' (protégée)"]);
            exit;
        }

        $branchEscaped = escapeArg($branch);
        $result = execGit("git push origin --delete {$branchEscaped}");

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => "Branche '{$branch}' supprimée du dépôt distant"]);
        }
        break;

    case 'stashList':
        $result = execGit('git stash list --format="%gd|%gs|%ci"');
        $stashes = [];

        if ($result['code'] === 0 && !empty(trim($result['output']))) {
            foreach (explode("\n", $result['output']) as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $parts = explode('|', $line, 3);
                if (count($parts) >= 2) {
                    $stashes[] = [
                        'id' => $parts[0],
                        'message' => $parts[1],
                        'date' => $parts[2] ?? ''
                    ];
                }
            }
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'stashes' => $stashes,
                'count' => count($stashes)
            ]
        ]);
        break;

    case 'stashSave':
        $message = $input['message'] ?? '';
        $includeUntracked = $input['includeUntracked'] ?? true;

        $cmd = 'git stash push';
        if ($includeUntracked) {
            $cmd .= ' --include-untracked';
        }
        if (!empty($message)) {
            $messageEscaped = escapeArg($message);
            $cmd .= ' -m ' . $messageEscaped;
        }

        $result = execGit($cmd);

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            $noChanges = (strpos($result['output'], 'No local changes') !== false);
            if ($noChanges) {
                echo json_encode(['success' => false, 'error' => 'Aucune modification à mettre de côté']);
            } else {
                echo json_encode(['success' => true, 'output' => $result['output'] ?: 'Modifications mises de côté']);
            }
        }
        break;

    case 'stashApply':
        $stashId = $input['stashId'] ?? 'stash@{0}';
        $drop = $input['drop'] ?? true;

        if (!preg_match('/^stash@\{[0-9]+\}$/', $stashId)) {
            echo json_encode(['success' => false, 'error' => 'Identifiant de stash invalide']);
            break;
        }

        $stashIdEscaped = escapeArg($stashId);

        if ($drop) {
            $result = execGit("git stash pop {$stashIdEscaped}");
        } else {
            $result = execGit("git stash apply {$stashIdEscaped}");
        }

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            $actionLabel = $drop ? 'restaurées et supprimées du stash' : 'restaurées (stash conservé)';
            echo json_encode(['success' => true, 'output' => $result['output'] ?: "Modifications {$actionLabel}"]);
        }
        break;

    case 'stashDrop':
        $stashId = $input['stashId'] ?? '';

        if (empty($stashId) || !preg_match('/^stash@\{[0-9]+\}$/', $stashId)) {
            echo json_encode(['success' => false, 'error' => 'Identifiant de stash invalide']);
            break;
        }

        $stashIdEscaped = escapeArg($stashId);
        $result = execGit("git stash drop {$stashIdEscaped}");

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output'] ?: 'Stash supprimé']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}