<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Branches : branches, switchBranch, createBranch, renameBranch,
//            deleteBranch, mergeBranch, pushBranch, deleteRemoteBranch

function isValidBranchName(string $name): bool {
    return preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $name) === 1;
}

switch ($action) {

    case 'branches':
        $currentBranch = trim(execGit('git branch --show-current')['output']);
        $isDetached    = empty($currentBranch);
        $detachedAt    = $isDetached ? trim(execGit('git rev-parse --short HEAD')['output']) : '';
        $isRebasing    = (is_dir($repoPath . '/.git/rebase-merge') && file_exists($repoPath . '/.git/rebase-merge/head-name'))
                      || (is_dir($repoPath . '/.git/rebase-apply') && file_exists($repoPath . '/.git/rebase-apply/head-name'));

        $localResult = execGit('git branch --format="%(refname:short)|%(upstream:short)|%(upstream:track)|%(objectname)"');
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
                'upstream' => $upstream, 'ahead' => $ahead, 'behind' => $behind,
                'tip' => $parts[3] ?? ''   // retiré plus bas : sert seulement à repérer une branche identique à la branche par défaut
            ];
        }

        if (empty($localBranches) && !empty($currentBranch)) {
            $localBranches[] = ['name' => $currentBranch, 'current' => true, 'upstream' => '', 'ahead' => 0, 'behind' => 0, 'tip' => ''];
        }

        $remoteResult  = execGit('git branch -r --format="%(refname:short)"');
        $remoteBranches = array_map('trim', filterGitWarnings(explode("\n", $remoteResult['output'])));
        $remoteBranches = array_values(array_filter($remoteBranches, function($b) {
            if (empty($b)) return false;
            if (strpos($b, '/HEAD') !== false) return false;
            if (strpos($b, '/') === false) return false; // exclut les noms de remote purs (ex. "origin")
            return true;
        }));

        // Section "Branches distantes" : seulement celles sans copie locale du même nom
        // (origin/main est déjà visible comme branche locale "suivie" ; upstream/main se met à jour via le bouton Original)
        $localNames = array_column($localBranches, 'name');
        $remoteOnly = array_values(array_filter($remoteBranches, function($b) use ($localNames) {
            return !in_array(substr($b, strpos($b, '/') + 1), $localNames, true);
        }));

        // Branche par défaut du dépôt GitHub (jamais supprimable) : origin/HEAD si connu, sinon main/master
        $defaultRemote = trim(execGit('git symbolic-ref --quiet --short refs/remotes/origin/HEAD')['output']);
        if (!str_starts_with($defaultRemote, 'origin/')) {
            $defaultRemote = in_array('origin/main', $remoteBranches, true) ? 'origin/main' : 'origin/master';
        }

        // Indicateur « fusionnée » : branche de référence = branche par défaut en local (celle d'origin/HEAD si elle existe
        // en local, sinon main, sinon master). Une branche est « fusionnée » si tous ses commits sont déjà dans la
        // référence (git branch --merged) ; « identique » si elle pointe exactement sur le même commit (branche neuve,
        // sans commit propre : techniquement fusionnée, mais pas utile de le dire de la même façon).
        // Sans branche de référence ou sans commit, aucun indicateur. Une fusion « squash » ou « rebase » n'est pas
        // reconnue par Git comme une fusion : la branche n'a alors pas d'indicateur.
        $baseBranch = '';
        foreach ([preg_replace('#^origin/#', '', $defaultRemote), 'main', 'master'] as $candidate) {
            if ($candidate !== '' && in_array($candidate, $localNames, true)) { $baseBranch = $candidate; break; }
        }
        $mergedNames = [];
        if ($baseBranch !== '') {
            $mergedResult = execGit('git branch --merged ' . escapeArg($baseBranch) . ' --format="%(refname:short)"');
            if ($mergedResult['code'] === 0) {
                $mergedNames = array_map('trim', explode("\n", $mergedResult['output']));
            }
        }
        $baseTip = '';
        foreach ($localBranches as $b) {
            if ($b['name'] === $baseBranch) $baseTip = $b['tip'];
        }
        // unmerged : nombre de commits de la branche absents de la référence (git rev-list --count référence..branche),
        // pour les branches ni fusionnées ni identiques ; null sinon, et au-delà de 30 branches (un appel git par branche)
        $counted = 0;
        foreach ($localBranches as &$b) {
            $isOther = $baseBranch !== '' && $b['name'] !== $baseBranch;
            $b['sameAsBase'] = $isOther && $baseTip !== '' && $b['tip'] === $baseTip;
            $b['merged']     = $isOther && !$b['sameAsBase'] && in_array($b['name'], $mergedNames, true);
            $b['unmerged']   = null;
            if ($isOther && $baseTip !== '' && !$b['sameAsBase'] && !$b['merged'] && $counted < 30) {
                $counted++;
                $b['unmerged'] = (int) trim(execGit('git rev-list --count ' . escapeArg($baseBranch . '..' . $b['name']))['output']);
            }
            unset($b['tip']);
        }
        unset($b);

        echo json_encode([
            'success' => true,
            'data' => [
                'current'     => $currentBranch,
                'baseBranch'  => $baseBranch,       // référence de l'indicateur « fusionnée » ('' si aucune)
                'local'       => $localBranches,
                'remote'      => $remoteBranches,   // toutes (liste « Créer depuis… »)
                'remoteOnly'  => $remoteOnly,       // sans copie locale (section « Branches distantes »)
                'defaultRemote' => $defaultRemote,
                'isDetached'  => $isDetached,
                'detachedAt'  => $detachedAt,
                'isRebasing'  => $isRebasing
            ]
        ]);
        break;

    case 'switchBranch':
        $branch = $input['branch'] ?? '';
        $force  = $input['force'] ?? false;

        if (empty($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche requis']); break;
        }
        if (!isValidBranchName($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']); break;
        }

        // git checkout ne touche ni aux fichiers non suivis ni aux fichiers ignorés, et conserve
        // les modifications locales quand elles ne sont pas en conflit avec la branche cible :
        // aucune copie de sauvegarde n'est nécessaire (elle copiait aussi les fichiers ignorés,
        // node_modules / dist compris, au point de dépasser le temps maximum de PHP).
        //
        // Forcer (-f) écrase TOUTES les modifications locales des fichiers suivis, pas seulement
        // les fichiers en conflit : on le refuse tant qu'il en existe (commit ou remisage d'abord).
        if ($force) {
            $dirty = execGit("git status --porcelain --untracked-files=no");
            if (trim($dirty['output']) !== '') {
                echo json_encode([
                    'success' => false, 'localChanges' => true,
                    'error' => "Basculement forcé refusé : des fichiers suivis ont des modifications non enregistrées, "
                             . "que -f effacerait. Faites un commit ou un remisage (stash) puis réessayez.\n\n"
                             . rtrim($dirty['output']),
                ]);
                break;
            }
        }
        $forceFlag = $force ? '-f ' : '';

        if (preg_match('/^(origin|upstream)\/(.+)$/', $branch, $matches)) {
            $localBranchName    = $matches[2];
            $localBranchEscaped = escapeArg($localBranchName);
            $remoteBranchEscaped = escapeArg($branch);
            $checkResult = execGit("git branch --list {$localBranchEscaped}");
            $localExists = !empty(trim($checkResult['output']));

            if ($localExists) {
                $result = execGit("git checkout {$forceFlag}{$localBranchEscaped}");
                $message = "Basculé sur la branche '{$localBranchName}'";
            } elseif ($matches[1] === 'upstream') {
                // Branche du dépôt original : copie locale non suivie, à publier ensuite sur le fork (pas de droits sur l'original)
                $result = execGit("git checkout {$forceFlag}-b {$localBranchEscaped} --no-track {$remoteBranchEscaped}");
                $message = "Branche locale '{$localBranchName}' créée depuis le dépôt original — utilisez Publier pour l'envoyer sur votre fork";
            } else {
                $result = execGit("git checkout {$forceFlag}-b {$localBranchEscaped} --track {$remoteBranchEscaped}");
                $message = "Branche locale '{$localBranchName}' créée depuis '{$branch}'";
            }
        } else {
            $branchEscaped = escapeArg($branch);
            $result = execGit("git checkout {$forceFlag}{$branchEscaped}");
            $message = "Basculé sur la branche '{$branch}'";
        }

        if ($result['code'] !== 0) {
            // Fichiers non suivis qui seraient écrasés : le forçage peut être proposé
            $needsForce = strpos($result['output'], 'untracked working tree files would be overwritten') !== false
                       || strpos($result['output'], 'Please move or remove them') !== false;
            // Modifications locales en conflit avec la branche cible : jamais de forçage automatique
            $localChanges = !$needsForce && isFileOverwriteError($result['output']);
            $error = $localChanges
                ? "Vos modifications locales seraient écrasées par la branche '{$branch}' : faites un commit "
                  . "ou un remisage (stash) avant de basculer. Rien n'a été modifié.\n\n" . $result['output']
                : $result['output'];
            echo json_encode(['success' => false, 'error' => $error, 'needsForce' => $needsForce, 'localChanges' => $localChanges]);
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
        if (!isValidBranchName($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide. Utilisez uniquement lettres, chiffres, tirets, underscores, points et slashes.']); break;
        }
        if (!empty($sourceBranch) && !isValidBranchName($sourceBranch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche source invalide']); break;
        }

        $branchEscaped = escapeArg($branch);
        $sourceEscaped = !empty($sourceBranch) ? escapeArg($sourceBranch) : '';

        // Branche sans historique (vide, ou avec l'état actuel des fichiers) : créée directement
        // par les commandes de bas niveau de git (write-tree / commit-tree), avec un index
        // temporaire. Le dossier de travail, l'index et la branche courante ne sont jamais
        // touchés : pas de checkout, pas de copie des fichiers ignorés, pas de `checkout -f`
        // (qui effaçait les modifications non enregistrées au retour sur la branche d'origine).
        if ($orphan || $freshStart) {
            if (trim(execGit("git branch --list {$branchEscaped}")['output']) !== '') {
                echo json_encode(['success' => false, 'error' => "La branche '{$branch}' existe déjà"]); break;
            }
            $tmpIndex = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'git-manager-index-' . uniqid();
            putenv('GIT_INDEX_FILE=' . $tmpIndex);
            // Index vide → arbre vide ; « nouveau départ » : tous les fichiers non ignorés, tels
            // qu'ils sont sur le disque (modifications non enregistrées comprises)
            $addResult = $freshStart ? execGit("git add -A") : ['code' => 0, 'output' => ''];
            $treeResult = $addResult['code'] === 0 ? execGit("git write-tree") : $addResult;
            putenv('GIT_INDEX_FILE');
            if (file_exists($tmpIndex)) @unlink($tmpIndex);
            if ($treeResult['code'] !== 0) {
                echo json_encode(['success' => false, 'error' => $treeResult['output']]); break;
            }

            $commitMsg = $freshStart ? 'Fresh start - nouveau départ sans historique' : 'Initial commit (branche vide)';
            $commitResult = execGit("git commit-tree " . escapeArg(trim($treeResult['output'])) . " -m " . escapeArg($commitMsg));
            if ($commitResult['code'] !== 0) {
                echo json_encode(['success' => false, 'error' => "Erreur lors du commit initial : " . $commitResult['output']]); break;
            }
            $result = execGit("git branch {$branchEscaped} " . escapeArg(trim($commitResult['output'])));
            if ($result['code'] !== 0) {
                echo json_encode(['success' => false, 'error' => $result['output']]); break;
            }

            $what = $freshStart ? 'avec tous les fichiers (sans historique)' : '(vide)';
            $current = getCurrentBranch();
            echo json_encode(['success' => true, 'output' => "Branche '{$branch}' créée {$what}"
                . (!empty($current) && $current !== 'HEAD' ? "\n✓ Vous restez sur '{$current}', vos fichiers n'ont pas été modifiés" : '')]);
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
        if (!isValidBranchName($oldName) || !isValidBranchName($newName)) {
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
        if (!isValidBranchName($branch)) {
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
        if (!isValidBranchName($branch)) {
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
        if (!isValidBranchName($branch)) {
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
        if (!isValidBranchName($branch)) {
            echo json_encode(['success' => false, 'error' => 'Nom de branche invalide']); break;
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
