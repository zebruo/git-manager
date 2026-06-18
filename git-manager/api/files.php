<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Fichiers : checkout, discardAll, checkoutCommit, removeFromRepo, untrackFile

switch ($action) {

    case 'checkout':
        $file   = $input['file'] ?? '';
        $source = $input['source'] ?? 'HEAD';

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']); break;
        }

        $allowedSources = ['HEAD', 'origin/main', 'origin/master'];
        $isValidSource = in_array($source, $allowedSources)
            || preg_match('/^HEAD~[0-9]+$/', $source)
            || preg_match('/^[a-f0-9]{7,40}$/', $source);

        if (!$isValidSource) $source = 'HEAD';

        $result = execGit('git checkout ' . escapeArg($source) . ' -- ' . escapeArg($file));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $result['output'] ?: 'Fichier récupéré']);
        }
        break;

    case 'discardAll':
        $result = execGit('git checkout -- .');

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => 'Modifications annulées']);
        }
        break;

    case 'checkoutCommit':
        $hash = $input['hash'] ?? '';

        if (empty($hash) || !preg_match('/^[a-f0-9]{7,40}$/', $hash)) {
            echo json_encode(['success' => false, 'error' => 'Hash de commit invalide']); break;
        }

        $backupPath = backupGitManager($repoPath);
        $result = execGit("git checkout " . escapeArg($hash) . " 2>&1");
        restoreGitManager($repoPath, $backupPath);

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => "HEAD détaché sur le commit {$hash}"]);
        }
        break;

    case 'removeFromRepo':
        $file  = $input['file'] ?? '';
        $isDir = $input['isDir'] ?? false;

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']); break;
        }

        $flag = $isDir ? '-r ' : '';
        $result = execGit("git rm {$flag}" . escapeArg($file));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            $label = $isDir ? "Dossier '{$file}'" : "Fichier '{$file}'";
            echo json_encode(['success' => true, 'output' => $result['output'] ?: "{$label} supprimé du dépôt et du disque"]);
        }
        break;

    case 'untrackFile':
        $file  = $input['file'] ?? '';
        $isDir = $input['isDir'] ?? false;

        if (empty($file) || !isValidFile($file)) {
            echo json_encode(['success' => false, 'error' => 'Fichier invalide']); break;
        }

        $flag = $isDir ? '-r ' : '';
        $result = execGit("git rm --cached {$flag}" . escapeArg($file));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            $label = $isDir ? "Dossier '{$file}'" : "Fichier '{$file}'";
            echo json_encode(['success' => true, 'output' => $result['output'] ?: "{$label} retiré du suivi Git (conservé sur le disque)"]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
