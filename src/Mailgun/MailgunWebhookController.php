<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\Mailgun;

use EzPhp\Events\EventDispatcher;
use EzPhp\Http\Request;
use EzPhp\Http\Response;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\Payload;

/**
 * Class MailgunWebhookController
 *
 * Endpoint for Mailgun webhooks (one event per request, JSON body): verifies
 * the signature, parses the event and dispatches it. Answers 401 for a bad or
 * stale signature, 406 for an unparseable body (Mailgun stops retrying on
 * 406), 200 once listeners ran.
 *
 * @package EzPhp\MailEvents\Mailgun
 */
final readonly class MailgunWebhookController
{
    /**
     * @param MailgunSignature   $signature
     * @param MailgunEventParser $parser
     * @param EventDispatcher    $events
     */
    public function __construct(
        private MailgunSignature $signature,
        private MailgunEventParser $parser,
        private EventDispatcher $events,
    ) {
    }

    /**
     * @param Request $request
     *
     * @return Response
     */
    public function __invoke(Request $request): Response
    {
        try {
            $body = Payload::decode($request->rawBody());
        } catch (MailEventsException) {
            return new Response('Invalid payload.', 406);
        }

        $verified = $this->signature->verify(
            Payload::string($body, 'signature', 'timestamp') ?? '',
            Payload::string($body, 'signature', 'token') ?? '',
            Payload::string($body, 'signature', 'signature') ?? '',
        );

        if (!$verified) {
            return new Response('Invalid signature.', 401);
        }

        try {
            $event = $this->parser->parse($body);
        } catch (MailEventsException) {
            return new Response('Invalid payload.', 406);
        }

        $this->events->dispatch($event);

        return new Response('OK');
    }
}
