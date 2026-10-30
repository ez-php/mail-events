<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\Ses;

use EzPhp\MailEvents\MailEvent;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\MailEventType;
use EzPhp\MailEvents\Payload;

/**
 * Class SesEventParser
 *
 * Maps one Amazon SES event (configuration-set event publishing, `eventType`, or
 * the older identity notifications, `notificationType`) onto one MailEvent per
 * affected recipient: Delivery, Bounce (Transient = Deferred), Complaint, Reject,
 * DeliveryDelay, Open, Click, Subscription. The event id is the SNS MessageId plus
 * the recipient, so each per-recipient event can be de-duplicated on its own.
 *
 * @package EzPhp\MailEvents\Ses
 */
final class SesEventParser
{
    /**
     * @param array<array-key, mixed> $event     The decoded SES event (SNS `Message`).
     * @param string|null             $messageId The SNS MessageId.
     *
     * @return list<MailEvent>
     * @throws MailEventsException When the event lacks its type or recipients.
     */
    public function parse(array $event, ?string $messageId = null): array
    {
        $kind = Payload::string($event, 'eventType') ?? Payload::requireString($event, 'notificationType');
        $section = lcfirst($kind === 'DeliveryDelay' ? 'deliveryDelay' : $kind);

        [$type, $recipients, $reason] = match ($kind) {
            'Bounce' => [
                Payload::string($event, 'bounce', 'bounceType') === 'Transient' ? MailEventType::Deferred : MailEventType::Bounced,
                self::addresses($event, 'bounce', 'bouncedRecipients'),
                Payload::string($event, 'bounce', 'bouncedRecipients', '0', 'diagnosticCode') ?? Payload::string($event, 'bounce', 'bounceSubType'),
            ],
            'Complaint' => [MailEventType::Complained, self::addresses($event, 'complaint', 'complainedRecipients'), Payload::string($event, 'complaint', 'complaintFeedbackType')],
            'DeliveryDelay' => [MailEventType::Deferred, self::addresses($event, 'deliveryDelay', 'delayedRecipients'), Payload::string($event, 'deliveryDelay', 'delayType')],
            'Delivery' => [MailEventType::Delivered, self::list($event, 'delivery', 'recipients'), Payload::string($event, 'delivery', 'smtpResponse')],
            'Reject' => [MailEventType::Dropped, self::list($event, 'mail', 'destination'), Payload::string($event, 'reject', 'reason')],
            'Open' => [MailEventType::Opened, self::list($event, 'mail', 'destination'), null],
            'Click' => [MailEventType::Clicked, self::list($event, 'mail', 'destination'), null],
            'Subscription' => [MailEventType::Unsubscribed, self::list($event, 'mail', 'destination'), null],
            default => [MailEventType::Other, self::list($event, 'mail', 'destination'), null],
        };

        if ($recipients === []) {
            throw new MailEventsException(sprintf('SES %s event lists no recipient.', $kind));
        }

        $time = Payload::string($event, $section, 'timestamp') !== null
            ? Payload::requireIsoTime($event, $section, 'timestamp')
            : Payload::requireIsoTime($event, 'mail', 'timestamp');

        $events = [];

        foreach ($recipients as $recipient) {
            $events[] = new MailEvent(
                provider: 'ses',
                type: $type,
                providerEvent: $kind,
                recipient: $recipient,
                occurredAt: $time,
                eventId: $messageId !== null ? $messageId . ':' . $recipient : null,
                messageId: Payload::string($event, 'mail', 'messageId'),
                reason: $reason,
                url: Payload::string($event, 'click', 'link'),
                payload: $event,
            );
        }

        return $events;
    }

    /**
     * `emailAddress` of each entry in a recipient-object list.
     *
     * @param array<array-key, mixed> $event
     * @param string                  ...$path
     *
     * @return list<string>
     */
    private static function addresses(array $event, string ...$path): array
    {
        $entries = Payload::get($event, ...$path);
        $addresses = [];

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (is_array($entry) && is_string($entry['emailAddress'] ?? null) && $entry['emailAddress'] !== '') {
                $addresses[] = $entry['emailAddress'];
            }
        }

        return $addresses;
    }

    /**
     * @param array<array-key, mixed> $event
     * @param string                  ...$path
     *
     * @return list<string>
     */
    private static function list(array $event, string ...$path): array
    {
        $values = Payload::get($event, ...$path);

        return is_array($values) ? array_values(array_filter($values, static fn (mixed $v): bool => is_string($v) && $v !== '')) : [];
    }
}
