<?php

declare(strict_types=1);

namespace Tests\Mailgun;

use EzPhp\MailEvents\Mailgun\MailgunSignature;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class MailgunSignatureTest
 *
 * @package Tests\Mailgun
 */
#[CoversClass(MailgunSignature::class)]
final class MailgunSignatureTest extends TestCase
{
    private const string KEY = 'key-signing-secret';

    private const int NOW = 1_700_000_000;

    public function test_accepts_a_valid_signature(): void
    {
        self::assertTrue(self::verifier()->verify((string) self::NOW, 'tok', self::sign((string) self::NOW, 'tok')));
        self::assertTrue(self::verifier()->verify((string) self::NOW, 'tok', strtoupper(self::sign((string) self::NOW, 'tok'))));
    }

    public function test_rejects_a_wrong_signature_token_or_key(): void
    {
        $signature = self::sign((string) self::NOW, 'tok');

        self::assertFalse(self::verifier()->verify((string) self::NOW, 'other', $signature));
        self::assertFalse(self::verifier()->verify((string) self::NOW, 'tok', str_repeat('0', 64)));
        self::assertFalse((new MailgunSignature('other-key', 300, fn (): int => self::NOW))->verify((string) self::NOW, 'tok', $signature));
    }

    public function test_rejects_timestamps_outside_the_tolerance_window(): void
    {
        $old = (string) (self::NOW - 301);
        $future = (string) (self::NOW + 301);
        $edge = (string) (self::NOW - 300);

        self::assertFalse(self::verifier()->verify($old, 'tok', self::sign($old, 'tok')));
        self::assertFalse(self::verifier()->verify($future, 'tok', self::sign($future, 'tok')));
        self::assertTrue(self::verifier()->verify($edge, 'tok', self::sign($edge, 'tok')));
    }

    public function test_rejects_without_a_key_or_with_a_non_numeric_timestamp(): void
    {
        self::assertFalse((new MailgunSignature('', 300, fn (): int => self::NOW))->verify((string) self::NOW, 'tok', hash_hmac('sha256', self::NOW . 'tok', '')));
        self::assertFalse(self::verifier()->verify('12a', 'tok', self::sign('12a', 'tok')));
    }

    public function test_defaults_to_the_system_clock(): void
    {
        $now = (string) time();

        self::assertTrue((new MailgunSignature(self::KEY))->verify($now, 'tok', self::sign($now, 'tok')));
    }

    /**
     * @return MailgunSignature
     */
    private static function verifier(): MailgunSignature
    {
        return new MailgunSignature(self::KEY, 300, fn (): int => self::NOW);
    }

    /**
     * @param string $timestamp
     * @param string $token
     *
     * @return string
     */
    private static function sign(string $timestamp, string $token): string
    {
        return hash_hmac('sha256', $timestamp . $token, self::KEY);
    }
}
