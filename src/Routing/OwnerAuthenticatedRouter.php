<?php
declare(strict_types=1);

namespace ControlBot\\Routing;

use ControlBot\\Security\\OwnerSessionService;
use Throwable;

/**
 * Valida una sesion OAuth del owner, cifrada y reautenticada por TOTP.
 * Ningun dato de REMOTE_USER, cookies crudas, request flags o querystring
 * concede acceso por esta via; la sesion se recibe del storage del servidor.
 */
final class OwnerAuthenticatedRouter
{
    public static function verifiedOwner(OwnerSessionService $service, array $session, int $now): ?string
    {
        try {
            if ($now < 1) {
                return null;
            }
            $context = $service->contextFromRequest(
                $session,
                ['_csrf' => $service->csrfToken($session)],
                $now,
            );
            // Verifica MAC del token OAuth sellado, sin incluirlo en respuestas.
            $service->githubToken($session);
            return $context->isOwner ? $context->login : null;
        } catch (Throwable) {
            return null;
        }
    }
}
