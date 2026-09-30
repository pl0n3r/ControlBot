<?php
declare(strict_types=1);

namespace ControlBot\Production;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class SshConnectionIdentityResolver
{
    private const FIELDS = ['username_ref', 'provider', 'project', 'environment'];

    public function __construct(
        private readonly ConnectionIdentityBroker $broker,
        private readonly string $executorId = 'ssh-transport-adapter',
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{1,79}$/D', $this->executorId) !== 1) {
            throw new InvalidArgumentException('Executor id inválido.');
        }
    }

    public function __invoke(array $context, callable $consumer): mixed
    {
        $context = self::context($context);

        try {
            $surface = $this->broker->agentSurface($context['username_ref']);
        } catch (Throwable) {
            throw new RuntimeException('SSH identity unavailable.');
        }

        $identity = self::identity($surface);
        foreach (['username_ref', 'provider', 'project', 'environment'] as $field) {
            if ($identity[$field] !== $context[$field]) {
                throw new RuntimeException('SSH identity unavailable.');
            }
        }

        $result = $this->broker->execute(
            [
                'executor_id' => $this->executorId,
                'username_ref' => $identity['username_ref'],
                'provider' => $identity['provider'],
                'project' => $identity['project'],
                'environment' => $identity['environment'],
                'generation' => $identity['generation'],
            ],
            static function (string $username, array $metadata) use ($context, $consumer): mixed {
                foreach (['username_ref', 'provider', 'project', 'environment'] as $field) {
                    if (($metadata[$field] ?? null) !== $context[$field]) {
                        throw new RuntimeException('SSH identity scope changed.');
                    }
                }
                return $consumer($username);
            },
        );

        if (($result['ok'] ?? false) !== true || !array_key_exists('result', $result)) {
            throw new RuntimeException('SSH identity unavailable.');
        }
        return $result['result'];
    }

    private static function context(array $context): array
    {
        if (array_is_list($context)
            || count($context) !== count(self::FIELDS)
            || array_diff(self::FIELDS, array_keys($context)) !== []
            || array_diff(array_keys($context), self::FIELDS) !== []) {
            throw new InvalidArgumentException('SSH identity context inválido.');
        }

        if (!is_string($context['username_ref'])
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{2,159}$/D', $context['username_ref']) !== 1
            || $context['provider'] !== 'hostinger'
            || !is_string($context['project'])
            || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $context['project']) !== 1
            || !is_string($context['environment'])
            || preg_match('/^[a-z][a-z0-9-]{1,31}$/D', $context['environment']) !== 1) {
            throw new InvalidArgumentException('SSH identity context inválido.');
        }
        return $context;
    }

    private static function identity(array $surface): array
    {
        if (!array_key_exists('username', $surface)
            || $surface['username'] !== null
            || ($surface['resolvable'] ?? true) !== false
            || !is_array($surface['identity'] ?? null)) {
            throw new RuntimeException('SSH identity surface invalid.');
        }

        $identity = $surface['identity'];
        foreach (['username_ref', 'provider', 'project', 'environment'] as $field) {
            if (!is_string($identity[$field] ?? null)) {
                throw new RuntimeException('SSH identity metadata invalid.');
            }
        }
        if (!is_int($identity['generation'] ?? null) || $identity['generation'] < 1) {
            throw new RuntimeException('SSH identity metadata invalid.');
        }
        return $identity;
    }
}
