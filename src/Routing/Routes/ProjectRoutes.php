<?php
declare(strict_types=1);

use ControlBot\Project\ProjectBackfill;
use ControlBot\Project\ProjectModel;
use ControlBot\Project\ProjectUi;

require_once __DIR__ . '/../../ProjectModel.php';
require_once __DIR__ . '/../../ProjectBackfill.php';
require_once __DIR__ . '/../../ProjectUi.php';

/** Read-only owner module. No database writes or external API. */
$definitions = [
    ['project_id'=>'project-controlbot','slug'=>'controlbot','title'=>'ControlBot','repository'=>'pl0n3r/ControlBot','phase'=>'building','priority'=>'medium'],
    ['project_id'=>'project-condor','slug'=>'condor','title'=>'Condor','repository'=>'pl0n3r/Condor','phase'=>'building','priority'=>'medium'],
    ['project_id'=>'project-brvtal','slug'=>'brvtal','title'=>'BRVTAL','repository'=>'pl0n3r/brvtal','phase'=>'building','priority'=>'medium'],
    ['project_id'=>'project-grindflow','slug'=>'grindflow','title'=>'GrindFlow','repository'=>'pl0n3r/GrindFlow','phase'=>'building','priority'=>'medium'],
];
$load = static function (callable $backend, int $now) use ($definitions): array {
    $snapshot = $backend('projects.read', [], [], $now);
    if (!is_array($snapshot) || array_keys($snapshot) !== ['version','projects']
        || $snapshot['version'] !== 1 || !is_array($snapshot['projects'])
        || !array_is_list($snapshot['projects']) || count($snapshot['projects']) > 100) {
        throw new RuntimeException('Trusted project snapshot unavailable.');
    }
    try {
        $projects = ProjectBackfill::apply($snapshot['projects'], $definitions, $now);
        if (ProjectBackfill::apply($projects, $definitions, $now) !== $projects) {
            throw new RuntimeException('Project preview is not idempotent.');
        }
        return $projects;
    } catch (InvalidArgumentException $error) {
        throw new RuntimeException('Invalid trusted project snapshot.', 0, $error);
    }
};
$list = static fn(array $projects): array => [
    'version'=>1, 'mode'=>'read_only', 'persisted'=>false,
    'path'=>'/proyectos', 'projects'=>$projects,
    'html'=>ProjectUi::renderList($projects),
];
return [
    ['method'=>'GET', 'path'=>'/api/v1/proyectos/backfill',
        'handler'=>static function (callable $backend, array $vars, array $request, int $now) use ($load, $list): array {
            $result = $list($load($backend, $now));
            $result['mode'] = 'preview_read_only';
            $result['preview_only'] = true;
            $result['path'] = '/proyectos/backfill';
            return $result;
        },
    ],
    ['method'=>'GET', 'path'=>'/api/v1/proyectos',
        'handler'=>static fn(callable $backend, array $vars, array $request, int $now): array => $list($load($backend, $now)),
    ],
    ['method'=>'GET', 'path'=>'/api/v1/proyectos/{project_id}',
        'handler'=>static function (callable $backend, array $vars, array $request, int $now) use ($load): array {
            foreach ($load($backend, $now) as $project) {
                if ($project['project_id'] !== $vars['project_id']) continue;
                return [
                    'version'=>1, 'mode'=>'read_only', 'persisted'=>false,
                    'found'=>true, 'path'=>'/proyectos/' . $project['project_id'],
                    'project'=>ProjectModel::normalize($project),
                    'html'=>ProjectUi::renderDetail($project),
                ];
            }
            // Router responses have a fixed 200 handler status; no fake record.
            return ['version'=>1, 'found'=>false, 'project_id'=>$vars['project_id']];
        },
    ],
];
