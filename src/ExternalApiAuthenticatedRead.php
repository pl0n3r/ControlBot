<?php
declare(strict_types=1);

namespace ControlBot\ExternalApi;

use ControlBot\Business\VerifiedAccessContext;
use InvalidArgumentException;

final class ExternalApiAuthenticatedRead
{
    public static function cockpit(
        VerifiedAccessContext $access,
        VerifiedExternalSessionContext $authentication,
        string $expectedScope,
        array $meta,
        array $ventures,
        int $now,
    ): array {
        self::authorize($access,$authentication,$expectedScope,'/api/v1/cockpit','cockpit.read',$now);
        return ExternalApiReadProjection::cockpit($meta,$ventures);
    }

    public static function ownerInbox(
        VerifiedAccessContext $access,
        VerifiedExternalSessionContext $authentication,
        string $expectedScope,
        array $meta,
        array $entries,
        int $now,
    ): array {
        self::authorize($access,$authentication,$expectedScope,'/api/v1/owner-inbox','owner_inbox.read',$now);
        return ExternalApiReadProjection::ownerInbox($meta,$entries);
    }

    private static function authorize(
        VerifiedAccessContext $access,
        VerifiedExternalSessionContext $authentication,
        string $expectedScope,
        string $path,
        string $operationId,
        int $now,
    ): void {
        $result=ExternalApiRequestGate::authorize(
            $access,'GET',$path,$expectedScope,$authentication,$now
        );
        if(($result['decision']??null)!=='allow'
            ||($result['operation_id']??null)!==$operationId
            ||($result['mutation']??true)!==false
            ||($result['scope']??null)!==$expectedScope)
            throw new InvalidArgumentException('Authenticated read not authorized.');
    }
}
