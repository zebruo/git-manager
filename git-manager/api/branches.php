<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Branches : branches, switchBranch, createBranch, renameBranch,
//            deleteBranch, mergeBranch, pushBranch, deleteRemoteBranch

switch ($action) {

    case 'branches':
        $currentBranch = trim(execGit('git branch --show-current')['output']);
        $isDetached    = empty($currentBranch);
        $detachedAt    = $isDetached ? trim(execGit('git rev-parse --short HEAD')['output']) : '';

        $localResult = execGit('git branch --format="%(refname:short)|%(upstream:short)|%(upstream:track)"');
        $localBranches = [];

        foreach (explode("\n", $localResult['output']) as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '(HEAD') === 0) continue;
            $parts  = explode('|', $line);
            $name   = $parts[0];
            $upstream = $parts[1] ?? '';
            $track  = $parts[2] ?? '';
            $ahead  = 0; $behind = 0;
            if (preg_match('/ahead (\d+)/', $track, $m))  $ahead  = (int)$m[1];
            if (preg_match('/behind (\d+)/', $track, $m)) $behind = (int)$m[1];
            $localBranches[] = [
                'name' => $name, 'current' => ($name === $currentBranch),
                'upstream' => $upstream, 'ahead' => $ahead, 'behind' => $behind
            ];
        }

        if (empty($localBranches) && !empty($currentBranch)) {
            $localBranches[] = ['name' => $currentBranch, 'current' => true, 'upstream' => '', 'ahead' => 0, 'behind' => 0];
        }

        $remoteResult  = execGit('git branch -r --format="%(refname:short)"');
        $remoteBranches = filterGitWarnings(explode("\n", $remoteResult['output']));
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
                'current'    => $currentBranch,
                'local'      => $localBranches,
                'remote'     => array_values($remoteBranches),
                'isDetached' => $isDetached,
                'detachedAt' => $detachedAt
            ]
        ]);
        break;

    case 'switchBranch':
        $branch = $input['branch'] ?? '';
        $force  = $input['force'] ?? false;

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']); break;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']); break;
        }

        $forceFlag = $force ? '-f ' : '';
        $backupPath = backupGitManager($repoPath);

        // Sauvegarder fichiers non suivis
        $allUntrackedFiles = [];
        $untrackedTempBackup = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git-untracked-switch-' . uniqid();
        $untrackedTempCreated = false;

        $untrackedResult = execGit("git ls-files --others --exclude-standard");
        if ($untrackedResult['code'] === 0 && !empty(trim($untrackedResult['output']))) {
            $allUntrackedFiles = array_merge($allUntrackedFiles, array_filter(explode("\n", trim($untrackedResult['output']))));
        }
        $ignoredResult = execGit("git ls-files --others --ignored --exclude-standard");
        if ($ignoredResult['code'] === 0 && !empty(trim($ignoredResult['output']))) {
            $allUntrackedFiles = array_merge($allUntrackedFiles, array_filter(explode("\n", trim($ignoredResult['output']))));
        }

        if (!empty($allUntrackedFiles)) {
            mkdir($untrackedTempBackup, 0755, true);
            foreach ($allUntrackedFiles as $file) {
                $file = trim($file);
                if (empty($file)) continue;
                if (strpos($file, 'git-manager/') === 0 || strpos($file, 'git-manager\\') === 0) continue;
                $srcFile = $repoPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
                $dstFile = $untrackedTempBackup . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
                if (file_exists($srcFile) && is_file($srcFile)) {
                    $dstDir = dirname($dstFile);
                    if (!is_dir($dstDir)) mkdir($dstDir, 0755, true);
                    copy($srcFile, $dstFile);
                    $untrackedTempCreated = true;
                }
            }
        }

        if (preg_match('/^origin\/(.+)$/', $branch, $matches)) {
            $localBranchName    = $matches[1];
            $localBranchEscaped = escapeArg($localBranchName);
            $remoteBranchEscaped = escapeArg($branch);
            $checkResult = execGit("git branch --list {$localBranchEscaped}");
            $localExists = !empty(trim($checkResult['output']));

            if ($localExists) {
                $result = execGit("git checkout {$forceFlag}{$localBranchEscaped}");
                if ($result['code'] !== 0 && isFileOverwriteError($result['output'])) {
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
            if ($result['code'] !== 0 && isFileOverwriteError($result['output'])) {
                $result = execGit("git checkout -f {$branchEscaped}");
            }
            $message = "Basculé sur la branche '{$branch}'";
        }

        $gitManagerPath = $repoPath . DIRECTORY_SEPARATOR . 'git-manager';
        $gitApiFile = $gitManagerPath . DIRECTORY_SEPARATOR . 'git-api.php';
        $gitManagerRestored = $backupPath !== null && (!is_dir($gitManagerPath) || !file_exists($gitApiFile));
        restoreGitManager($repoPath, $backupPath);
        if ($gitManagerRestored) $message .= " (git-manager restauré)";

        if ($result['code'] === 0) {
            $trackedFiles = execGit("git ls-files");
            $trackedList = array_filter(explode("\n", trim($trackedFiles['output'])));
            $isEmptyBranch = true;
            foreach ($trackedList as $file) {
                if (strpos($file, 'git-manager/') !== 0) { $isEmptyBranch = false; break; }
            }
            if ($isEmptyBranch && !empty($trackedList)) {
                execGit("git clean -fd -e git-manager/ -e .git/");
            }
            if ($untrackedTempCreated && is_dir($untrackedTempBackup)) {
                recurseCopy($untrackedTempBackup, $repoPath);
                recurseDelete($untrackedTempBackup);
            }
            cleanEmptyDirs($repoPath);
        } else {
            if ($untrackedTempCreated && is_dir($untrackedTempBackup)) {
                recurseDelete($untrackedTempBackup);
            }
        }

        if ($result['code'] !== 0) {
            $needsForce = strpos($result['output'], 'untracked working tree files would be overwritten') !== false
                       || strpos($result['output'], 'Please move or remove them') !== false;
            echo json_encode(['success' => false, 'error' => $result['output'], 'needsForce' => $needsForce]);
        } else {
            echo json_encode(['success' => true, 'output' => $message]);
        }
        break;

    case 'createBranch':
        $branch      = $input['branch'] ?? '';
        $checkout    = $input['checkout'] ?? true;
        $sourceBranch = $input['sourceBranch'] ?? '';
        $orphan      = $input['orphan'] ?? false;
        $freshStart  = $input['freshStart'] ?? false;

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']); break;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide. Utilisez uniquement lettres, chiffres, tirets, underscores et points.']); break;
        }
        if (!empty($sourceBranch) && !preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $sourceBranch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche source invalide']); break;
        }

        $branchEscaped = escapeArg($branch);
        $sourceEscaped = !empty($sourceBranch) ? escapeArg($sourceBranch) : '';

        if ($orphan) {
            $originalBranch = getCurrentBranch();
            $result = execGit("git checkout --orphan {$branchEscaped}");
            if ($result['code'] !== 0) { echo json_encode(['success' => false, 'error' => $result['output']]); break; }

            execGit("git rm -rf --cached . 2>&1");
            execGit("git add -f git-manager/ 2>&1");

            $statusResult = execGit("git status --porcelain");
            $hasStaged = !empty(trim($statusResult['output']));

            if ($hasStaged) {
                $commitResult = execGit("git commit -m \"Initial commit (branche vide avec git-manager)\"");
            } else {
                $commitResult = execGit("git commit --allow-empty -m \"Initial commit (branche vide)\"");
            }

            if ($commitResult['code'] !== 0) {
                echo json_encode(['success' => false, 'error' => "Branche créée mais erreur lors du commit initial: " . $commitResult['output']]); break;
            }

            $returnMessage = "";
            if (!empty($originalBranch) && $originalBranch !== 'HEAD') {
                $backupPath = backupGitManager($repoPath);
                $checkoutBack = execGit("git checkout -f " . escapeArg($originalBranch) . " 2>&1");
                restoreGitManager($repoPath, $backupPath);
                cleanEmptyDirs($repoPath);
                $returnMessage = $checkoutBack['code'] === 0
                    ? "\n✓ Retour sur '{$originalBranch}'"
                    : "\n⚠️ Impossible de revenir sur '{$originalBranch}'";
            }

            $msg = $hasStaged
                ? "Branche '{$branch}' créée (vide avec git-manager/)" . $returnMessage
                : "Branche '{$branch}' créée (vide) - git-manager/ non trouvé" . $returnMessage;
            echo json_encode(['success' => true, 'output' => $msg]);
            break;
        }

        if ($freshStart) {
            $originalBranch = getCurrentBranch();

            $allUntracked = [];
            $untrackedResult = execGit("git ls-files --others --exclude-standard");
            if ($untrackedResult['code'] === 0 && !empty(trim($untrackedResult['output']))) {
                $allUntracked = array_merge($allUntracked, array_filter(explode("\n", trim($untrackedResult['output']))));
            }
            $ignoredResult = execGit("git ls-files --others --ignored --exclude-standard");
            if ($ignoredResult['code'] === 0 && !empty(trim($ignoredResult['output']))) {
                $allUntracked = array_merge($allUntracked, array_filter(explode("\n", trim($ignoredResult['output']))));
            }

            $untrackedBackupPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git-untracked-backup-' . uniqid();
            $untrackedBackupCreated = false;
            if (!empty($allUntracked)) {
                mkdir($untrackedBackupPath, 0755, true);
                foreach ($allUntracked as $file) {
                    $file = trim($file);
                    if (empty($file)) continue;
                    if (strpos($file, 'git-manager/') === 0 || strpos($file, 'git-manager\\') === 0) continue;
                    $srcFile = $repoPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
                    $dstFile = $untrackedBackupPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
                    if (file_exists($srcFile)) {
                        $dstDir = dirname($dstFile);
                        if (!is_dir($dstDir)) mkdir($dstDir, 0755, true);
                        copy($srcFile, $dstFile);
                        $untrackedBackupCreated = true;
                    }
                }
            }

            $result = execGit("git checkout --orphan {$branchEscaped}");
            if ($result['code'] !== 0) {
                if ($untrackedBackupCreated && is_dir($untrackedBackupPath)) recurseDelete($untrackedBackupPath);
                echo json_encode(['success' => false, 'error' => $result['output']]); break;
            }

            execGit("git add -A");
            $commitResult = execGit("git commit -m \"Fresh start - nouveau départ sans historique\"");
            if ($commitResult['code'] !== 0) {
                if ($untrackedBackupCreated && is_dir($untrackedBackupPath)) recurseDelete($untrackedBackupPath);
                echo json_encode(['success' => false, 'error' => "Branche créée mais erreur lors du commit: " . $commitResult['output']]); break;
            }

            $returnMessage = "";
            if (!empty($originalBranch) && $originalBranch !== 'HEAD') {
                $backupPath = backupGitManager($repoPath);
                $checkoutBack = execGit("git checkout -f " . escapeArg($originalBranch) . " 2>&1");
                restoreGitManager($repoPath, $backupPath);
                if ($untrackedBackupCreated && is_dir($untrackedBackupPath)) {
                    recurseCopy($untrackedBackupPath, $repoPath);
                    recurseDelete($untrackedBackupPath);
                }
                cleanEmptyDirs($repoPath);
                $returnMessage = $checkoutBack['code'] === 0
                    ? "\n✓ Retour sur '{$originalBranch}'"
                    : "\n⚠️ Impossible de revenir sur '{$originalBranch}'";
            } else {
                if ($untrackedBackupCreated && is_dir($untrackedBackupPath)) recurseDelete($untrackedBackupPath);
            }

            echo json_encode(['success' => true, 'output' => "Branche '{$branch}' créée avec tous les fichiers (sans historique)" . $returnMessage]);
            break;
        }

        // Branche normale
        if ($checkout) {
            $result = !empty($sourceEscaped)
                ? execGit("git checkout -b {$branchEscaped} {$sourceEscaped}")
                : execGit("git checkout -b {$branchEscaped}");
            $message = !empty($sourceBranch)
                ? "Branche '{$branch}' créée depuis '{$sourceBranch}' et activée"
                : "Branche '{$branch}' créée et activée";
        } else {
            $result = !empty($sourceEscaped)
                ? execGit("git branch {$branchEscaped} {$sourceEscaped}")
                : execGit("git branch {$branchEscaped}");
            $message = !empty($sourceBranch)
                ? "Branche '{$branch}' créée depuis '{$sourceBranch}'"
                : "Branche '{$branch}' créée";
        }

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $message]);
        }
        break;

    case 'renameBranch':
        $oldName = $input['oldName'] ?? '';
        $newName = $input['newName'] ?? '';

        if (empty($oldName) || empty($newName)) {
            echo json_encode(['success' => false, 'error' => 'Ancien et nouveau nom requis']); break;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $oldName) || !preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $newName)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']); break;
        }

        $oldNameEscaped = escapeArg($oldName);
        $newNameEscaped = escapeArg($newName);
        $currentBranch  = trim(execGit('git branch --show-current')['output']);

        $upstreamResult = execGit("git config --get branch.{$oldNameEscaped}.remote");
        $hasUpstream = !empty(trim($upstreamResult['output']));

        $result = ($currentBranch === $oldName)
            ? execGit("git branch -m {$newNameEscaped}")
            : execGit("git branch -m {$oldNameEscaped} {$newNameEscaped}");

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]); break;
        }

        if ($hasUpstream) {
            $pushResult = execGit("git push -u origin {$newNameEscaped}");
            if ($pushResult['code'] !== 0) {
                echo json_encode(['success' => true, 'output' => "Branche '{$oldName}' renommée en '{$newName}' localement.\n⚠️ Erreur lors de la publication: " . $pushResult['output']]);
                break;
            }
            $deleteResult = execGit("git push origin --delete {$oldNameEscaped}");
            if ($deleteResult['code'] !== 0) {
                echo json_encode(['success' => true, 'output' => "Branche '{$oldName}' renommée en '{$newName}' et publiée.\n⚠️ L'ancienne branche '{$oldName}' n'a pas pu être supprimée sur origin: " . $deleteResult['output']]);
                break;
            }
            echo json_encode(['success' => true, 'output' => "Branche '{$oldName}' renommée en '{$newName}' (local et distant)"]);
        } else {
            echo json_encode(['success' => true, 'output' => "Branche '{$oldName}' renommée en '{$newName}'"]);
        }
        break;

    case 'deleteBranch':
        $branch = $input['branch'] ?? '';
        $force  = $input['force'] ?? false;

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']); break;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']); break;
        }
        if (in_array($branch, ['main', 'master'])) {
            echo json_encode(['success' => false, 'error' => "Impossible de supprimer la branche '{$branch}' (protégée)"]); break;
        }

        $flag = $force ? '-D' : '-d';
        $result = execGit("git branch {$flag} " . escapeArg($branch));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => "Branche '{$branch}' supprimée"]);
        }
        break;

    case 'mergeBranch':
        $branch = $input['branch'] ?? '';

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']); break;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']); break;
        }

        $result = execGit("git merge " . escapeArg($branch));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output'] ?: "Branche '{$branch}' fusionnée"]);
        }
        break;

    case 'pushBranch':
        $branch = $input['branch'] ?? '';

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']); break;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']); break;
        }

        $hasCommits = execGit('git rev-parse HEAD');
        if ($hasCommits['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Aucun commit sur la branche — faites un premier commit avant de publier']); break;
        }

        $result = execGit("git push -u origin " . escapeArg($branch));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => "Branche '{$branch}' publiée sur origin"]);
        }
        break;

    case 'deleteRemoteBranch':
        $branch = $input['branch'] ?? '';

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']); break;
        }
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']); break;
        }
        if (in_array($branch, ['main', 'master'])) {
            echo json_encode(['success' => false, 'error' => "Impossible de supprimer la branche '{$branch}' (protégée)"]); break;
        }

        $result = execGit("git push origin --delete " . escapeArg($branch));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => "Branche '{$branch}' supprimée du dépôt distant"]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
