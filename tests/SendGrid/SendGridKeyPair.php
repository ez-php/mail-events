<?php

declare(strict_types=1);

namespace Tests\SendGrid;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A fresh P-256 key pair standing in for SendGrid's signing key.
 *
 * @package Tests\SendGrid
 */
final readonly class SendGridKeyPair
{
    /**
     * @param OpenSSLAsymmetricKey $privateKey
     * @param string               $publicPem
     */
    private function __construct(
        private OpenSSLAsymmetricKey $privateKey,
        public string $publicPem,
    ) {
    }

    /**
     * @return self
     */
    public static function generate(): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $details = $key !== false ? openssl_pkey_get_details($key) : false;

        if ($key === false || $details === false || !is_string($details['key'] ?? null)) {
            throw new RuntimeException('Could not generate an EC key pair.');
        }

        return new self($key, $details['key']);
    }

    /**
     * The public key the way SendGrid's UI shows it: base64 DER, no PEM armour.
     *
     * @return string
     */
    public function publicBase64(): string
    {
        return str_replace(["\n", '-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $this->publicPem);
    }

    /**
     * Base64 ECDSA signature over `timestamp . body`, as in the signature header.
     *
     * @param string $timestamp
     * @param string $body
     *
     * @return string
     */
    public function sign(string $timestamp, string $body): string
    {
        openssl_sign($timestamp . $body, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);

        return base64_encode(is_string($signature) ? $signature : '');
    }
}
