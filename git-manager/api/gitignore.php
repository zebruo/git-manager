<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Gestion .gitignore : getGitignore, addToGitignore, removeFromGitignore

switch ($action) {

    case 'getGitignore':
        $gitignorePath = $repoPath . '/.gitignore';
        $patterns = [];

        if (file_exists($gitignorePath)) {
            foreach (explode("\n", file_get_contents($gitignorePath)) as $line) {
                $line = trim($line);
                if (!empty($line) && $line[0] !== '#') {
                    $patterns[] = $line;
                }
            }
        }

        $untracked = filterGitWarnings(explode("\n", execGit('git ls-files --others --exclude-standard')['output']));

        echo json_encode([
            'success' => true,
            'data' => ['patterns' => $patterns, 'untracked' => array_values($untracked)]
        ]);
        break;

    case 'addToGitignore':
        $pattern = trim($input['pattern'] ?? '');

        if (empty($pattern)) {
            echo json_encode(['success' => false, 'error' => 'Pattern requis']); break;
        }
        if (strpos($pattern, '..') !== false) {
            echo json_encode(['success' => false, 'error' => 'Pattern invalide']); break;
        }

        $gitignorePath = $repoPath . '/.gitignore';
        $content = '';

        if (file_exists($gitignorePath)) {
            $content = file_get_contents($gitignorePath);
            foreach (explode("\n", $content) as $line) {
                if (trim($line) === $pattern) {
                    echo json_encode(['success' => false, 'error' => 'Pattern déjà présent']); break 2;
                }
            }
            if (!empty($content) && substr($content, -1) !== "\n") $content .= "\n";
        }

        $content .= $pattern . "\n";

        if (file_put_contents($gitignorePath, $content) !== false) {
            echo json_encode(['success' => true, 'output' => 'Pattern ajouté']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur d\'écriture']);
        }
        break;

    case 'removeFromGitignore':
        $pattern = $input['pattern'] ?? '';

        if (empty($pattern)) {
            echo json_encode(['success' => false, 'error' => 'Pattern requis']); break;
        }

        $gitignorePath = $repoPath . '/.gitignore';

        if (!file_exists($gitignorePath)) {
            echo json_encode(['success' => false, 'error' => 'Fichier .gitignore inexistant']); break;
        }

        $lines = explode("\n", file_get_contents($gitignorePath));
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
            echo json_encode(['success' => false, 'error' => 'Pattern non trouvé']); break;
        }

        while (!empty($newLines) && trim(end($newLines)) === '') {
            array_pop($newLines);
        }

        $newContent = implode("\n", $newLines);
        if (!empty($newContent)) $newContent .= "\n";

        if (file_put_contents($gitignorePath, $newContent) !== false) {
            echo json_encode(['success' => true, 'output' => 'Pattern retiré']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erreur d\'écriture']);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
