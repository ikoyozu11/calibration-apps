<?php

declare(strict_types=1);

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mime\Email;

require dirname(__DIR__).'/vendor/autoload.php';

const SENDER = 'instrumentsdesk.vt@gmail.com';
const RECIPIENT = 'instrumentsdesk.vt@gmail.com';
const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;

function outputResult(string $key, string $value): void
{
    fwrite(STDOUT, $key.'='.$value.PHP_EOL);
}

function stopWithoutSending(string $category): never
{
    outputResult('SMTP_HOST', SMTP_HOST);
    outputResult('SMTP_PORT', (string) SMTP_PORT);
    outputResult('TLS_STATUS', 'NOT_VERIFIED');
    outputResult('AUTHENTICATION', 'NOT_ATTEMPTED');
    outputResult('SEND_RESULT', 'NOT_SENT');
    outputResult('SEND_ATTEMPTS', '0');
    outputResult('ERROR_CATEGORY', $category);
    exit(1);
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

if (filter_var(SENDER, FILTER_VALIDATE_EMAIL) === false || filter_var(RECIPIENT, FILTER_VALIDATE_EMAIL) === false) {
    stopWithoutSending('invalid_address_configuration');
}

$password = trim((string) stream_get_contents(STDIN));

if ($password === '') {
    stopWithoutSending('missing_app_password');
}

$transport = null;
$sendAttempted = false;

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
        stopWithoutSending('mandatory_starttls_not_enforced');
    }

    $transport->start();

    if (! hasEncryptedTlsStream($transport)) {
        stopWithoutSending('tls_upgrade_not_verified');
    }

    $email = (new Email)
        ->from(SENDER)
        ->to(RECIPIENT)
        ->subject('Instruments Desk - Gmail SMTP Test')
        ->text("This is a one-time SMTP connectivity test for Instruments Desk.\n\nThe message contains no calibration data and was sent to verify Gmail SMTP authentication and delivery.\n\nNo attachments.\nNo external recipients.\nNo calibration notification template.");

    $sendAttempted = true;
    $transport->send($email);

    outputResult('SMTP_HOST', SMTP_HOST);
    outputResult('SMTP_PORT', (string) SMTP_PORT);
    outputResult('TLS_STATUS', 'VERIFIED');
    outputResult('AUTHENTICATION', 'SUCCEEDED');
    outputResult('SEND_RESULT', 'ACCEPTED_BY_SMTP');
    outputResult('RECIPIENT', RECIPIENT);
    outputResult('SEND_ATTEMPTS', $sendAttempted ? '1' : '0');
    outputResult('ERROR_CATEGORY', 'NONE');
} catch (TransportExceptionInterface $exception) {
    outputResult('SMTP_HOST', SMTP_HOST);
    outputResult('SMTP_PORT', (string) SMTP_PORT);
    outputResult('TLS_STATUS', 'UNKNOWN_OR_FAILED');
    outputResult('AUTHENTICATION', 'FAILED_OR_UNKNOWN');
    outputResult('SEND_RESULT', 'UNKNOWN');
    outputResult('SEND_ATTEMPTS', $sendAttempted ? '1' : '0');
    outputResult('ERROR_CATEGORY', 'smtp_transport_failure');
    exit(1);
} catch (Throwable $exception) {
    outputResult('SMTP_HOST', SMTP_HOST);
    outputResult('SMTP_PORT', (string) SMTP_PORT);
    outputResult('TLS_STATUS', 'UNKNOWN_OR_FAILED');
    outputResult('AUTHENTICATION', 'FAILED_OR_UNKNOWN');
    outputResult('SEND_RESULT', 'FAILED');
    outputResult('SEND_ATTEMPTS', $sendAttempted ? '1' : '0');
    outputResult('ERROR_CATEGORY', 'smtp_test_failure');
    exit(1);
} finally {
    if ($transport instanceof EsmtpTransport) {
        $transport->stop();
    }

    $password = str_repeat("\0", strlen($password));
    unset($password);
}
