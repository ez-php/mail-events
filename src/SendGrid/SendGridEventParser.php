<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\SendGrid;

use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\MailEventType;
use EzPhp\MailEvents\Payload;

/**
 * Class SendGridEventParser
 *
 * Turns one entry of a SendGrid Event Webhook batch into a MailEvent.
 * `bounce` covers both hard bounces and blocks (`type` = `bounce`/`blocked`);
 * both map to Bounced.
 *
 * @package EzPhp\MailEvents\SendGrid
 */
final class SendGridEventParser
{
    /**
     * @param array<array-key, mixed> $entry One element of the webhook's JSON array.
     *
     * @throws MailEventsException When event, email or timestamp is missing.
     *
     * @return MailEvent
     */
    public function parse(array $entry): MailEvent
    {
        $event = Payload::requireString($entry, 'event');

        /** @var array<string, mixed> $entry */
        return new MailEvent(
            provider: 'sendgrid',
            type: self::type($event),
            providerEvent: $event,
            recipient: Payload::requireString($entry, 'email'),
            occurredAt: Payload::requireTime($entry, 'timestamp'),
            eventId: Payload::string($entry, 'sg_event_id'),
            messageId: Payload::string($entry, 'sg_message_id'),
            reason: Payload::string($entry, 'reason') ?? Payload::string($entry, 'response'),
            url: Payload::string($entry, 'url'),
            payload: $entry,
        );
    }

    /**
     * @param string $event
     *
     * @return MailEventType
     */
    private static function type(string $event): MailEventType
    {
        return match ($event) {
            'delivered' => MailEventType::Delivered,
            'bounce' => MailEventType::Bounced,
            'deferred' => MailEventType::Deferred,
            'dropped' => MailEventType::Dropped,
            'spamreport' => MailEventType::Complained,
            'open' => MailEventType::Opened,
            'click' => MailEventType::Clicked,
            'unsubscribe', 'group_unsubscribe' => MailEventType::Unsubscribed,
            default => MailEventType::Other,
        };
    }
}
