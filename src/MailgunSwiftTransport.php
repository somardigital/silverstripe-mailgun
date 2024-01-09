<?php

namespace LeKoala\Mailgun;

use \Exception;
use \Swift_MimePart;
use \Swift_Transport;
use \Swift_Attachment;
use \Swift_Mime_Message;
use \Swift_Events_SendEvent;
use \Swift_Events_EventListener;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Director;
use SilverStripe\Assets\FileNameFilter;
use SilverStripe\Core\Injector\Injector;
use Mailgun\Mailgun;

/**
 * A Mailgun transport for Swift Mailer using the official client
 *
 * @author LeKoala <thomas@lekoala.be>
 */
class MailgunSwiftTransport implements Swift_Transport
{
    /**
     * @var Swift_Transport_SimpleMailInvoker
     */
    protected $invoker;

    /**
     * @var Swift_Events_SimpleEventDispatcher
     */
    protected $eventDispatcher;

    /**
     * @var Mailgun
     */
    protected $client;

    /**
     * @var array
     */
    protected $resultApi;

    /**
     * @var string
     */
    protected $fromEmail;

    /**
     * @var boolean
     */
    protected $isStarted = false;

    public function __construct(Mailgun $client)
    {
        $this->client = $client;

        $this->invoker = new \Swift_Transport_SimpleMailInvoker();
        $this->eventDispatcher = new \Swift_Events_SimpleEventDispatcher();
    }

    /**
     * Not used
     */
    public function isStarted()
    {
        return $this->isStarted;
    }

    /**
     * Not used
     */
    public function start()
    {
        $this->isStarted = true;
    }

    /**
     * Not used
     */
    public function stop()
    {
        $this->isStarted = false;
    }

    /**
     * Not used
     */
    public function ping()
    {
        return true;
    }

    /**
     * @param Swift_Mime_Message $message
     * @param null $failedRecipients
     * @return int Number of messages sent
     */
    public function send(Swift_Mime_Message $message, &$failedRecipients = null)
    {
        $this->resultApi = null;
        if ($event = $this->eventDispatcher->createSendEvent($this, $message)) {
            $this->eventDispatcher->dispatchEvent(
                $event,
                'beforeSendPerformed'
            );
            if ($event->bubbleCancelled()) {
                return 0;
            }
        }

        $sendCount = 0;
        $disableSending =
            $message->getHeaders()->has('X-SendingDisabled') ||
            MailgunHelper::config()->disable_sending;

        $emailData = $this->buildMessage($message);

        $client = $this->client;

        if ($disableSending) {
            $result = [
                'message' => 'Disabled',
                'id' => uniqid(),
            ];
            $queued = true;
        } else {
            $resultResponse = $client
                ->messages()
                ->send(MailgunHelper::getDomain(), $emailData);
            if ($resultResponse) {
                $result = [
                    'message' => $resultResponse->getMessage(),
                    'id' => $resultResponse->getId(),
                ];
                $queued = strpos($result['message'], 'Queued') !== false;
            }
        }
        $this->resultApi = $result;

        if (MailgunHelper::config()->enable_logging) {
            $this->logMessageContent($message, $result);
        }

        // Mailgun does not tell us how many messages are sent
        $sendCount = 1;

        // We don't know which recipients failed, so simply add fromEmail since it's the only one we know
        if (!$queued) {
            $failedRecipients[] = $this->fromEmail;
            $sendCount = 0;
        }

        if ($event) {
            if ($sendCount > 0) {
                $event->setResult(Swift_Events_SendEvent::RESULT_SUCCESS);
            } else {
                $event->setResult(Swift_Events_SendEvent::RESULT_FAILED);
            }

            $this->eventDispatcher->dispatchEvent($event, 'sendPerformed');
        }

        return $sendCount;
    }

    /**
     * Log message content
     *
     * @param Swift_Mime_SimpleMessage $message
     * @param array $results Results from the api
     * @return void
     */
    protected function logMessageContent(
        Swift_Mime_SimpleMessage $message,
        $results = []
    ) {
        $subject = $message->getSubject();
        $body = $message->getBody();
        $contentType = $this->getMessagePrimaryContentType($message);

        $logContent = $body;

        // Append some extra information at the end
        $logContent .= '<hr><pre>Debug infos:' . "\n\n";
        $logContent .= 'To : ' . print_r($message->getTo(), true) . "\n";
        $logContent .= 'Subject : ' . $subject . "\n";
        $logContent .= 'From : ' . print_r($message->getFrom(), true) . "\n";
        $logContent .= 'Headers:' . "\n";
        foreach ($message->getHeaders()->getAll() as $header) {
            $logContent .=
                '  ' .
                $header->getFieldName() .
                ': ' .
                $header->getFieldBody() .
                "\n";
        }
        if (!empty($message->getTo())) {
            $logContent .=
                'Recipients : ' . print_r($message->getTo(), true) . "\n";
        }
        $logContent .= 'Results:' . "\n";
        foreach ($results as $resultKey => $resultValue) {
            $logContent .= '  ' . $resultKey . ': ' . $resultValue . "\n";
        }
        $logContent .= '</pre>';

        $logFolder = MailgunHelper::getLogFolder();

        // Generate filename
        $filter = new FileNameFilter();
        $title = substr($filter->filter($subject), 0, 35);
        $logName = date('Ymd_His') . '_' . $title;

        // Store attachments if any
        $attachments = $message->getChildren();
        if (!empty($attachments)) {
            $logContent .= '<hr />';
            foreach ($attachments as $attachment) {
                if ($attachment instanceof Swift_Attachment) {
                    $attachmentDestination =
                        $logFolder .
                        '/' .
                        $logName .
                        '_' .
                        $attachment->getFilename();
                    file_put_contents(
                        $attachmentDestination,
                        $attachment->getBody()
                    );
                    $logContent .=
                        'File : <a href="' .
                        $attachmentDestination .
                        '">' .
                        $attachment->getFilename() .
                        '</a><br/>';
                }
            }
        }

        // Store it
        $ext = $contentType == 'text/html' ? 'html' : 'txt';
        $r = file_put_contents(
            $logFolder . '/' . $logName . '.' . $ext,
            $logContent
        );

        if (!$r && Director::isDev()) {
            throw new Exception('Failed to store email in ' . $logFolder);
        }
    }

    /**
     * @return LoggerInterface
     */
    public function getLogger()
    {
        return Injector::inst()
            ->get(LoggerInterface::class)
            ->withName('Mailgun');
    }

    /**
     * @param Swift_Events_EventListener $plugin
     */
    public function registerPlugin(Swift_Events_EventListener $plugin)
    {
        $this->eventDispatcher->bindEventListener($plugin);
    }

    /**
     * @return array
     */
    protected function getSupportedContentTypes()
    {
        return ['text/plain', 'text/html'];
    }

    /**
     * @param string $contentType
     * @return bool
     */
    protected function supportsContentType($contentType)
    {
        return in_array($contentType, $this->getSupportedContentTypes());
    }

    /**
     * @param Swift_Mime_SimpleMessage $message
     * @return string
     */
    protected function getMessagePrimaryContentType(
        Swift_Mime_SimpleMessage $message
    ) {
        $contentType = $message->getContentType();

        if ($this->supportsContentType($contentType)) {
            return $contentType;
        }

        // SwiftMailer hides the content type set in the constructor of Swift_Mime_SimpleMessage as soon
        // as you add another part to the message. We need to access the protected property
        // _userContentType to get the original type.
        $messageRef = new \ReflectionClass($message);
        if ($messageRef->hasProperty('_userContentType')) {
            $propRef = $messageRef->getProperty('_userContentType');
            $propRef->setAccessible(true);
            $contentType = $propRef->getValue($message);
        }

        return $contentType;
    }

    /**
     * Convert a Swift Message for the api
     *
     * @param Swift_Mime_SimpleMessage $message
     * @return array Mailgun Send Message
     * @throws \Swift_SwiftException
     */
    public function buildMessage(Swift_Mime_SimpleMessage $message)
    {
        $contentType = $this->getMessagePrimaryContentType($message);

        $fromAddresses = $message->getFrom();
        $fromEmails = array_keys($fromAddresses);
        $fromFirstEmail = key($fromAddresses);
        $fromFirstName = current($fromAddresses);

        if ($fromFirstName) {
            $this->fromEmail = sprintf(
                '%s <%s>',
                $fromFirstName,
                $fromFirstEmail
            );
        } else {
            $this->fromEmail = $fromFirstEmail;
        }

        $toAddresses = $message->getTo();
        $ccAddresses = $message->getCc() ? $message->getCc() : [];
        $bccAddresses = $message->getBcc() ? $message->getBcc() : [];
        $replyToAddresses = $message->getReplyTo()
            ? $message->getReplyTo()
            : [];

        $recipients = [];
        $cc = [];
        $bcc = [];
        $attachments = [];
        $headers = [];
        $tags = [];
        $metadata = [];
        $mergeVars = [];
        $inlineCss = null;

        // Mandrill compatibility
        // Data is merge with transmission and removed from headers
        // @link https://mandrill.zendesk.com/hc/en-us/articles/205582467-How-to-Use-Tags-in-Mandrill
        if ($message->getHeaders()->has('X-MC-Tags')) {
            $tagsHeader = $message->getHeaders()->get('X-MC-Tags');
            $tags = explode(',', $tagsHeader->getValue());
            $message->getHeaders()->remove('X-MC-Tags');
        }
        if ($message->getHeaders()->has('X-MC-Metadata')) {
            $metadataHeader = $message->getHeaders()->get('X-MC-Metadata');
            $metadata = json_decode(
                $metadataHeader->getValue(),
                JSON_OBJECT_AS_ARRAY
            );
            $message->getHeaders()->remove('X-MC-Metadata');
        }
        if ($message->getHeaders()->has('X-MC-InlineCSS')) {
            $inlineCss = $message
                ->getHeaders()
                ->get('X-MC-InlineCSS')
                ->getValue();
            $message->getHeaders()->remove('X-MC-InlineCSS');
        }
        if ($message->getHeaders()->has('X-MC-MergeVars')) {
            $mergeVarsHeader = $message->getHeaders()->get('X-MC-MergeVars');
            $mergeVarsFromMC = json_decode(
                $mergeVarsHeader->getValue(),
                JSON_OBJECT_AS_ARRAY
            );
            // We need to transform them to a mandrill friendly format rcpt => vars, to email : {...}
            foreach ($mergeVarsFromMC as $row) {
                $mergeVars[$row['rcpt']] = $row['vars'];
            }
            $message->getHeaders()->remove('X-MC-MergeVars');
        }

        // Handle mailgun headers
        // Data is merge with message and removed from headers
        // @link https://documentation.mailgun.com/en/latest/user_manual.html#sending-via-smtp
        if ($message->getHeaders()->has('X-Mailgun-Tag')) {
            $tagsHeader = $message->getHeaders()->get('X-Mailgun-Tag');
            $tags = explode(',', $tagsHeader->getValue());
            $message->getHeaders()->remove('X-Mailgun-Tag');
        }
        // @link https://documentation.mailgun.com/en/latest/user_manual.html#attaching-data-to-messages
        if ($message->getHeaders()->has('X-Mailgun-Variables')) {
            $metadataHeader = $message
                ->getHeaders()
                ->get('X-Mailgun-Variables');
            $metadata = json_decode(
                $metadataHeader->getValue(),
                JSON_OBJECT_AS_ARRAY
            );
            $message->getHeaders()->remove('X-Mailgun-Variables');
        }
        if ($message->getHeaders()->has('X-Mailgun-Recipient-Variables')) {
            $recipientVariablesHeader = $message
                ->getHeaders()
                ->get('X-Mailgun-Recipient-Variables');
            $mergeVars = json_decode(
                $recipientVariablesHeader->getValue(),
                JSON_OBJECT_AS_ARRAY
            );
            $message->getHeaders()->remove('X-Mailgun-Recipient-Variables');
        }

        // Build recipients
        $primaryEmail = null;
        foreach ($toAddresses as $toEmail => $toName) {
            if ($primaryEmail === null) {
                $primaryEmail = $toEmail;
            }
            if ($toName) {
                $recipients[] = sprintf('%s <%s>', $toName, $toEmail);
            } else {
                $recipients[] = $toEmail;
            }
        }

        $reply_to = null;
        foreach ($replyToAddresses as $replyToEmail => $replyToName) {
            if ($replyToName) {
                $reply_to = sprintf('%s <%s>', $replyToName, $replyToEmail);
            } else {
                $reply_to = $replyToEmail;
            }
        }

        foreach ($ccAddresses as $ccEmail => $ccName) {
            if ($ccName) {
                $cc[] = sprintf('%s <%s>', $ccName, $ccEmail);
            } else {
                $cc[] = $ccEmail;
            }
        }

        foreach ($bccAddresses as $bccEmail => $bccName) {
            if ($bccName) {
                $bcc[] = sprintf('%s <%s>', $bccName, $bccEmail);
            } else {
                $bcc[] = $bccEmail;
            }
        }

        $bodyHtml = $bodyText = null;

        if ($contentType === 'text/plain') {
            $bodyText = $message->getBody();
        } elseif ($contentType === 'text/html') {
            $bodyHtml = $message->getBody();
        } else {
            $bodyHtml = $message->getBody();
        }

        foreach ($message->getChildren() as $child) {
            // File attachment. You can post multiple attachment values.
            // Important: You must use multipart/form-data encoding when sending attachments.
            if ($child instanceof Swift_Attachment) {
                $attachment = [
                    'filename' => $child->getFilename(),
                    'fileContent' => $child->getBody(),
                ];
                $attachments[] = $attachment;
            } elseif (
                $child instanceof Swift_MimePart &&
                $this->supportsContentType($child->getContentType())
            ) {
                if ($child->getContentType() == 'text/html') {
                    $bodyHtml = $child->getBody();
                } elseif ($child->getContentType() == 'text/plain') {
                    $bodyText = $child->getBody();
                }
            }
        }

        // If we ask to provide plain, use our custom method instead of the provided one
        if ($bodyHtml && MailgunHelper::config()->provide_plain) {
            $bodyText = EmailUtils::convert_html_to_text($bodyHtml);
        }

        // Should we inline css
        if (!$inlineCss && MailgunHelper::config()->inline_styles && $bodyHtml) {
            $bodyHtml = EmailUtils::inline_styles($bodyHtml);
        }

        // Custom unsubscribe list
        if ($message->getHeaders()->has('List-Unsubscribe')) {
            $headers['List-Unsubscribe'] = $message
                ->getHeaders()
                ->get('List-Unsubscribe')
                ->getValue();
        }

        // Mailgun params format does not work well in yml, so we map them
        $rawParams = MailgunHelper::config()->default_params;
        $defaultParams = [];
        foreach ($rawParams as $rawParamKey => $rawParamValue) {
            switch ($rawParamKey) {
                case 'inline':
                    $defaultParams['inline'] = $rawParamValue;
                    break;
                case 'tracking_opens':
                    $defaultParams['o:tracking-opens'] = $rawParamValue;
                    break;
                case 'tracking_clicks':
                    $defaultParams['o:tracking-clicks'] = $rawParamValue;
                    break;
                case 'testmode':
                    $defaultParams['o:testmode'] = $rawParamValue;
                    break;
                default:
                    $defaultParams[$rawParamKey] = $rawParamValue;
                    break;
            }
        }

        // Build base transmission
        $mailgunMessage = [
            'to' => implode(',', $recipients),
            'from' => $this->fromEmail,
            'subject' => $message->getSubject(),
            'html' => $bodyHtml,
            'text' => $bodyText,
        ];
        if ($reply_to) {
            $mailgunMessage['h:Reply-To'] = $reply_to;
        }

        // Add default params
        $mailgunMessage = array_merge($defaultParams, $mailgunMessage);

        // Add remaining elements
        if (!empty($cc)) {
            $mailgunMessage['cc'] = $cc;
        }
        if (!empty($bcc)) {
            $mailgunMessage['bcc'] = $bcc;
        }
        if (!empty($mergeVars)) {
            $mailgunMessage['recipient-variables'] = json_encode($mergeVars);
        }
        if (!empty($headers)) {
            foreach ($headers as $headerKey => $headerValue) {
                $mailgunMessage['h:' . $headerKey] = $headerValue;
            }
        }
        if (count($attachments) > 0) {
            $mailgunMessage['attachment'] = $attachments;
        }

        return $mailgunMessage;
    }

    /**
     * @return null|array
     */
    public function getResultApi()
    {
        return $this->resultApi;
    }
}
