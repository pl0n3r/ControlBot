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

        foreach (['problem', 'user', 'objectives', 'out_of_scope', 'dependencies'] as $f) {
            if ($p[$f]['status'] === 'unknown') {
                $unknown[] = $f;
            }
        }

        $project = [
            'artifact' => 'project',
            'operation' => match ($p['project_match']['status']) {
                'matched' => 'link_existing',
                'ambiguous' => 'review_matches',
                default => 'owner_choice_required',
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

        $issues = array_map(
            static fn (array $s): array => [
                'artifact' => 'issue',
                'operation' => 'propose_create',
                'slice_key' => $s['slice_key'],
                'title' => $s['title'],
                'acceptance' => $s['acceptance'],
                'execution' => false,
            ],
            $p['slices'],
        );

        $options = [
            ['code' => 'approve', 'intent' => 'allow_later_materialization', 'execution' => false],
            ['code' => 'revise', 'intent' => 'request_changes', 'execution' => false],
            ['code' => 'reject', 'intent' => 'decline_proposal', 'execution' => false],
        ];

        $surface = [
            'sections' => ['summary', 'impacts', 'materialization_diff', 'decision'],
            'controls' => ['approve', 'revise', 'reject'],
            'semantic_element' => 'button',
            'group_role' => 'group',
            'aria_label' => 'Decisión sobre requerimiento',
            'min_target_px' => 52,
            'keyboard' => true,
            'keyboard_keys' => ['Enter', 'Space'],
            'focus_visible' => true,
            'gesture_only' => false,
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
            'surfaces' => ['desktop' => $surface, 'mobile' => $surface],
            'execution' => false,
        ];

        $fp = self::digest($base);
        $out = [
            'version' => 1,
            'view_ref' => 'requirement-decision-view:' . substr($fp, 0, 40),
        ] + $base + ['fingerprint' => $fp];

        self::secretFree($out);
        return $out;
    }

    private static function proposal(array $r): array
    {
        self::fields($r, [
            'version', 'proposal_ref', 'draft_ref', 'problem', 'user', 'objectives',
            'out_of_scope', 'risks', 'dependencies', 'questions', 'slices',
            'project_match', 'requires_approval', 'execution', 'fingerprint',
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

        if (
            count($n['slices']) !== 1
            || $n['fingerprint'] !== $expected
            || $n['proposal_ref'] !== 'epic-proposal:' . substr($expected, 0, 40)
        ) {
            self::bad('EpicProposal integrity');
        }

        self::secretFree($n);
        return $n;
    }

    private static function projectMatch(mixed $r): array
    {
        self::fields($r, ['status', 'project_ref', 'score', 'matched_terms', 'action', 'auto_create'], 'project_match');

        $s = self::one($r['status'], ['none', 'matched', 'ambiguous'], 'project_match.status');
        $ref = $r['project_ref'];

        if ($s === 'matched') {
            if (!is_string($ref) || preg_match('/^controlbot:project\/[a-z][a-z0-9._-]{0,79}$/D', $ref) !== 1) {
                self::bad('project_match.project_ref');
            }
        } elseif ($ref !== null) {
            self::bad('project_match.project_ref');
        }

        if (!is_int($r['score']) || $r['score'] < 0) {
            self::bad('project_match.score');
        }

        $terms = self::textList($r['matched_terms'], 'matched_terms');
        $action = self::one($r['action'], ['no_match', 'link_proposal', 'review_matches'], 'project_match.action');

        if (
            $action !== ['none' => 'no_match', 'matched' => 'link_proposal', 'ambiguous' => 'review_matches'][$s]
            || $r['auto_create'] !== false
        ) {
            self::bad('project_match state');
        }

        return [
            'status' => $s,
            'project_ref' => $ref,
            'score' => $r['score'],
            'matched_terms' => $terms,
            'action' => $action,
            'auto_create' => false,
        ];
    }

    private static function slices(mixed $rows): array
    {
        if (!is_array($rows) || !array_is_list($rows) || !$rows || count($rows) > 50) {
            self::bad('slices');
        }

        $out = [];
        foreach ($rows as $r) {
            self::fields($r, ['slice_key', 'title', 'acceptance'], 'slice');
            $k = self::text($r['slice_key'], 'slice_key');
            if (preg_match('/^[a-z][a-z0-9._-]{0,79}$/D', $k) !== 1) {
                self::bad('slice_key');
            }

            $a = self::textList($r['acceptance'], 'acceptance');
            if (!$a) {
                self::bad('acceptance');
            }

            $out[] = [
                'slice_key' => $k,
                'title' => self::text($r['title'], 'slice.title'),
                'acceptance' => $a,
            ];
        }
        return $out;
    }

    private static function fact(mixed $r, string $label, bool $list): array
    {
        self::fields($r, $list ? ['status', 'values'] : ['status', 'value'], $label);
        $s = self::one($r['status'], ['known', 'unknown'], "$label.status");

        if (!$list) {
            if ($s === 'unknown') {
                if ($r['value'] !== null) {
                    self::bad("$label unknown value");
                }
                return ['status' => 'unknown', 'value' => null];
            }
            return ['status' => 'known', 'value' => self::text($r['value'], "$label.value")];
        }

        if (!is_array($r['values']) || !array_is_list($r['values']) || count($r['values']) > 50) {
            self::bad("$label values");
        }
        if ($s === 'unknown') {
            if ($r['values']) {
                self::bad("$label unknown values");
            }
            return ['status' => 'unknown', 'values' => []];
        }
        if (!$r['values']) {
            self::bad("$label known values");
        }
        return ['status' => 'known', 'values' => self::textList($r['values'], "$label.values")];
    }

    private static function textList(mixed $rows,string $label): array{if(!is_array($rows)||!array_is_list($rows)||count($rows)>100)self::bad($label);$out=[];foreach($rows as $r)$out[]=self::text($r,$label);return array_values(array_unique($out));}
    private static function text(mixed $v,string $label): string{if(!is_string($v)||trim($v)===''||strlen($v)>500||preg_match('/[\x00-\x1F\x7F]/',$v))self::bad($label);self::secretFree($v);return trim($v);}
    private static function typed(mixed $v,string $ns,string $label): string{if(!is_string($v)||preg_match('/^'.preg_quote($ns,'/').':[a-f0-9]{40}$/D',$v)!==1)self::bad($label);return $v;}
    private static function one(mixed $v,array $a,string $label): string{if(!is_string($v)||!in_array($v,$a,true))self::bad($label);return $v;}
    private static function hex(mixed $v,string $label): string{if(!is_string($v)||preg_match('/^[a-f0-9]{64}$/D',$v)!==1)self::bad($label);return $v;}
    private static function digest(array $v): string{return hash('sha256',serialize(self::ordered($v)));}
    private static function ordered(mixed $v): mixed{if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as $k=>$x)$v[$k]=self::ordered($x);return $v;}
    private static function secretFree(mixed $v): void{$s=is_string($v)?$v:json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);if(preg_match(self::SENSITIVE,$s))self::bad('RequirementDecisionView contains sensitive material');}
    private static function fields(mixed $r,array $e,string $label): void{if(!is_array($r)||array_is_list($r))self::bad($label);$k=array_keys($r);if(count($k)!==count($e)||array_diff($k,$e)||array_diff($e,$k))self::bad("$label fields");}
    private static function bad(string $m): never{throw new InvalidArgumentException("$m invalid.");}
}
