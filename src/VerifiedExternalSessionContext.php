<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use ControlBot\Business\VerifiedAccessContext;
use InvalidArgumentException;
use LogicException;

final class VerifiedExternalSessionContext
{
    private function __construct(private readonly array $canonical){}

    /** Build only from verified access plus a server-side session source. */
    public static function fromSource(
        VerifiedAccessContext $access, ExternalApiSessionSource $source,
        string $deviceRef, string $sessionRef, ?string $stepUpRef, int $now,
    ): self {
        $summary=$access->safeSummary();
        $identity=$summary['identity_id']??null; $scope=$summary['scope']??null;
        if(!is_string($identity)||!is_string($scope))
            throw new InvalidArgumentException('verified access summary invalid.');
        $query=ExternalApiSessionSourceContract::query($identity,$scope,$deviceRef,$sessionRef,$stepUpRef,$now);
        $resolved=$source->resolve(
            $query['identity_id'],$query['scope'],$query['device_ref'],
            $query['session_ref'],$query['step_up_ref'],$query['now']
        );
        if(!is_array($resolved)) throw new InvalidArgumentException('session source response invalid.');
        return new self(ExternalApiSessionSourceContract::resolved($resolved,$query));
    }

    /** Return normalized device metadata. */
    public function device(): array { return $this->canonical['device']; }

    /** Return normalized session metadata. */
    public function session(): array { return $this->canonical['session']; }

    /** Return normalized step-up metadata when requested. */
    public function stepUp(): ?array { return $this->canonical['step_up']; }

    /** Return a secret-free audit summary without authorization fields. */
    public function safeSummary(): array
    {
        return [
            'version'=>1,'identity_id'=>$this->canonical['identity_id'],'scope'=>$this->canonical['scope'],
            'device_ref'=>$this->canonical['device']['device_ref'],
            'session_ref'=>$this->canonical['session']['session_ref'],
            'step_up_ref'=>$this->canonical['step_up']['step_up_ref']??null,
            'observed_at'=>$this->canonical['observed_at'],'freshness'=>$this->canonical['freshness'],
            'source_ref'=>$this->canonical['source_ref'],
        ];
    }

    private function __clone(): void {}
    public function __serialize(): array { throw new LogicException('VerifiedExternalSessionContext is not serializable.'); }
    public function __unserialize(array $data): void { throw new LogicException('VerifiedExternalSessionContext is not unserializable.'); }
}
