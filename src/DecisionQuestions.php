<?php
declare(strict_types=1);

namespace ControlBot\Decisions;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

interface DecisionConversationProvider
{
    public function answer(array $context, string $question): string;
}

final class DecisionQuestions
{
    private const SESSION_KEY = '_controlbot_decision_questions';
    private const MAX_ENTRIES = 20;
    private const MAX_QUESTION_BYTES = 500;
    private const MAX_ANSWER_BYTES = 2000;

    public function __construct(
        private readonly ?DecisionConversationProvider $provider = null,
    ) {}

    public function ask(
        array &$session,
        array $decisions,
        string $repository,
        int $issue,
        string $question,
        int $now,
    ): array {
        $question = self::cleanText($question, self::MAX_QUESTION_BYTES, 5, 'Pregunta inválida.');
        if ($issue < 1 || $now < 1) {
            throw new InvalidArgumentException('Contexto de pregunta inválido.');
        }

        $matches = array_values(array_filter(
            $decisions,
            static fn (mixed $decision): bool => is_array($decision)
                && ($decision['repository'] ?? null) === $repository
                && ($decision['issue'] ?? null) === $issue,
        ));
        if (count($matches) !== 1) {
            throw new RuntimeException('La decisión no está disponible para preguntar.');
        }

        $answer = null;
        $status = 'unavailable';
        if ($this->provider !== null) {
            try {
                $candidate = $this->provider->answer(self::providerContext($matches[0]), $question);
                $answer = self::cleanText(
                    $candidate,
                    self::MAX_ANSWER_BYTES,
                    20,
                    'Respuesta conversacional inválida.',
                );
                $status = 'answered';
            } catch (Throwable) {
                $answer = null;
                $status = 'unavailable';
            }
        }

        $entry = [
            'repository' => $repository,
            'issue' => $issue,
            'question' => $question,
            'answer' => $answer,
            'status' => $status,
            'at' => $now,
        ];
        $entries = self::entries($session);
        $entries[self::key($repository, $issue)] = $entry;
        uasort($entries, static fn (array $left, array $right): int => $right['at'] <=> $left['at']);
        $entries = array_slice($entries, 0, self::MAX_ENTRIES, true);
        $session[self::SESSION_KEY] = $entries;

        return $entry;
    }

    public static function forUi(array $session): array
    {
        return self::entries($session);
    }

    private static function entries(array $session): array
    {
        $raw = $session[self::SESSION_KEY] ?? [];
        if (!is_array($raw) || count($raw) > self::MAX_ENTRIES) {
            throw new RuntimeException('Estado de preguntas inválido.');
        }

        $entries = [];
        foreach ($raw as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                throw new RuntimeException('Estado de preguntas inválido.');
            }
            $repository = $entry['repository'] ?? null;
            $issue = $entry['issue'] ?? null;
            $question = $entry['question'] ?? null;
            $answer = $entry['answer'] ?? null;
            $status = $entry['status'] ?? null;
            $at = $entry['at'] ?? null;
            if (
                !is_string($repository)
                || !is_int($issue) || $issue < 1
                || !is_string($question)
                || strlen($question) > self::MAX_QUESTION_BYTES
                || ($answer !== null && (!is_string($answer) || strlen($answer) > self::MAX_ANSWER_BYTES))
                || !in_array($status, ['answered', 'unavailable'], true)
                || ($status === 'answered' && $answer === null)
                || ($status === 'unavailable' && $answer !== null)
                || !is_int($at) || $at < 1
                || !hash_equals(self::key($repository, $issue), $key)
            ) {
                throw new RuntimeException('Estado de preguntas inválido.');
            }
            $entries[$key] = $entry;
        }
        return $entries;
    }

    private static function providerContext(array $decision): array
    {
        $repository = $decision['repository'] ?? null;
        $issue = $decision['issue'] ?? null;
        $title = $decision['title_simple'] ?? $decision['title'] ?? null;
        $summary = $decision['summary_simple'] ?? $decision['context'] ?? null;
        $category = $decision['category'] ?? null;
        if (
            !is_string($repository)
            || !is_int($issue) || $issue < 1
            || !is_string($title) || trim($title) === ''
            || !is_string($summary) || trim($summary) === ''
            || !is_string($category) || trim($category) === ''
        ) {
            throw new RuntimeException('Contexto de decisión inválido.');
        }

        return [
            'repository' => $repository,
            'issue' => $issue,
            'title' => trim($title),
            'summary' => trim($summary),
            'category' => $category,
        ];
    }

    private static function cleanText(string $value, int $maxBytes, int $maxLines, string $error): string
    {
        $value = trim($value);
        if (
            $value === ''
            || strlen($value) > $maxBytes
            || substr_count($value, "\n") + 1 > $maxLines
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1
        ) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private static function key(string $repository, int $issue): string
    {
        return $repository . '#' . $issue;
    }
}
