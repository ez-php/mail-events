<?php

declare(strict_types=1);

namespace Tests\SendGrid;

use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\SendGrid\SendGridSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class SendGridSignatureTest
 *
 * @package Tests\SendGrid
 */
#[CoversClass(SendGridSignature::class)]
#[CoversClass(MailEventsException::class)]
final class SendGridSignatureTest extends TestCase
{
    private const int NOW = 1_700_000_000;

    private const string BODY = '[{"event":"delivered"}]';

    public function test_accepts_a_valid_signature_with_a_base64_or_pem_key(): void
    {
        $keys = SendGridKeyPair::generate();
        $timestamp = (string) self::NOW;
        $signature = $keys->sign($timestamp, self::BODY);

        self::assertTrue(self::verifier($keys->publicBase64())->verify($timestamp, $signature, self::BODY));
        self::assertTrue(self::verifier($keys->publicPem)->verify($timestamp, $signature, self::BODY));
    }

    public function test_rejects_a_tampered_body_timestamp_or_foreign_key(): void
    {
        $keys = SendGridKeyPair::generate();
        $timestamp = (string) self::NOW;
        $signature = $keys->sign($timestamp, self::BODY);
        $verifier = self::verifier($keys->publicBase64());

        self::assertFalse($verifier->verify($timestamp, $signature, '[{"event":"bounce"}]'));
        self::assertFalse($verifier->verify((string) (self::NOW + 1), $signature, self::BODY));
        self::assertFalse(self::verifier(SendGridKeyPair::generate()->publicBase64())->verify($timestamp, $signature, self::BODY));
    }

    public function test_rejects_stale_timestamps_and_malformed_signatures(): void
    {
        $keys = SendGridKeyPair::generate();
        $verifier = self::verifier($keys->publicBase64());
        $old = (string) (self::NOW - 301);

        self::assertFalse($verifier->verify($old, $keys->sign($old, self::BODY), self::BODY));
        self::assertFalse($verifier->verify('abc', $keys->sign('abc', self::BODY), self::BODY));
        self::assertFalse($verifier->verify((string) self::NOW, '***', self::BODY));
        self::assertFalse($verifier->verify((string) self::NOW, '', self::BODY));
    }

    public function test_defaults_to_the_system_clock(): void
    {
        $keys = SendGridKeyPair::generate();
        $now = (string) time();

        self::assertTrue((new SendGridSignature($keys->publicBase64()))->verify($now, $keys->sign($now, self::BODY), self::BODY));
    }

    public function test_rejects_an_invalid_key(): void
    {
        $this->expectException(MailEventsException::class);

        new SendGridSignature('not-a-key');
    }

    /**
     * @param string $key
     *
     * @return SendGridSignature
     */
    private static function verifier(string $key): SendGridSignature
    {
        return new SendGridSignature($key, 300, fn (): int => self::NOW);
    }
}
