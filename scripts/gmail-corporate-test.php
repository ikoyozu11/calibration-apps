<?php

declare(strict_types=1);

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

require dirname(__DIR__).'/vendor/autoload.php';

const SENDER = 'instrumentsdesk.vt@gmail.com';
const FROM_NAME = 'Instruments Desk | Calibration Notification';
const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;

/** @var array<string, string> */
const RECIPIENTS = [
    'RECIPIENT_A' => 'service.malang@vision-teknik.com',
    'RECIPIENT_B' => 'Indra_Tri_Sutanto@bat.com',
];

function outputResult(string $key, string $value): void
{
    fwrite(STDOUT, $key.'='.$value.PHP_EOL);
}

function hasEncryptedTlsStream(EsmtpTransport $transport): bool
{
    try {
        $stream = $transport->getStream();
        $property = new ReflectionProperty($stream, 'stream');
        $property->setAccessible(true);
        $resource = $property->getValue($stream);

        if (! is_resource($resource)) {
            return false;
        }

        $metadata = stream_get_meta_data($resource);
        $crypto = $metadata['crypto'] ?? null;

        return is_array($crypto)
            && isset($crypto['protocol'])
            && $crypto['protocol'] !== '';
    } catch (Throwable) {
        return false;
    }
}

function reportNotStarted(string $errorCategory, string $tlsStatus = 'NOT_VERIFIED', string $authenticationStatus = 'NOT_ATTEMPTED'): never
{
    outputResult('RECIPIENT_A', RECIPIENTS['RECIPIENT_A']);
    outputResult('SMTP_RESULT_A', 'NOT_SENT');
    outputResult('SEND_ATTEMPTS_A', '0');
    outputResult('RECIPIENT_B', RECIPIENTS['RECIPIENT_B']);
    outputResult('SMTP_RESULT_B', 'NOT_SENT');
    outputResult('SEND_ATTEMPTS_B', '0');
    outputResult('TOTAL_SEND_ATTEMPTS', '0');
    outputResult('TLS_STATUS', $tlsStatus);
    outputResult('AUTHENTICATION_STATUS', $authenticationStatus);
    outputResult('ERROR_CATEGORY', $errorCategory);
    exit(1);
}

if (filter_var(SENDER, FILTER_VALIDATE_EMAIL) === false) {
    reportNotStarted('invalid_sender_configuration');
}

foreach (RECIPIENTS as $recipient) {
    if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
        reportNotStarted('invalid_recipient_configuration');
    }
}

$password = trim((string) stream_get_contents(STDIN));

if ($password === '') {
    reportNotStarted('missing_app_password');
}

$transport = null;
$results = [];
$totalAttempts = 0;

try {
    $dsn = new Dsn(
        'smtp',
        SMTP_HOST,
        SENDER,
        $password,
        SMTP_PORT,
        [
            'auto_tls' => 'true',
            'require_tls' => 'true',
            'verify_peer' => 'true',
            'local_domain' => 'localhost',
        ],
    );

    $transport = (new EsmtpTransportFactory)->create($dsn);

    if (! $transport instanceof EsmtpTransport || ! $transport->isAutoTls() || ! $transport->isTlsRequired()) {
        reportNotStarted('mandatory_starttls_not_enforced');
    }

    $transport->start();

    if (! hasEncryptedTlsStream($transport)) {
        reportNotStarted('tls_upgrade_not_verified');
    }

    foreach (RECIPIENTS as $label => $recipient) {
        $attempted = false;

        try {
            $email = (new Email)
                ->from(new Address(SENDER, FROM_NAME))
                ->to($recipient)
                ->subject('Instruments Desk - Email Delivery Test')
                ->text("Hello,\n\nThis is a test email from the Instruments Desk notification system.\n\nWe are verifying that automated notification emails can be delivered successfully to this mailbox.\n\nNo action is required.\n\nThis message contains no calibration data and is for testing purposes only.\n\nRegards,\nInstruments Desk\nCalibration Notification System");

            $attempted = true;
            $totalAttempts++;
            $sentMessage = $transport->send($email);

            $results[$label] = [
                'result' => $sentMessage instanceof SentMessage ? 'ACCEPTED_BY_SMTP' : 'UNKNOWN',
                'attempts' => '1',
                'error' => $sentMessage instanceof SentMessage ? 'NONE' : 'ambiguous_send_result',
            ];
        } catch (TransportExceptionInterface) {
            $results[$label] = [
                'result' => 'UNKNOWN',
                'attempts' => $attempted ? '1' : '0',
                'error' => 'smtp_transport_failure',
            ];
        } catch (Throwable) {
            $results[$label] = [
                'result' => 'FAILED',
                'attempts' => $attempted ? '1' : '0',
                'error' => 'smtp_test_failure',
            ];
        }
    }

    outputResult('RECIPIENT_A', RECIPIENTS['RECIPIENT_A']);
    outputResult('SMTP_RESULT_A', $results['RECIPIENT_A']['result']);
    outputResult('SEND_ATTEMPTS_A', $results['RECIPIENT_A']['attempts']);
    outputResult('RECIPIENT_B', RECIPIENTS['RECIPIENT_B']);
    outputResult('SMTP_RESULT_B', $results['RECIPIENT_B']['result']);
    outputResult('SEND_ATTEMPTS_B', $results['RECIPIENT_B']['attempts']);
    outputResult('TOTAL_SEND_ATTEMPTS', (string) $totalAttempts);
    outputResult('TLS_STATUS', 'VERIFIED');
    outputResult('AUTHENTICATION_STATUS', 'SUCCEEDED');
    outputResult('ERROR_CATEGORY', 'NONE');
} catch (TransportExceptionInterface) {
    $tlsStatus = $transport instanceof EsmtpTransport && hasEncryptedTlsStream($transport)
        ? 'VERIFIED'
        : 'UNKNOWN_OR_FAILED';
    reportNotStarted('smtp_transport_setup_failure', $tlsStatus, 'FAILED_OR_UNKNOWN');
} catch (Throwable) {
    reportNotStarted('smtp_test_setup_failure', 'UNKNOWN_OR_FAILED', 'FAILED_OR_UNKNOWN');
} finally {
    if ($transport instanceof EsmtpTransport) {
        $transport->stop();
    }

    $password = str_repeat("\0", strlen($password));
    unset($password);
}
