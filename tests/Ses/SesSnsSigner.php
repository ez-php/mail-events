<?php

declare(strict_types=1);

namespace Tests\Ses;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Test helper: a fresh RSA key and self-signed certificate that signs SNS
 * messages the way Amazon SNS does (canonical string, SignatureVersion 1/2).
 */
final readonly class SesSnsSigner
{
    public const string CERT_URL = 'https://sns.eu-central-1.amazonaws.com/SimpleNotificationService-test.pem';

    public const string TOPIC = 'arn:aws:sns:eu-central-1:123456789012:ses-events';

    private function __construct(
        private OpenSSLAsymmetricKey $key,
        public string $certificate,
    ) {
    }

    public static function generate(): self
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        if ($key === false) {
            throw new RuntimeException('Could not create a test key.');
        }

        $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key);

        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new RuntimeException('Could not create a test CSR.');
        }

        $cert = openssl_csr_sign($csr, null, $key, 1);

        if ($cert === false || !openssl_x509_export($cert, $pem)) {
            throw new RuntimeException('Could not create a test certificate.');
        }

        return new self($key, $pem);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, string>
     */
    public function sign(array $fields, string $version = '2'): array
    {
        $message = [
            'MessageId' => 'sns-1',
            'TopicArn' => self::TOPIC,
            'Timestamp' => '2026-10-30T10:00:00.000Z',
            'SignatureVersion' => $version,
            'SigningCertURL' => self::CERT_URL,
            ...$fields,
        ];
        $order = $message['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $canonical = '';

        foreach ($order as $field) {
            if (isset($message[$field])) {
                $canonical .= $field . "\n" . $message[$field] . "\n";
            }
        }

        openssl_sign($canonical, $signature, $this->key, $version === '1' ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256);
        $message['Signature'] = base64_encode((string) $signature);

        return $message;
    }
}
