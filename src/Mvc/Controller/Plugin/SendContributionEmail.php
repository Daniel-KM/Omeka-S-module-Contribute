<?php declare(strict_types=1);

namespace Contribute\Mvc\Controller\Plugin;

use Laminas\Log\Logger;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Stdlib\Mailer as MailerService;
use Omeka\Stdlib\Message;

/**
 * Send an email.
 */
class SendContributionEmail extends AbstractPlugin
{
    /**
     * @var MailerService
     */
    protected $mailer;

    /**
     * @var Logger
     */
    protected $logger;

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
     * @return bool
     */
    public function __invoke($recipients, $subject, $body, $name = null)
    {
        if (!is_array($recipients)) {
            $recipients = [$recipients];
        }

        $subject = trim($subject);
        if (empty($subject)) {
            $message = new Message('Email not sent: the subject is missing.'); // @translate
            $this->logger->err($message);
            return false;
        }
        $body = trim($body);
        if (empty($body)) {
            $message = new Message('Email not sent: content is missing (subject: %1$s).', $subject); // @translate
            $this->logger->err($message);
            return false;
        }
        $recipients = array_filter(array_unique(array_map('trim', $recipients)));
        if (empty($recipients)) {
            $message = new Message('Email not sent: no recipient (subject: %1$s).', $subject); // @translate
            $this->logger->err($message);
            return false;
        }

        $message = $this->mailer->createMessage();

        $isHtml = strpos($body, '<') === 0;
        if ($isHtml) {
            // Full html.
            if (strpos($body, '<!DOCTYPE') === 0 || strpos($body, '<html') === 0) {
                $boundary = substr(str_replace(['+', '/', '='], ['', '', ''], base64_encode(random_bytes(128))), 0, 20);
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
            // Allows to pass the name with the recipient when alone.
            ->addTo(is_array($recipients) && count($recipients) === 1 ? reset($recipients) : $recipients, $name)
            ->setSubject($subject)
            ->setBody($body);

        try {
            $this->mailer->send($message);
        } catch (\Exception $e) {
            $this->logger->err((string) $e);
            return false;
        }

        // Log email sent for security purpose.
        $msg = new Message(
            'An email was sent to %1$s with subject: %2$s', // @translate
            implode(', ', $recipients), $subject
        );
        $this->logger->info($msg);
        return true;
    }
}
