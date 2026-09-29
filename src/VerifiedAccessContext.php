<?php
declare(strict_types=1);

namespace ControlBot\Business;

use LogicException;

final class VerifiedAccessContext
{
    private function __construct(
        private readonly array $decisionContext,
        private readonly array $grant,
    ) {
    }

    public static function fromServerSource(
        VentureAccessSource $source,
        array $query,
        int $now,
    ): self {
        $query = VentureAccessSourceContract::query($query);
        $resolved = $source->resolve(
            $query['identity_id'],
            $query['scope'],
            $query['capability'],
            $now,
        );
        $canonical = VentureAccessSourceContract::resolved($resolved, $query, $now);

        return new self(
            [
                'identity' => $canonical['identity'],
                'scope' => $canonical['scope'],
                'active_policy_refs' => $canonical['active_policy_refs'],
            ],
            $canonical['grant'],
        );
    }

    public function decisionContext(): array
    {
        return $this->decisionContext;
    }

    public function grant(): array
    {
        return $this->grant;
    }

    public function safeSummary(): array
    {
        return [
            'identity_id' => $this->decisionContext['identity']['identity_id'],
            'scope' => $this->decisionContext['scope'],
            'policy_refs' => $this->decisionContext['active_policy_refs'],
            'grant_id' => $this->grant['grant_id'],
            'capability' => $this->grant['capability'],
            'authority_level' => $this->grant['authority_level'],
        ];
    }

    private function __clone(): void
    {
    }

    public function __serialize(): array
    {
        throw new LogicException('VerifiedAccessContext is not serializable.');
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('VerifiedAccessContext is not unserializable.');
    }
}
