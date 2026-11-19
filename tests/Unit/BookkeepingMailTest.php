<?php

namespace Tests\Unit;

use App\Services\Bookkeeping\BookkeepingMailer;
use App\Services\Bookkeeping\BookkeepingMessage;
use App\Services\Bookkeeping\EmailComposer;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class BookkeepingMailTest extends TestCase
{
    public function test_default_template_wording_and_signature_replacement(): void
    {
        $subject = config('bookkeeping.template.subject');
        $body = config('bookkeeping.template.body');
        $this->assertSame('Outstanding Invoice(s) from Powerbrands', $subject);
        $this->assertSame(
            "Please review the attached invoice for the latest update. If payment is already on its way, please disregard this note. Otherwise, let me know if you have any questions or need anything resent.\n\nThank you,\n[signature]",
            $body
        );

        $signature = EmailComposer::sanitize('<script>alert(1)</script><b>Andy Wu</b><a href="javascript:alert(1)">x</a><a href="https://powerbrandsny.com">site</a>');
        $this->assertStringNotContainsString('<script', $signature);
        $this->assertStringNotContainsString('javascript:', $signature);
        $this->assertStringContainsString('<b>Andy Wu</b>', $signature);
        $this->assertStringContainsString('href="https://powerbrandsny.com"', $signature);

        [$text, $html] = EmailComposer::render($body, $signature);
        $this->assertStringContainsString('Please review the attached invoice for the latest update.', $text);
        $this->assertStringContainsString('Andy Wu', $text);
        $this->assertStringNotContainsString('[signature]', $html);
        $this->assertStringContainsString('<b>Andy Wu</b>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertSame('Attached: INV-1001.pdf', EmailComposer::attachmentIndicator(['INV-1001']));
        $this->assertSame('Attached: open invoice PDF(s)', EmailComposer::attachmentIndicator([]));
    }

    public function test_real_mailer_never_sends_during_tests(): void
    {
        config([
            'bookkeeping.mail.transport' => 'gmail',
            'bookkeeping.mail.gmail.client_id' => 'client',
            'bookkeeping.mail.gmail.client_secret' => 'secret',
            'bookkeeping.mail.gmail.refresh_token' => 'refresh',
            'bookkeeping.mail.allow_in_tests' => true,
        ]);
        Http::fake();

        $mailer = new BookkeepingMailer;
        $this->assertTrue($mailer->credentialsPresent());
        $this->assertTrue($mailer->configured());

        $message = new BookkeepingMessage(
            'billing@powerbrandsny.com',
            'Powerbrands',
            'pay@green.example',
            'Outstanding Invoice(s) from Powerbrands',
            'Hello',
            '<p>Hello</p>',
            [['filename' => 'INV-1.pdf', 'mime' => 'application/pdf', 'bytes' => "%PDF-1.4\n"]],
        );
        $email = $mailer->buildEmail($message);
        $this->assertSame('billing@powerbrandsny.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('pay@green.example', $email->getTo()[0]->getAddress());
        $this->assertCount(1, $email->getAttachments());

        try {
            $mailer->send($message);
            $this->fail('The real mailer sent during a test.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Refusing to send', $e->getMessage());
        }
        Http::assertNothingSent();

        config(['bookkeeping.mail.allow_in_tests' => false]);
        $this->assertFalse((new BookkeepingMailer)->configured());
    }

    public function test_smtp_without_a_password_is_not_configured(): void
    {
        config([
            'bookkeeping.mail.allow_in_tests' => true,
            'bookkeeping.mail.transport' => 'smtp',
            'bookkeeping.mail.smtp.host' => 'smtp.example.com',
            'bookkeeping.mail.smtp.username' => 'billing',
            'bookkeeping.mail.smtp.password' => '',
        ]);
        $this->assertFalse((new BookkeepingMailer)->configured());
    }
}
