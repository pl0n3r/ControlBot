<?php
declare(strict_types=1);

namespace ControlBot\Business;

use InvalidArgumentException;

/**
 * Pure guard for already-shaped live projections. No IO, network, or side effects.
 * This guard is a build-ahead candidate, NOT connected to the live cache yet.
 * Producer contracts: FactoryLiveOrchestratorSnapshot in ControlBot PR #766.
 */
final class FactoryOrchestratorFieldSafety
{
    private const SENSITIVE =
        '/(?:password|passwd|secret|token|cookie|authorization|bearer|private[_ -]?key|api[_ -]?key|dsn)/i';
    private const PII =
        '/(?:[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\+?(?=(?:[0-9(). -]*[0-9]){10})[0-9][0-9(). -]{7,}[0-9])/i';

    public static function signalId(mixed $value): void
    {
        // Exactly the producer's ID pattern; the quantifier applies after the first character.
        if (!is_string($value)
            || preg_match('/^[a-z][a-z0-9._:\/#-]{2,160}$/D', $value) !== 1) {
            throw new InvalidArgumentException('signal id invalid');
        }
        self::sourceText($value, 180);
        self::uiText($value);
    }

    public static function sourceText(mixed $value, int $max): void
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('source text invalid');
        }
        $value = trim($value);
        if ($value === '' || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match(self::SENSITIVE, $value) === 1
            || preg_match(self::PII, $value) === 1) {
            throw new InvalidArgumentException('unsafe source text');
        }
    }

    public static function uiText(mixed $value): void
    {
        // HTML renderer checks byte count before trim, unlike the producer.
        if (!is_string($value) || trim($value) === '' || strlen($value) > 240) {
            throw new InvalidArgumentException('HTML text invalid');
        }
    }

    public static function decisionText(mixed $value, int $max): void
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('decision text invalid');
        }
        $value = trim($value);
        // This reproduces the decision producer, which does not use SENSITIVE/PII here.
        if ($value === '' || strlen($value) > $max
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            throw new InvalidArgumentException('decision text invalid');
        }
    }

    public static function assertProjection(array $view): void
    {
        $central = $view['central'] ?? null;
        if (!is_array($central) || array_is_list($central)) {
            throw new InvalidArgumentException('central invalid');
        }
        if (($central['source_ref'] ?? null) !== null) {
            self::sourceText($central['source_ref'], 240);
            self::uiText($central['source_ref']);
        }

        $fronts = $view['fronts'] ?? null;
        if (!is_array($fronts) || !array_is_list($fronts)) {
            throw new InvalidArgumentException('fronts invalid');
        }
        foreach ($fronts as $front) {
            if (!is_array($front) || array_is_list($front)) {
                throw new InvalidArgumentException('front invalid');
            }
            self::signalId($front['id'] ?? null);
            self::sourceText($front['source_ref'] ?? null, 240);
            self::uiText($front['source_ref']);
        }

        $decisions = $view['owner_decisions'] ?? null;
        if (!is_array($decisions) || !array_is_list($decisions)) {
            throw new InvalidArgumentException('decisions invalid');
        }
        foreach ($decisions as $decision) {
            if (!is_array($decision) || array_is_list($decision)) {
                throw new InvalidArgumentException('decision invalid');
            }
            self::signalId($decision['id'] ?? null);
            self::sourceText($decision['source_ref'] ?? null, 240);
            self::uiText($decision['source_ref']);
            self::decisionText($decision['title'] ?? null, 160);

            if (($decision['format'] ?? null) !== 'structured') {
                continue; // The existing cache validator checks the legacy/structured discriminant.
            }
            foreach (['title_simple'=>160, 'summary_simple'=>600,
                'why_recommended'=>600, 'blocks'=>600] as $key => $limit) {
                self::decisionText($decision[$key] ?? null, $limit);
            }
            if (($decision['explain_simple'] ?? null) !== null) {
                self::decisionText($decision['explain_simple'], 600);
            }
            $options = $decision['options'] ?? null;
            if (!is_array($options) || !array_is_list($options)
                || count($options) < 1 || count($options) > 6) {
                throw new InvalidArgumentException('options invalid');
            }
            foreach ($options as $option) {
                if (!is_array($option) || array_is_list($option)) {
                    throw new InvalidArgumentException('option invalid');
                }
                foreach (['label'=>200, 'effect'=>600, 'risk'=>80] as $key => $limit) {
                    self::decisionText($option[$key] ?? null, $limit);
                }
                foreach (['cost'=>240, 'explain_simple'=>400] as $key => $limit) {
                    if (($option[$key] ?? null) !== null) {
                        self::decisionText($option[$key], $limit);
                    }
                }
                foreach (['pros', 'cons'] as $key) {
                    $list = $option[$key] ?? null;
                    if (!is_array($list) || !array_is_list($list) || count($list) > 6) {
                        throw new InvalidArgumentException('option text list invalid');
                    }
                    foreach ($list as $value) {
                        self::decisionText($value, 300);
                    }
                }
            }
        }
    }
}
