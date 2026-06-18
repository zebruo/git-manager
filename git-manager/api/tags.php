<?php
/** @var string $action */
/** @var array  $input */
/** @var string $repoPath */
/** @var string $safeRepoPath */
// Tags : listTags, createTag, deleteTag, pushTag, deleteRemoteTag

function validateTagName(string $name): bool {
    return preg_match('/^[a-zA-Z0-9._\-\/]+$/', $name) === 1;
}

switch ($action) {

    case 'listTags':
        // --sort=-version:refname trie par version décroissante (v1.1.0 avant v1.0.0)
        $result = execGit('git tag --list --sort=-version:refname');
        $tags = [];

        if ($result['code'] === 0 && !empty(trim($result['output']))) {
            foreach (explode("\n", $result['output']) as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                // git cat-file -t refs/tags/NAME retourne 'tag' (annoté) ou 'commit' (léger)
                $typeResult = execGit('git cat-file -t refs/tags/' . escapeArg($line));
                $type = trim($typeResult['output']) === 'tag' ? 'tag annoté' : 'tag léger';
                $tags[] = ['name' => $line, 'type' => $type];
            }
        }

        echo json_encode(['success' => true, 'data' => ['tags' => $tags, 'count' => count($tags)]]);
        break;

    case 'createTag':
        $name    = $input['name'] ?? '';
        $message = $input['message'] ?? '';

        if (empty($name) || !validateTagName($name)) {
            echo json_encode(['success' => false, 'error' => 'Nom de tag invalide']); break;
        }

        $cmd = empty($message)
            ? 'git tag ' . escapeArg($name)
            : 'git tag -a ' . escapeArg($name) . ' -m ' . escapeArg($message);

        $result = execGit($cmd);

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => "Tag {$name} créé"]);
        }
        break;

    case 'deleteTag':
        $name = $input['name'] ?? '';

        if (empty($name) || !validateTagName($name)) {
            echo json_encode(['success' => false, 'error' => 'Nom de tag invalide']); break;
        }

        $result = execGit('git tag -d ' . escapeArg($name));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => "Tag {$name} supprimé localement"]);
        }
        break;

    case 'pushTag':
        $name = $input['name'] ?? '';

        if (empty($name) || !validateTagName($name)) {
            echo json_encode(['success' => false, 'error' => 'Nom de tag invalide']); break;
        }

        $result = execGit('git push origin ' . escapeArg($name));

        if ($result['code'] !== 0) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            $alreadyPushed = stripos($result['output'], 'Everything up-to-date') !== false;
            echo json_encode(['success' => true, 'output' => $result['output'], 'alreadyPushed' => $alreadyPushed]);
        }
        break;

    case 'deleteRemoteTag':
        $name = $input['name'] ?? '';

        if (empty($name) || !validateTagName($name)) {
            echo json_encode(['success' => false, 'error' => 'Nom de tag invalide']); break;
        }

        $result = execGit('git push origin --delete ' . escapeArg($name));

        // "remote ref does not exist" = tag jamais pushé, pas une erreur
        $notFound = stripos($result['output'], 'remote ref does not exist') !== false
                 || stripos($result['output'], 'not found') !== false;

        if ($result['code'] !== 0 && !$notFound) {
            echo json_encode(['success' => false, 'error' => $result['output']]);
        } else {
            echo json_encode(['success' => true, 'output' => $notFound ? '' : "Tag {$name} supprimé sur GitHub"]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'error' => 'Action non reconnue']);
        break;
}
