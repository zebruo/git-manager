<?php
/** @var string      $action */
/** @var array       $input */
/** @var string      $repoPath */
/** @var string      $safeRepoPath */
/** @var bool        $isGitRepoCheck */
/** @var string      $gitUserName */
/** @var string      $gitUserEmail */
/** @var string|null $reposRoot */
// Actions pré-dépôt : checkRepo, clone, initRepo, listFolders, listRemoteBranches, resetRemote

switch ($action) {

    case 'checkRepo':
        // Si reposRoot est configuré et qu'aucun dépôt n'est sélectionné, forcer "pas de repo"
        $isRepo = (!empty($reposRoot) && empty($repoParam)) ? false : $isGitRepoCheck;
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

        if (empty($reposRoot)) {
            echo json_encode(['success' => false, 'error' => 'reposRoot non configuré dans git-config.php']); break;
        }

        $url = $input['url'] ?? '';
        $targetDir = $input['targetDir'] ?? '';

        if (empty($url)) { echo json_encode(['success' => false, 'error' => 'URL du dépôt requise']); break; }
        if (!preg_match('/^(https?:\/\/|git@)/', $url)) {
            echo json_encode(['success' => false, 'error' => 'URL invalide. Utilisez une URL HTTPS ou SSH.']); break;
        }

        if (empty($targetDir)) {
            $cleanUrl = rtrim($url, '/');
            $cleanUrl = preg_replace('/\.git$/i', '', $cleanUrl);
            if (preg_match('/\/([^\/]+)$/', $cleanUrl, $matches)) {
                $targetDir = $matches[1];
            } else {
                $targetDir = 'cloned-repo';
            }
        }

        $targetDir = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $targetDir);
        if (empty($targetDir)) { echo json_encode(['success' => false, 'error' => 'Nom de dossier invalide']); break; }

        $fullTargetPath = $reposRoot . DIRECTORY_SEPARATOR . $targetDir;
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
            ]
        ]);
        break;

    case 'listFolders':
        if (empty($reposRoot)) {
            echo json_encode(['success' => false, 'error' => 'reposRoot non configuré dans git-config.php']); break;
        }

        $folders = [];
        if (is_dir($reposRoot)) {
            $items = scandir($reposRoot);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') continue;
                $itemPath = $reposRoot . DIRECTORY_SEPARATOR . $item;
                if (is_dir($itemPath)) {
                    $folders[] = [
                        'name'      => $item,
                        'isGitRepo' => is_dir($itemPath . DIRECTORY_SEPARATOR . '.git')
                    ];
                }
            }
        }

        echo json_encode(['success' => true, 'data' => [
            'parentDir' => $reposRoot,
            'folders'   => $folders,
        ]]);
        break;

    case 'initRepo':
        if (empty($reposRoot)) {
            echo json_encode(['success' => false, 'error' => 'reposRoot non configuré — cette action nécessite le mode multi-dépôts']); break;
        }

        $newName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $input['name'] ?? '');
        if (empty($newName)) { echo json_encode(['success' => false, 'error' => 'Nom de dépôt invalide']); break; }

        $newPath = $reposRoot . DIRECTORY_SEPARATOR . $newName;
        if (file_exists($newPath)) { echo json_encode(['success' => false, 'error' => "Le dossier '{$newName}' existe déjà"]); break; }

        if (!@mkdir($newPath, 0755, true)) {
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

        echo json_encode(['success' => true, 'data' => ['name' => $newName, 'path' => $newPath]]);
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
