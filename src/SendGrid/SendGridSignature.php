<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\SendGrid;

use Closure;
use EzPhp\MailEvents\MailEventsException;
use OpenSSLAsymmetricKey;

/**
 * Class SendGridSignature
 *
 * Verifies SendGrid's Signed Event Webhook: an ECDSA (P-256, SHA-256)
 * signature over `timestamp . rawBody`, sent base64-encoded in
 * `X-Twilio-Email-Event-Webhook-Signature`, with the timestamp in
 * `X-Twilio-Email-Event-Webhook-Timestamp`. A timestamp outside the tolerance
 * window is rejected (replay protection). Needs ext-openssl.
 *
 * The public key is accepted as SendGrid shows it (base64 DER) or as PEM.
 *
 * @package EzPhp\MailEvents\SendGrid
 */
final readonly class SendGridSignature
{
    public const string SIGNATURE_HEADER = 'X-Twilio-Email-Event-Webhook-Signature';

    public const string TIMESTAMP_HEADER = 'X-Twilio-Email-Event-Webhook-Timestamp';

    private OpenSSLAsymmetricKey $publicKey;

    /**
     * @var Closure(): int
     */
    private Closure $clock;

    /**
     * @param string                $publicKey Verification key: base64 DER or PEM.
     * @param int                   $tolerance Max. age (and clock skew) in seconds.
     * @param (Closure(): int)|null $clock     Current Unix time; defaults to time().
     *
     * @throws MailEventsException When the key cannot be loaded.
     */
    public function __construct(
        string $publicKey,
        private int $tolerance = 300,
        ?Closure $clock = null,
    ) {
        $publicKey = trim($publicKey);
        $pem = str_starts_with($publicKey, '-----BEGIN')
            ? $publicKey
            : "-----BEGIN PUBLIC KEY-----\n" . chunk_split($publicKey, 64, "\n") . "-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new MailEventsException('The SendGrid webhook verification key is not a valid public key.');
        }

        $this->publicKey = $key;
        $this->clock = $clock ?? time(...);
    }

    /**
     * @param string $timestamp Value of the timestamp header.
     * @param string $signature Value of the signature header (base64 DER ECDSA).
     * @param string $rawBody   The request body, byte for byte.
     *
     * @return bool
     */
    public function verify(string $timestamp, string $signature, string $rawBody): bool
    {
        if (!ctype_digit($timestamp) || abs(($this->clock)() - (int) $timestamp) > $this->tolerance) {
            return false;
        }

        $decoded = base64_decode($signature, true);

        if ($decoded === false || $decoded === '') {
            return false;
        }

        return openssl_verify($timestamp . $rawBody, $decoded, $this->publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
