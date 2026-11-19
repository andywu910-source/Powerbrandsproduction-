<?php

namespace App\Services\Bookkeeping;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends one bookkeeping email. Credentials come from the environment.
 * This class refuses to open a connection while tests are running.
 */
class BookkeepingMailer
{
    public function configured(): bool
    {
        if ($this->testsBlockSending() && ! config('bookkeeping.mail.allow_in_tests')) {
            return false;
        }

        return $this->credentialsPresent();
    }

    public function credentialsPresent(): bool
    {
        $transport = $this->transport();
        if ($transport === 'smtp') {
            return $this->filled('bookkeeping.mail.smtp.host')
                && $this->filled('bookkeeping.mail.smtp.username')
                && $this->filled('bookkeeping.mail.smtp.password');
        }
        if ($transport === 'gmail') {
            return $this->filled('bookkeeping.mail.gmail.client_id')
                && $this->filled('bookkeeping.mail.gmail.client_secret')
                && $this->filled('bookkeeping.mail.gmail.refresh_token');
        }

        return false;
    }

    public function buildEmail(BookkeepingMessage $message): Email
    {
        $email = (new Email)
            ->from(new Address($message->fromAddress, $message->fromName))
            ->to($message->to)
            ->subject($message->subject)
            ->text($message->text)
            ->html($message->html);

        foreach ($message->attachments as $file) {
            $email->attach($file['bytes'], $file['filename'], $file['mime']);
        }

        return $email;
    }

    public function send(BookkeepingMessage $message): void
    {
        if ($this->testsBlockSending()) {
            throw new RuntimeException('Refusing to send bookkeeping email during tests.');
        }
        if (! $this->credentialsPresent()) {
            throw new RuntimeException('Bookkeeping mail is not configured. No message was sent.');
        }

        $email = $this->buildEmail($message);
        if ($this->transport() === 'gmail') {
            $this->sendGmail($email);

            return;
        }
        $this->sendSmtp($email);
    }

    private function sendSmtp(Email $email): void
    {
        $host = (string) config('bookkeeping.mail.smtp.host');
        $port = (int) config('bookkeeping.mail.smtp.port');
        $user = (string) config('bookkeeping.mail.smtp.username');
        $pass = (string) config('bookkeeping.mail.smtp.password');
        $encryption = strtolower((string) config('bookkeeping.mail.smtp.encryption', 'tls'));
        if (! preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host) || $port < 1 || $port > 65535) {
            throw new RuntimeException('Bookkeeping SMTP host is not valid. No message was sent.');
        }

        $scheme = $encryption === 'ssl' ? 'smtps' : 'smtp';
        $dsn = sprintf('%s://%s:%s@%s:%d', $scheme, rawurlencode($user), rawurlencode($pass), $host, $port);
        if ($encryption === 'tls') {
            $dsn .= '?encryption=tls';
        }

        try {
            (new Mailer(Transport::fromDsn($dsn)))->send($email);
        } catch (\Throwable $e) {
            Log::warning('Bookkeeping SMTP send failed.', ['exception' => $e::class]);
            throw new RuntimeException('The email could not be sent. Check the SMTP settings.');
        }
    }

    private function sendGmail(Email $email): void
    {
        $token = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('bookkeeping.mail.gmail.client_id'),
            'client_secret' => config('bookkeeping.mail.gmail.client_secret'),
            'refresh_token' => config('bookkeeping.mail.gmail.refresh_token'),
            'grant_type' => 'refresh_token',
        ]);
        $access = $token->json('access_token');
        if (! $token->successful() || ! is_string($access) || $access === '') {
            Log::warning('Bookkeeping Gmail token request failed.', ['status' => $token->status()]);
            throw new RuntimeException('Gmail refused the sign-in. Check the Gmail API credentials. No message was sent.');
        }

        $raw = rtrim(strtr(base64_encode($email->toString()), '+/', '-_'), '=');
        $send = Http::withToken($access)->timeout(30)->post(
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            ['raw' => $raw]
        );
        if (! $send->successful()) {
            Log::warning('Bookkeeping Gmail send failed.', ['status' => $send->status()]);
            throw new RuntimeException('Gmail did not accept the message. Nothing was recorded as sent.');
        }
    }

    private function transport(): string
    {
        return strtolower(trim((string) config('bookkeeping.mail.transport')));
    }

    private function filled(string $key): bool
    {
        return trim((string) config($key)) !== '';
    }

    private function testsBlockSending(): bool
    {
        return app()->runningUnitTests();
    }
}
