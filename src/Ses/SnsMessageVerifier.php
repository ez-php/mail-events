<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\Ses;

use Closure;

/**
 * Class SnsMessageVerifier
 *
 * Verifies an Amazon SNS HTTP(S) message — how Amazon SES delivers its event
 * notifications — and confirms subscriptions:
 *
 * - the `TopicArn` must equal the configured topic, so no other AWS account's
 *   topic can post here;
 * - the `SigningCertURL` must be `https://sns.<region>.amazonaws.com[.cn]/….pem`;
 * - the `Signature` must verify against that certificate over SNS's canonical
 *   string (SignatureVersion 1 = SHA1, 2 = SHA256).
 *
 * Certificates are fetched once per URL and kept for the lifetime of this object.
 * There is no replay window: SNS redelivers a message with its original
 * `Timestamp` for up to an hour, so de-duplicate on `MailEvent::$eventId`.
 *
 * @package EzPhp\MailEvents\Ses
 */
final class SnsMessageVerifier
{
    private const string CERT_HOST = '/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/';

    /**
     * Signed fields per message type, in SNS's canonical order.
     */
    private const array SIGNED_FIELDS = [
        'Notification' => ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type'],
        'SubscriptionConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
        'UnsubscribeConfirmation' => ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'],
    ];

    /**
     * @var array<string, string>
     */
    private array $certificates = [];

    private readonly Closure $fetch;

    /**
     * @param string                          $topicArn The SNS topic the SES configuration set publishes to.
     * @param (Closure(string): (string|false))|null $fetch    GET a URL and return its body; defaults to a 5 s HTTPS request (tests inject one).
     */
    public function __construct(
        private readonly string $topicArn,
        ?Closure $fetch = null,
    ) {
        $this->fetch = $fetch ?? self::httpGet(...);
    }

    /**
     * @param array<array-key, mixed> $message The decoded SNS message.
     *
     * @return bool
     */
    public function verify(array $message): bool
    {
        $type = $message['Type'] ?? null;
        $signature = $message['Signature'] ?? null;
        $certUrl = $message['SigningCertURL'] ?? null;
        $algorithm = match ($message['SignatureVersion'] ?? null) {
            '1' => OPENSSL_ALGO_SHA1,
            '2' => OPENSSL_ALGO_SHA256,
            default => null,
        };

        if (
            $this->topicArn === ''
            || !is_string($type) || !isset(self::SIGNED_FIELDS[$type])
            || ($message['TopicArn'] ?? null) !== $this->topicArn
            || !is_string($signature) || !is_string($certUrl) || $algorithm === null
            || !self::isAwsUrl($certUrl, '.pem')
        ) {
            return false;
        }

        $decoded = base64_decode($signature, true);
        $certificate = $this->certificate($certUrl);

        if ($decoded === false || $certificate === null) {
            return false;
        }

        $publicKey = openssl_pkey_get_public($certificate);

        return $publicKey !== false
            && openssl_verify(self::canonical($type, $message), $decoded, $publicKey, $algorithm) === 1;
    }

    /**
     * Confirm a verified `SubscriptionConfirmation` by visiting its SubscribeURL.
     *
     * @param array<array-key, mixed> $message
     *
     * @return bool
     */
    public function confirm(array $message): bool
    {
        $url = $message['SubscribeURL'] ?? null;

        return is_string($url) && self::isAwsUrl($url, null) && ($this->fetch)($url) !== false;
    }

    /**
     * @param string                  $type
     * @param array<array-key, mixed> $message
     *
     * @return string
     */
    private static function canonical(string $type, array $message): string
    {
        $canonical = '';

        foreach (self::SIGNED_FIELDS[$type] as $field) {
            $value = $message[$field] ?? null;

            if (is_string($value)) {
                $canonical .= $field . "\n" . $value . "\n";
            }
        }

        return $canonical;
    }

    /**
     * @param string      $url
     * @param string|null $pathSuffix
     *
     * @return bool
     */
    private static function isAwsUrl(string $url, ?string $pathSuffix): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && !isset($parts['user'])
            && !isset($parts['port'])
            && preg_match(self::CERT_HOST, strtolower($parts['host'] ?? '')) === 1
            && ($pathSuffix === null || str_ends_with($parts['path'] ?? '', $pathSuffix));
    }

    /**
     * @param string $url
     *
     * @return string|null
     */
    private function certificate(string $url): ?string
    {
        if (!isset($this->certificates[$url])) {
            $pem = ($this->fetch)($url);

            if (!is_string($pem) || !str_contains($pem, 'BEGIN CERTIFICATE')) {
                return null;
            }

            $this->certificates[$url] = $pem;
        }

        return $this->certificates[$url];
    }

    /**
     * @param string $url
     *
     * @return string|false
     */
    private static function httpGet(string $url): string|false
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 5, 'follow_location' => 0],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        // A failed request raises a warning; it is handled through the return value.
        set_error_handler(static fn (): bool => true, E_WARNING);

        try {
            return file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }
    }
}
