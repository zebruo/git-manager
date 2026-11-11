<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Synchronisation : push, pull, fetch

switch ($action) {

    case 'push':
        $currentBranch = trim(execGit('git branch --show-current')['output']);

        if (empty($currentBranch)) {
            echo json_encode(['success' => false, 'error' => 'HEAD détaché - impossible de push']); break;
        }

        $hasCommits = execGit('git rev-parse HEAD');
        if ($hasCommits['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => 'Aucun commit sur la branche — faites un premier commit avant de push']); break;
        }

        $upstream = trim(execGit("git config --get branch.{$currentBranch}.remote")['output']);

        if (empty($upstream)) {
            $result = execGit("git push -u origin {$currentBranch}");
            $message = "Branche '{$currentBranch}' publiée et push effectué";
        } else {
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

    case 'syncUpstream':
        // Fork : récupérer les nouveautés du dépôt original (remote "upstream") dans la branche courante
        $currentBranch = getCurrentBranch();
        if ($currentBranch === '' || $currentBranch === 'HEAD') {
            echo json_encode(['success' => false, 'error' => 'HEAD détaché — placez-vous sur une branche avant de mettre à jour']); break;
        }
        if (getRemoteUrl('upstream') === '') {
            echo json_encode(['success' => false, 'error' => 'Aucun dépôt original (upstream) configuré']); break;
        }

        $fetchResult = execGit('git fetch upstream --prune');
        if ($fetchResult['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => "Impossible de joindre le dépôt original :\n" . $fetchResult['output']]); break;
        }

        $target = 'upstream/' . $currentBranch;
        if (execGit('git rev-parse --verify --quiet ' . escapeArg('refs/remotes/' . $target))['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => "La branche '{$currentBranch}' n'existe pas sur le dépôt original"]); break;
        }

        // --no-edit / GIT_EDITOR : jamais d'éditeur interactif pour le message de fusion
        putenv('GIT_EDITOR=true');
        $rebase = !empty($input['rebase']);
        $result = execGit(($rebase ? 'git rebase ' : 'git merge --no-edit ') . escapeArg($target));

        $output = trim($fetchResult['output'] . "\n" . $result['output']);
        if ($result['code'] !== 0) {
            // Conflit : l'interface affiche les fichiers en conflit (merge) ou le bandeau Continuer/Abandonner (rebase)
            echo json_encode(['success' => false, 'error' => $output, 'conflict' => true]); break;
        }
        echo json_encode(['success' => true, 'output' => $output ?: "Branche '{$currentBranch}' à jour avec le dépôt original"]);
        break;

    case 'fetch':
        $result = execGit('git fetch origin --prune');

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]); break;
        }
        $response = ['success' => true, 'output' => $result['output'] ?: 'Fetch effectué'];

        // Fork : récupérer aussi l'état du dépôt original, pour calculer le retard (statut et bouton « Depuis l'original »)
        if (getRemoteUrl('upstream') !== '') {
            $upstreamResult = execGit('git fetch upstream --prune');
            if ($upstreamResult['code'] !== 0) {
                $response['warning'] = "Fetch effectué, mais le dépôt original (upstream) n'a pas pu être joint";
                $response['fetchError'] = $upstreamResult['output'];
            } elseif ($upstreamResult['output'] !== '') {
                $response['output'] .= "\n" . $upstreamResult['output'];
            }
        }
        echo json_encode($response);
        break;

    case 'rebaseContinue':
        $rebaseMergeDir  = $repoPath . '/.git/rebase-merge';
        $rebaseApplyDir  = $repoPath . '/.git/rebase-apply';

        // Répertoire corrompu (head-name manquant) → nettoyer, restaurer la branche si HEAD détaché
        $corrupted = (is_dir($rebaseMergeDir) && !file_exists($rebaseMergeDir . '/head-name'))
                  || (is_dir($rebaseApplyDir) && !file_exists($rebaseApplyDir . '/head-name'));
        if ($corrupted) {
            if (is_dir($rebaseMergeDir)) removeDirRecursive($rebaseMergeDir);
            if (is_dir($rebaseApplyDir)) removeDirRecursive($rebaseApplyDir);
            $currentBranch = trim(execGit('git branch --show-current')['output']);
            if (empty($currentBranch)) execGit('git checkout -');
            echo json_encode(['success' => true]);
            break;
        }

        // Lire la branche cible depuis head-name avant de continuer
        $headNameFile = file_exists($rebaseMergeDir . '/head-name')
            ? $rebaseMergeDir . '/head-name'
            : $rebaseApplyDir . '/head-name';
        $targetBranch = '';
        if (file_exists($headNameFile)) {
            $targetBranch = basename(trim(file_get_contents($headNameFile)));
        }

        putenv('GIT_EDITOR=true');
        putenv('GIT_SEQUENCE_EDITOR=true');
        $result = execGit('git rebase --continue');

        // Sur Windows, git peut échouer à supprimer rebase-merge → nettoyage manuel
        if (is_dir($rebaseMergeDir)) removeDirRecursive($rebaseMergeDir);

        $rebased = strpos($result['output'], 'Successfully rebased') !== false;
        if ($result['code'] === 0 || $rebased) {
            // Si HEAD détaché après un rebase réussi (Windows), forcer le retour sur la branche cible
            $currentBranch = trim(execGit('git branch --show-current')['output']);
            if (empty($currentBranch) && !empty($targetBranch)) {
                execGit("git checkout {$targetBranch}");
            }
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        }
        break;

    case 'rebaseAbort':
        $result = execGit('git rebase --abort');
        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
