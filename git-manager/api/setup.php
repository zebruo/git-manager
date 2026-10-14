<?php
/** @var string      $action */
/** @var array       $input */
/** @var string      $repoPath */
/** @var string      $safeRepoPath */
/** @var bool        $isGitRepoCheck */
/** @var string      $gitUserName */
/** @var string      $gitUserEmail */
/** @var array       $reposRoots */
/** @var array       $reposRootsRaw */
/** @var bool        $hasReposRoots */
// Actions pré-dépôt : checkRepo, clone, initRepo, listFolders, listRemoteBranches, resetRemote

switch ($action) {

    case 'checkRepo':
        // Si reposRoot est configuré et qu'aucun dépôt n'est sélectionné, forcer "pas de repo"
        $isRepo = ($hasReposRoots && empty($repoParam)) ? false : $isGitRepoCheck;
        echo json_encode([
            'success' => true,
            'data' => [
                'isGitRepo' => $isRepo,
                'path' => $repoPath
            ]
        ]);
        break;

    case 'clone':
        set_time_limit(600);
        ini_set('max_execution_time', 600);

        if (!$hasReposRoots) {
            echo json_encode(['success' => false, 'error' => 'reposRoot non configuré dans git-config.php']); break;
        }

        $targetRoot = getTargetRoot($input, $reposRoots);
        if (!$targetRoot) { echo json_encode(['success' => false, 'error' => 'Emplacement invalide']); break; }
        [$rootIndex, $rootPath] = $targetRoot;

        $url = $input['url'] ?? '';
        $targetDir = $input['targetDir'] ?? '';

        if (empty($url)) { echo json_encode(['success' => false, 'error' => 'URL du dépôt requise']); break; }
        if (!preg_match('/^(https?:\/\/|git@)/', $url)) {
            echo json_encode(['success' => false, 'error' => 'URL invalide. Utilisez une URL HTTPS ou SSH.']); break;
        }

        if (empty($targetDir)) {
            // Nom déduit de l'URL : nettoyé silencieusement, l'utilisateur ne l'a pas saisi
            $cleanUrl = rtrim($url, '/');
            $cleanUrl = preg_replace('/\.git$/i', '', $cleanUrl);
            if (preg_match('/\/([^\/]+)$/', $cleanUrl, $matches)) {
                $targetDir = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $matches[1]);
            } else {
                $targetDir = 'cloned-repo';
            }
        } elseif (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $targetDir)) {
            // Nom saisi : refusé plutôt que modifié, pour ne pas cloner sous un autre nom que celui tapé
            echo json_encode(['success' => false, 'error' => 'Nom de dossier invalide : lettres, chiffres, points, tirets (-) et underscores (_) uniquement']); break;
        }

        if (trim($targetDir, '.') === '') { echo json_encode(['success' => false, 'error' => 'Nom de dossier invalide']); break; }

        $fullTargetPath = $rootPath . DIRECTORY_SEPARATOR . $targetDir;
        $safeTargetPath = str_replace('\\', '/', $fullTargetPath);

        if (file_exists($fullTargetPath)) {
            echo json_encode(['success' => false, 'error' => "Le dossier '{$targetDir}' existe déjà"]); break;
        }

        putenv("GIT_DIR");
        putenv("GIT_WORK_TREE");

        $output = [];
        $returnCode = 0;
        exec("git clone " . escapeshellarg($url) . " " . escapeshellarg($fullTargetPath) . " 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            echo json_encode(['success' => false, 'error' => implode("\n", $output)]); break;
        }

        exec("git config --global --add safe.directory \"{$safeTargetPath}\" 2>&1");
        if (!empty($gitUserName)) exec("git -C \"{$safeTargetPath}\" config user.name \"{$gitUserName}\" 2>&1");
        if (!empty($gitUserEmail)) exec("git -C \"{$safeTargetPath}\" config user.email \"{$gitUserEmail}\" 2>&1");

        echo json_encode([
            'success' => true,
            'output'  => "Dépôt cloné avec succès dans '{$targetDir}'",
            'data'    => [
                'path'       => $fullTargetPath,
                'folderName' => $targetDir,
                'id'         => makeRepoId($rootIndex, $targetDir),
            ]
        ]);
        break;

    case 'listFolders':
        if (!$hasReposRoots) {
            echo json_encode(['success' => false, 'error' => 'reposRoot non configuré dans git-config.php']); break;
        }

        $roots = [];
        foreach ($reposRoots as $rootIndex => $root) {
            if (!$root) {
                $roots[] = ['index' => $rootIndex, 'path' => $reposRootsRaw[$rootIndex], 'error' => 'Dossier introuvable', 'folders' => []];
                continue;
            }

            $folders = [];
            foreach (scandir($root) as $item) {
                if ($item === '.' || $item === '..') continue;
                $itemPath = $root . DIRECTORY_SEPARATOR . $item;
                if (is_dir($itemPath)) {
                    // Un lien symbolique dont la cible réelle sort de la racine ne pourra pas être
                    // ouvert par git-api.php (confinement via realpath) : ne pas l'annoncer comme dépôt Git.
                    $resolvedItem = realpath($itemPath);
                    $isConfined = $resolvedItem && $resolvedItem !== $root && isInsideRoot($resolvedItem, $root);
                    $folders[] = [
                        'name'      => $item,
                        'isGitRepo' => $isConfined && is_dir($itemPath . DIRECTORY_SEPARATOR . '.git')
                    ];
                }
            }
            $roots[] = ['index' => $rootIndex, 'path' => $root, 'folders' => $folders];
        }

        echo json_encode(['success' => true, 'data' => ['roots' => $roots]]);
        break;

    case 'initRepo':
        if (!$hasReposRoots) {
            echo json_encode(['success' => false, 'error' => 'reposRoot non configuré — cette action nécessite le mode multi-dépôts']); break;
        }

        $targetRoot = getTargetRoot($input, $reposRoots);
        if (!$targetRoot) { echo json_encode(['success' => false, 'error' => 'Emplacement invalide']); break; }
        [$rootIndex, $rootPath] = $targetRoot;

        // Refusé plutôt que nettoyé : un nom modifié en silence ne correspondrait plus au dossier
        // existant visé, et un dossier vide serait créé à côté au lieu d'adopter le bon.
        $newName = $input['name'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $newName)) {
            echo json_encode(['success' => false, 'error' => 'Nom de dépôt invalide : lettres, chiffres, points, tirets (-) et underscores (_) uniquement']); break;
        }
        // "." ou ".." désigneraient la racine elle-même ou son parent, qui seraient alors "adoptés"
        if (trim($newName, '.') === '') { echo json_encode(['success' => false, 'error' => 'Nom de dépôt invalide']); break; }

        $newPath = $rootPath . DIRECTORY_SEPARATOR . $newName;
        $alreadyExists = file_exists($newPath);

        if ($alreadyExists && is_dir($newPath . DIRECTORY_SEPARATOR . '.git')) {
            echo json_encode(['success' => false, 'error' => "'{$newName}' est déjà un dépôt Git"]); break;
        }
        if ($alreadyExists && !is_dir($newPath)) {
            echo json_encode(['success' => false, 'error' => "'{$newName}' existe déjà mais n'est pas un dossier"]); break;
        }

        // Dossier déjà existant (projet sans .git) : on l'adopte tel quel, on ne crée rien.
        if (!$alreadyExists && !@mkdir($newPath, 0755, true)) {
            echo json_encode(['success' => false, 'error' => "Impossible de créer le dossier '{$newName}'"]); break;
        }

        putenv("GIT_DIR");
        putenv("GIT_WORK_TREE");
        $safeNewPath = str_replace('\\', '/', $newPath);
        $initOutput = [];
        $initCode = 0;
        exec("git -C \"{$safeNewPath}\" init -b main 2>&1", $initOutput, $initCode);

        if ($initCode !== 0) {
            echo json_encode(['success' => false, 'error' => implode("\n", $initOutput)]); break;
        }

        exec("git config --global --add safe.directory \"{$safeNewPath}\" 2>&1");
        if (!empty($gitUserName))  exec("git -C \"{$safeNewPath}\" config user.name \"{$gitUserName}\" 2>&1");
        if (!empty($gitUserEmail)) exec("git -C \"{$safeNewPath}\" config user.email \"{$gitUserEmail}\" 2>&1");

        echo json_encode(['success' => true, 'data' => ['name' => $newName, 'path' => $newPath, 'id' => makeRepoId($rootIndex, $newName)]]);
        break;

    case 'listRemoteBranches':
        if (!$isGitRepoCheck) {
            echo json_encode(['success' => false, 'error' => 'Ce dossier n\'est pas un dépôt Git']); break;
        }

        $fetchOutput = [];
        $fetchCode = 0;
        exec("git -C \"{$safeRepoPath}\" fetch --prune 2>&1", $fetchOutput, $fetchCode);
        $fetchError = ($fetchCode !== 0) ? implode("\n", $fetchOutput) : null;

        $output = [];
        exec("git -C \"{$safeRepoPath}\" branch -r --format=\"%(refname:short)\" 2>&1", $output, $returnCode);

        $branches = [];
        foreach ($output as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '/HEAD') !== false) continue;
            if (preg_match('/^origin\/(.+)$/', $line, $matches)) {
                $branches[] = $matches[1];
            }
        }

        $response = ['success' => true, 'data' => ['branches' => $branches]];
        if ($fetchError) $response['fetchError'] = $fetchError;
        echo json_encode($response);
        break;

    case 'resetRemote':
        if (!$isGitRepoCheck) {
            echo json_encode(['success' => false, 'error' => 'Ce dossier n\'est pas un dépôt Git']); break;
        }

        $branchName = $input['branchName'] ?? 'main';
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $branchName)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']); break;
        }

        $remoteUrl = trim(shell_exec("git -C \"{$safeRepoPath}\" config --get remote.origin.url 2>&1") ?? '');
        if (empty($remoteUrl) || strpos($remoteUrl, 'fatal:') !== false) {
            echo json_encode(['success' => false, 'error' => 'Aucun remote origin configuré']); break;
        }

        $branchEscaped = escapeshellarg($branchName);
        $output = [];
        $allOutput = [];

        $allOutput[] = "→ Création de la branche orpheline '{$branchName}'...";
        exec("git -C \"{$safeRepoPath}\" checkout --orphan {$branchEscaped} 2>&1", $output, $returnCode);
        $allOutput[] = implode("\n", $output);

        if ($returnCode !== 0) {
            echo json_encode(['success' => false, 'error' => "Erreur lors de la création de la branche orpheline:\n" . implode("\n", $output), 'output' => implode("\n", $allOutput)]);
            break;
        }

        $output = [];
        $allOutput[] = "\n→ Suppression des fichiers de l'index Git...";
        exec("git -C \"{$safeRepoPath}\" rm -rf --cached . 2>&1", $output, $returnCode);
        $allOutput[] = implode("\n", $output);

        $output = [];
        $allOutput[] = "\n→ Création du commit initial vide...";
        exec("git -C \"{$safeRepoPath}\" commit --allow-empty -m \"Initial commit (reset repository)\" 2>&1", $output, $returnCode);
        $allOutput[] = implode("\n", $output);

        if ($returnCode !== 0) {
            echo json_encode(['success' => false, 'error' => "Erreur lors du commit:\n" . implode("\n", $output), 'output' => implode("\n", $allOutput)]);
            break;
        }

        $output = [];
        $allOutput[] = "\n→ Force push vers origin/{$branchName}...";
        exec("git -C \"{$safeRepoPath}\" push -f origin {$branchEscaped} 2>&1", $output, $returnCode);
        $allOutput[] = implode("\n", $output);

        if ($returnCode !== 0) {
            echo json_encode(['success' => false, 'error' => "Erreur lors du push:\n" . implode("\n", $output), 'output' => implode("\n", $allOutput)]);
            break;
        }

        $deleteOtherBranches = $input['deleteOtherBranches'] ?? false;
        $branchesToDelete = $input['branchesToDelete'] ?? [];

        if ($deleteOtherBranches && !empty($branchesToDelete)) {
            $allOutput[] = "\n→ Suppression des autres branches distantes...";
            foreach ($branchesToDelete as $branch) {
                if ($branch === $branchName) continue;
                $branchToDeleteEscaped = escapeshellarg($branch);
                $output = [];
                exec("git -C \"{$safeRepoPath}\" push origin --delete {$branchToDeleteEscaped} 2>&1", $output, $returnCode);
                $allOutput[] = ($returnCode === 0)
                    ? "  ✓ Branche '{$branch}' supprimée"
                    : "  ✗ Échec suppression '{$branch}': " . implode(" ", $output);
            }
        }

        $allOutput[] = "\n✓ Dépôt distant réinitialisé avec succès!";
        $allOutput[] = "La branche '{$branchName}' sur origin ne contient plus qu'un commit vide.";
        $allOutput[] = "\nATTENTION: Vos fichiers locaux sont toujours présents mais non suivis par Git.";
        $allOutput[] = "Vous pouvez maintenant les ajouter avec 'git add' pour créer un nouveau commit.";

        echo json_encode(['success' => true, 'output' => implode("\n", $allOutput)]);
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
