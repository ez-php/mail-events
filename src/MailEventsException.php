<?php

declare(strict_types=1);

namespace EzPhp\MailEvents;

use EzPhp\Contracts\EzPhpException;

/**
 * Class MailEventsException
 *
 * Thrown for a webhook payload that cannot be parsed, or a misconfigured key.
 *
 * @package EzPhp\MailEvents
 */
final class MailEventsException extends EzPhpException
{
}
