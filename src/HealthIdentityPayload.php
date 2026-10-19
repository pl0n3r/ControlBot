<?php
declare(strict_types=1);

namespace ControlBot\Health;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Pure structural identity for a supplied release artifact.
 *
 * A valid payload is not evidence that a server, deploy, or endpoint is healthy.
 * The `ok` status means only that the supplied identity fields are structurally valid.
 */
final readonly class HealthIdentityPayload implements JsonSerializable
{
    private function __construct(
        public string $status,
        public string $version,
        public string $releaseSha,
    ) {
    }

    public static function build(string $version, string $releaseSha): self
    {
        if (preg_match(
            '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/',
            $version
        ) !== 1) {
            throw new InvalidArgumentException('Invalid health identity.');
        }

        if (preg_match('/\A[0-9a-f]{40}\z/', $releaseSha) !== 1) {
            throw new InvalidArgumentException('Invalid health identity.');
        }

        return new self('ok', $version, $releaseSha);
    }

    /**
     * Expose only the versioned, minimal public payload contract.
     *
     * @return array{status: string, version: string, release_sha: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status,
            'version' => $this->version,
            'release_sha' => $this->releaseSha,
        ];
    }
}
