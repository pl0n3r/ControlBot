<?php
declare(strict_types=1);

namespace ControlBot\Production;

final class HostingerSshRuntime
{
    private readonly HostingerExecutor $executor;

    public function __construct(
        SecretsBroker $secretsBroker,
        ConnectionIdentityBroker $identityBroker,
        ?callable $sshClient = null,
    ) {
        $identityResolver = new SshConnectionIdentityResolver(
            $identityBroker,
            'hostinger-executor',
        );
        $transport = new SshTransportAdapter(
            $identityResolver,
            $sshClient ?? new OpenSshClient(),
        );
        $this->executor = new HostingerExecutor(
            $secretsBroker,
            $transport,
            'hostinger-executor',
        );
    }

    public function execute(
        ProductionOperation $operation,
        ?CapabilityGrant $grant,
        ConnectionProfile $profile,
        SecretReference $secretReference,
        array $request,
    ): array {
        return $this->executor->execute(
            $operation,
            $grant,
            $profile,
            $secretReference,
            $request,
        );
    }
}
