<?php
declare(strict_types=1);
namespace ControlBot\Requirements;

use InvalidArgumentException;

final class RequirementIntakeDecisionUi
{
    private const SENSITIVE = '/(?:-----BEGIN [^-]*PRIVATE KEY-----[\s\S]*?-----END [^-]*PRIVATE KEY-----|\bbearer\s+[A-Za-z0-9._~+\/-]{8,}|\b(?:password|passwd|token|secret|api[_ -]?key|private[_ -]?key|dsn|otp|recovery[_ -]?code|session[_ -]?token)\s*[:=]\s*[^\s,;]+|\b(?:ghp_|gho_|github_pat_)[A-Za-z0-9_]{20,}|\b(?:sk|rk|pk)-[A-Za-z0-9_-]{12,})/i';

    public static function project(array $raw): array
    {
        $p = self::proposal($raw);
        $unknown = [];
        foreach (['problem', 'user', 'objectives', 'out_of_scope', 'dependencies'] as $field) {
            if ($p[$field]['status'] === 'unknown') {
                $unknown[] = $field;
            }
        }

        $project = [
            'artifact' => 'project',
            'operation' => match ($p['project_match']['status']) {
                'matched' => 'link_existing',
                'ambiguous' => 'review_matches',
                default => 'propose_create',
            },
            'project_ref' => $p['project_match']['project_ref'],
            'match' => $p['project_match'],
            'execution' => false,
        ];
        $epic = [
            'artifact' => 'epic',
            'operation' => 'propose_create',
            'proposal_ref' => $p['proposal_ref'],
            'problem' => $p['problem'],
            'user' => $p['user'],
            'objectives' => $p['objectives'],
            'out_of_scope' => $p['out_of_scope'],
            'execution' => false,
        ];
        $issues = array_map(static fn(array $slice): array => [
            'artifact' => 'issue',
            'operation' => 'propose_create',
            'slice_key' => $slice['slice_key'],
            'title' => $slice['title'],
            'acceptance' => $slice['acceptance'],
            'execution' => false,
        ], $p['slices']);

        $options = [
            ['code' => 'approve', 'intent' => 'allow_later_materialization', 'execution' => false],
            ['code' => 'revise', 'intent' => 'request_changes', 'execution' => false],
            ['code' => 'reject', 'intent' => 'decline_proposal', 'execution' => false],
        ];
        $sections = ['summary', 'impacts', 'materialization_diff', 'decision'];
        $surfaces = [
            'desktop' => ['sections' => $sections, 'controls' => ['approve', 'revise', 'reject'], 'keyboard' => true, 'gesture_only' => false],
            'mobile' => ['sections' => $sections, 'controls' => ['approve', 'revise', 'reject'], 'keyboard' => true, 'gesture_only' => false],
        ];

        $base = [
            'proposal_ref' => $p['proposal_ref'],
            'proposal_fingerprint' => $p['fingerprint'],
            'provenance' => ['draft_ref' => $p['draft_ref']],
            'summary' => [
                'problem' => $p['problem'],
                'user' => $p['user'],
                'objectives' => $p['objectives'],
                'out_of_scope' => $p['out_of_scope'],
                'unknown_fields' => $unknown,
            ],
            'impacts' => [
                'risks' => $p['risks'],
                'dependencies' => $p['dependencies'],
                'questions' => $p['questions'],
                'project_match' => $p['project_match'],
            ],
            'materialization_diff' => ['project' => $project, 'epic' => $epic, 'issues' => $issues],
            'decision_options' => $options,
            'surfaces' => $surfaces,
            'execution' => false,
        ];
        $fingerprint = self::digest($base);
        $out = ['version' => 1, 'view_ref' => 'requirement-decision-view:'.substr($fingerprint, 0, 40)] + $base + ['fingerprint' => $fingerprint];
        self::secretFree($out);
        return $out;
    }

    private static function proposal(array $r): array
    {
        self::fields($r, [
            'version', 'proposal_ref', 'draft_ref', 'problem', 'user', 'objectives', 'out_of_scope',
            'risks', 'dependencies', 'questions', 'slices', 'project_match', 'requires_approval', 'execution', 'fingerprint',
        ], 'EpicProposal');
        if (($r['version'] ?? null) !== 1 || $r['requires_approval'] !== true || $r['execution'] !== false) {
            self::bad('EpicProposal state');
        }
        $n = [
            'version' => 1,
            'proposal_ref' => self::typed($r['proposal_ref'], 'epic-proposal', 'proposal_ref'),
            'draft_ref' => self::typed($r['draft_ref'], 'requirement-draft', 'draft_ref'),
            'problem' => self::fact($r['problem'], 'problem', false),
            'user' => self::fact($r['user'], 'user', false),
            'objectives' => self::fact($r['objectives'], 'objectives', true),
            'out_of_scope' => self::fact($r['out_of_scope'], 'out_of_scope', true),
            'risks' => self::textList($r['risks'], 'risks'),
            'dependencies' => self::fact($r['dependencies'], 'dependencies', true),
            'questions' => self::textList($r['questions'], 'questions'),
            'slices' => self::slices($r['slices']),
            'project_match' => self::projectMatch($r['project_match']),
            'requires_approval' => true,
            'execution' => false,
            'fingerprint' => self::hex($r['fingerprint'], 'fingerprint'),
        ];
        $expected = self::digest([
            'draft_ref' => $n['draft_ref'],
            'project_match' => $n['project_match'],
            'risks' => $n['risks'],
            'questions' => $n['questions'],
            'slice' => $n['slices'][0],
        ]);
        if (count($n['slices']) !== 1 || $n['fingerprint'] !== $expected || $n['proposal_ref'] !== 'epic-proposal:'.substr($expected, 0, 40)) {
            self::bad('EpicProposal integrity');
        }
        self::secretFree($n);
        return $n;
    }

    private static function projectMatch(mixed $r): array
    {
        self::fields($r, ['status', 'project_ref', 'score', 'matched_terms', 'action', 'auto_create'], 'project_match');
        $status = self::one($r['status'], ['none', 'matched', 'ambiguous'], 'project_match.status');
        $ref = $r['project_ref'];
        if ($status === 'matched') {
            if (!is_string($ref) || preg_match('/^controlbot:project\/[a-z][a-z0-9._-]{0,79}$/D', $ref) !== 1) self::bad('project_match.project_ref');
        } elseif ($ref !== null) {
            self::bad('project_match.project_ref');
        }
        if (!is_int($r['score']) || $r['score'] < 0) self::bad('project_match.score');
        $terms = self::textList($r['matched_terms'], 'matched_terms');
        $action = self::one($r['action'], ['no_match', 'link_proposal', 'review_matches'], 'project_match.action');
        $expectedAction = ['none' => 'no_match', 'matched' => 'link_proposal', 'ambiguous' => 'review_matches'][$status];
        if ($action !== $expectedAction || $r['auto_create'] !== false) self::bad('project_match state');
        return ['status' => $status, 'project_ref' => $ref, 'score' => $r['score'], 'matched_terms' => $terms, 'action' => $action, 'auto_create' => false];
    }

    private static function slices(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows) > 50) self::bad('slices');
        $out = [];
        foreach ($rows as $row) {
            self::fields($row, ['slice_key', 'title', 'acceptance'], 'slice');
            $key = self::text($row['slice_key'], 'slice_key');
            if (preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $key) !== 1) self::bad('slice_key');
            $acceptance = self::textList($row['acceptance'], 'acceptance');
            if (!$acceptance) self::bad('acceptance');
            $out[] = ['slice_key' => $key, 'title' => self::text($row['title'], 'slice.title'), 'acceptance' => $acceptance];
        }
        return $out;
    }

    private static function fact(mixed $r, string $label, bool $list): array
    {
        self::fields($r, $list ? ['status', 'values'] : ['status', 'value'], $label);
        $status = self::one($r['status'], ['known', 'unknown'], "$label.status");
        if (!$list) {
            if ($status === 'unknown') {
                if ($r['value'] !== null) self::bad("$label unknown value");
                return ['status' => 'unknown', 'value' => null];
            }
            return ['status' => 'known', 'value' => self::text($r['value'], "$label.value")];
        }
        if (!is_array($r['values']) || !array_is_list($r['values']) || count($r['values']) > 50) self::bad("$label values");
        if ($status === 'unknown') {
            if ($r['values']) self::bad("$label unknown values");
            return ['status' => 'unknown', 'values' => []];
        }
        if (!$r['values']) self::bad("$label known values");
        return ['status' => 'known', 'values' => self::textList($r['values'], "$label.values")];
    }

    private static function textList(mixed $rows, string $label): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) self::bad($label);
        $out = [];
        foreach ($rows as $row) $out[] = self::text($row, $label);
        return array_values(array_unique($out));
    }

    private static function text(mixed $v, string $label): string
    {
        if (!is_string($v) || trim($v) === '' || strlen($v) > 500 || preg_match('/[\x00-\x1F\x7F]/', $v)) self::bad($label);
        self::secretFree($v);
        return trim($v);
    }

    private static function typed(mixed $v, string $ns, string $label): string
    {
        if (!is_string($v) || preg_match('/^'.preg_quote($ns, '/').':[a-f0-9]{40}$/D', $v) !== 1) self::bad($label);
        return $v;
    }

    private static function one(mixed $v, array $allowed, string $label): string
    {
        if (!is_string($v) || !in_array($v, $allowed, true)) self::bad($label);
        return $v;
    }

    private static function hex(mixed $v, string $label): string
    {
        if (!is_string($v) || preg_match('/^[a-f0-9]{64}$/D', $v) !== 1) self::bad($label);
        return $v;
    }

    private static function digest(array $v): string
    {
        return hash('sha256', serialize(self::ordered($v)));
    }

    private static function ordered(mixed $v): mixed
    {
        if (!is_array($v)) return $v;
        if (!array_is_list($v)) ksort($v, SORT_STRING);
        foreach ($v as $k => $x) $v[$k] = self::ordered($x);
        return $v;
    }

    private static function secretFree(mixed $v): void
    {
        $s = is_string($v) ? $v : json_encode($v, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (preg_match(self::SENSITIVE, $s)) self::bad('RequirementDecisionView contains sensitive material');
    }

    private static function fields(mixed $r, array $expected, string $label): void
    {
        if (!is_array($r) || array_is_list($r)) self::bad($label);
        $keys = array_keys($r);
        if (count($keys) !== count($expected) || array_diff($keys, $expected) || array_diff($expected, $keys)) self::bad("$label fields");
    }

    private static function bad(string $message): never
    {
        throw new InvalidArgumentException("$message invalid.");
    }
}
