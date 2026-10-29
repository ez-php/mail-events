<?php

declare(strict_types=1);

namespace Tests\SendGrid;

use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\MailEventType;
use EzPhp\MailEvents\Payload;
use EzPhp\MailEvents\SendGrid\SendGridEventParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class SendGridEventParserTest
 *
 * @package Tests\SendGrid
 */
#[CoversClass(SendGridEventParser::class)]
#[UsesClass(MailEvent::class)]
#[UsesClass(Payload::class)]
final class SendGridEventParserTest extends TestCase
{
    public function test_maps_a_bounce_with_all_fields(): void
    {
        $event = (new SendGridEventParser())->parse([
            'email' => 'bob@example.com',
            'timestamp' => 1700000000,
            'event' => 'bounce',
            'type' => 'blocked',
            'reason' => '550 5.7.1 blocked',
            'sg_event_id' => 'sg-evt',
            'sg_message_id' => 'sg-msg.filter0001',
        ]);

        self::assertSame('sendgrid', $event->provider);
        self::assertSame(MailEventType::Bounced, $event->type);
        self::assertSame('bounce', $event->providerEvent);
        self::assertSame('bob@example.com', $event->recipient);
        self::assertSame(1700000000, $event->occurredAt->getTimestamp());
        self::assertSame('sg-evt', $event->eventId);
        self::assertSame('sg-msg.filter0001', $event->messageId);
        self::assertSame('550 5.7.1 blocked', $event->reason);
        self::assertSame('blocked', $event->payload['type']);
    }

    /**
     * @return array<string, array{string, MailEventType}>
     */
    public static function events(): array
    {
        return [
            'delivered' => ['delivered', MailEventType::Delivered],
            'deferred' => ['deferred', MailEventType::Deferred],
            'dropped' => ['dropped', MailEventType::Dropped],
            'spamreport' => ['spamreport', MailEventType::Complained],
            'open' => ['open', MailEventType::Opened],
            'click' => ['click', MailEventType::Clicked],
            'unsubscribe' => ['unsubscribe', MailEventType::Unsubscribed],
            'group_unsubscribe' => ['group_unsubscribe', MailEventType::Unsubscribed],
            'processed' => ['processed', MailEventType::Other],
        ];
    }

    #[DataProvider('events')]
    public function test_maps_event_types(string $name, MailEventType $expected): void
    {
        $event = (new SendGridEventParser())->parse([
            'email' => 'a@example.com',
            'timestamp' => 1,
            'event' => $name,
            'url' => 'https://example.com/x',
            'response' => '451 try later',
        ]);

        self::assertSame($expected, $event->type);
        self::assertSame('https://example.com/x', $event->url);
        self::assertSame('451 try later', $event->reason);
    }

    public function test_rejects_incomplete_entries(): void
    {
        $this->expectException(MailEventsException::class);

        (new SendGridEventParser())->parse(['event' => 'delivered', 'email' => 'a@example.com', 'timestamp' => 'yesterday']);
    }
}
