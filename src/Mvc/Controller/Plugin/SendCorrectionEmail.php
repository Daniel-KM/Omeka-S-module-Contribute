<?php
namespace Correction\Mvc\Controller\Plugin;

use Omeka\Stdlib\Message;
use Omeka\Stdlib\Mailer as MailerService;
use Zend\Log\Logger;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;

/**
 * Send an email.
 */
class SendCorrectionEmail extends AbstractPlugin
{
    /**
     * @var MailerService
     */
    protected $mailer;

    /**
     * @var Logger
     */
    protected $logger;

    /**
     * @param MailerService $mailer
     * @param Logger $logger
     */
    public function __construct(MailerService $mailer, Logger $logger)
    {
        $this->mailer = $mailer;
        $this->logger = $logger;
    }

    /**
     * Send an email to a list of recipients (no check done), and get response.
     *
     * @param array|string $recipients
     * @param string $subject
     * @param string $body
     * @param string $name
     * @return bool|string True, or a message in case of error.
     */
    public function __invoke($recipients, $subject, $body, $name = null)
    {
        if (!is_array($recipients)) {
            $recipients = [$recipients];
        }

        $recipients = array_filter(array_unique(array_map('trim', $recipients)));
        if (empty($recipients)) {
            return new Message('The message has no recipient.'); // @translate
        }
        $subject = trim($subject);
        if (empty($subject)) {
            return new Message('The message has no subject.'); // @translate
        }
        $body = trim($body);
        if (empty($body)) {
            return new Message('The message has no content.'); // @translate
        }

        $mailer = $this->mailer;
        $message = $mailer->createMessage();

        $isHtml = strpos($body, '<') === 0;
        if ($isHtml) {
            // Full html.
            if (strpos($body, '<!DOCTYPE') === 0 || strpos($body, '<html') === 0) {
                $boundary = substr(str_replace(['+', '/', '='], '', base64_encode(uniqid() . uniqid())), 0, 20);
                $message->getHeaders()
                    ->addHeaderLine('MIME-Version: 1.0')
                    ->addHeaderLine('Content-Type: multipart/alternative; boundary=' . $boundary);
                $raw = strip_tags($body);
                $body = <<<BODY
--$boundary
Content-Transfer-Encoding: quoted-printable
Content-Type: text/plain; charset=UTF-8
MIME-Version: 1.0

$raw

--$boundary
Content-Transfer-Encoding: quoted-printable
Content-Type: text/html; charset=UTF-8
MIME-Version: 1.0

$body

--$boundary--
BODY;
            }
            // Partial html.
            else {
                $message->getHeaders()
                    ->addHeaderLine('MIME-Version: 1.0')
                    ->addHeaderLine('Content-Type: text/html; charset=UTF-8');
            }
        }

        $message
            ->addTo($recipients, $name)
            ->setSubject($subject)
            ->setBody($body);

        try {
            $mailer->send($message);
            // Log email sent for security purpose.
            $msg = new Message(
                'A mail was sent to %1$s with subject: %2$s', // @translate
                implode(', ', $recipients), $subject
            );
            $this->logger->info($msg->getMessage());
            return true;
        } catch (\Exception $e) {
            $this->logger->err((string) $e);
            return (string) $e;
        }
    }
}
