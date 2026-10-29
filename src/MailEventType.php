<?php

declare(strict_types=1);

namespace EzPhp\MailEvents;

/**
 * Enum MailEventType
 *
 * Provider-neutral delivery event types.
 *
 * @package EzPhp\MailEvents
 */
enum MailEventType: string
{
    /** Accepted by the recipient's mail server. */
    case Delivered = 'delivered';

    /** Permanent delivery failure (hard bounce, block). Stop sending to this address. */
    case Bounced = 'bounced';

    /** Temporary delivery failure; the provider keeps retrying. */
    case Deferred = 'deferred';

    /** Not sent at all by the provider (suppressed address, invalid recipient). */
    case Dropped = 'dropped';

    /** The recipient marked the message as spam. */
    case Complained = 'complained';

    /** The recipient opened the message (tracking pixel). */
    case Opened = 'opened';

    /** The recipient clicked a tracked link (see MailEvent::$url). */
    case Clicked = 'clicked';

    /** The recipient unsubscribed via the provider's unsubscribe link. */
    case Unsubscribed = 'unsubscribed';

    /** Any other provider event (e.g. Mailgun `accepted`, SendGrid `processed`); see MailEvent::$providerEvent. */
    case Other = 'other';
}
