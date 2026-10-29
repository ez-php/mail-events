<?php

declare(strict_types=1);

namespace Tests\Mailgun;

use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\MailEventType;
use EzPhp\MailEvents\Mailgun\MailgunEventParser;
use EzPhp\MailEvents\Payload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class MailgunEventParserTest
 *
 * @package Tests\Mailgun
 */
#[CoversClass(MailgunEventParser::class)]
#[CoversClass(Payload::class)]
#[UsesClass(MailEvent::class)]
final class MailgunEventParserTest extends TestCase
{
    public function test_maps_a_permanent_failure_with_all_fields(): void
    {
        $event = (new MailgunEventParser())->parse(['event-data' => [
            'event' => 'failed',
            'severity' => 'permanent',
            'recipient' => 'alice@example.com',
            'timestamp' => 1700000000.123,
            'id' => 'evt-1',
            'message' => ['headers' => ['message-id' => '20231114.1@mg.example.com']],
            'delivery-status' => ['description' => 'mailbox does not exist', 'message' => '550 5.1.1'],
        ]]);

        self::assertSame('mailgun', $event->provider);
        self::assertSame(MailEventType::Bounced, $event->type);
        self::assertSame('failed', $event->providerEvent);
        self::assertSame('alice@example.com', $event->recipient);
        self::assertSame(1700000000, $event->occurredAt->getTimestamp());
        self::assertSame('evt-1', $event->eventId);
        self::assertSame('20231114.1@mg.example.com', $event->messageId);
        self::assertSame('mailbox does not exist', $event->reason);
        self::assertNull($event->url);
        self::assertSame('permanent', $event->payload['severity']);
    }

    /**
     * @return array<string, array{string, string|null, MailEventType}>
     */
    public static function events(): array
    {
        return [
            'delivered' => ['delivered', null, MailEventType::Delivered],
            'temporary failure' => ['failed', 'temporary', MailEventType::Deferred],
            'failure without severity' => ['failed', null, MailEventType::Bounced],
            'rejected' => ['rejected', null, MailEventType::Dropped],
            'complained' => ['complained', null, MailEventType::Complained],
            'opened' => ['opened', null, MailEventType::Opened],
            'clicked' => ['clicked', null, MailEventType::Clicked],
            'unsubscribed' => ['unsubscribed', null, MailEventType::Unsubscribed],
            'accepted' => ['accepted', null, MailEventType::Other],
        ];
    }

    #[DataProvider('events')]
    public function test_maps_event_types(string $name, ?string $severity, MailEventType $expected): void
    {
        $data = ['event' => $name, 'recipient' => 'a@example.com', 'timestamp' => '1700000000', 'url' => 'https://example.com/x', 'reason' => 'old'];

        if ($severity !== null) {
            $data['severity'] = $severity;
        }

        $event = (new MailgunEventParser())->parse(['event-data' => $data]);

        self::assertSame($expected, $event->type);
        self::assertSame('https://example.com/x', $event->url);
        self::assertSame('old', $event->reason);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidBodies(): array
    {
        return [
            'no event-data' => [['signature' => []]],
            'no event' => [['event-data' => ['recipient' => 'a@example.com', 'timestamp' => 1]]],
            'no recipient' => [['event-data' => ['event' => 'delivered', 'timestamp' => 1]]],
            'no timestamp' => [['event-data' => ['event' => 'delivered', 'recipient' => 'a@example.com']]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('invalidBodies')]
    public function test_rejects_incomplete_payloads(array $body): void
    {
        $this->expectException(MailEventsException::class);

        (new MailgunEventParser())->parse($body);
    }
}
