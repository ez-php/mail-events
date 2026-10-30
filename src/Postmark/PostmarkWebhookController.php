<?php

declare(strict_types=1);

namespace EzPhp\MailEvents\Postmark;

use EzPhp\Events\EventDispatcher;
use EzPhp\Http\Request;
use EzPhp\Http\Response;
use EzPhp\MailEvents\MailEventsException;
use EzPhp\MailEvents\Payload;

/**
 * Class PostmarkWebhookController
 *
 * Receives one Postmark webhook: 401 without valid Basic credentials, 400 for a
 * body that is not a parseable event (Postmark does not retry 4xx), otherwise the
 * MailEvent is dispatched and 200 returned.
 *
 * @package EzPhp\MailEvents\Postmark
 */
final readonly class PostmarkWebhookController
{
    /**
     * @param PostmarkAuthenticator $authenticator
     * @param PostmarkEventParser   $parser
     * @param EventDispatcher       $events
     */
    public function __construct(
        private PostmarkAuthenticator $authenticator,
        private PostmarkEventParser $parser,
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
        $authorization = $request->header('authorization');

        if (!$this->authenticator->verify(is_string($authorization) ? $authorization : null)) {
            return (new Response('Unauthorized.', 401))->withHeader('WWW-Authenticate', 'Basic realm="mail-events"');
        }

        try {
            $event = $this->parser->parse(Payload::decode($request->rawBody()));
        } catch (MailEventsException) {
            return new Response('Invalid payload.', 400);
        }

        $this->events->dispatch($event);

        return new Response('OK');
    }
}
