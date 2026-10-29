<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\Mailgun;

use Closure;

/**
 * Class MailgunSignature
 *
 * Verifies the `signature` object Mailgun puts in every webhook body:
 * HMAC-SHA256 over `timestamp . token`, keyed with the domain's HTTP webhook
 * signing key, hex-encoded. A timestamp outside the tolerance window is
 * rejected, so a captured request cannot be replayed later.
 *
 * @package EzPhp\MailEvents\Mailgun
 */
final readonly class MailgunSignature
{
    /**
     * @var Closure(): int
     */
    private Closure $clock;

    /**
     * @param string             $signingKey Mailgun's "HTTP webhook signing key".
     * @param int                $tolerance  Max. age (and clock skew) in seconds.
     * @param (Closure(): int)|null $clock   Current Unix time; defaults to time().
     */
    public function __construct(
        private string $signingKey,
        private int $tolerance = 300,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param string $timestamp
     * @param string $token
     * @param string $signature Hex HMAC from the payload.
     *
     * @return bool
     */
    public function verify(string $timestamp, string $token, string $signature): bool
    {
        if ($this->signingKey === '' || !ctype_digit($timestamp)) {
            return false;
        }

        if (abs(($this->clock)() - (int) $timestamp) > $this->tolerance) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp . $token, $this->signingKey), strtolower($signature));
    }
}
