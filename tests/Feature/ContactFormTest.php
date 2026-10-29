<?php

namespace Tests\Feature;

use App\Mail\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['abc.contact_email' => 'bugs@example.org']);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('contact:'.'visitor@example.com|127.0.0.1');
        RateLimiter::clear('contact:'.'127.0.0.1');

        parent::tearDown();
    }

    public function test_contact_page_renders(): void
    {
        $this->get('/contact')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Contact')
            ->where('flash.success', null));
    }

    public function test_contact_form_sends_email_to_configured_recipient(): void
    {
        Mail::fake();

        $this->post('/contact', [
            'name' => 'Jeanne Dupont',
            'email' => 'visitor@example.com',
            'message' => 'La carte ne charge pas les marqueurs sur la page /carte depuis ce matin.',
        ])->assertRedirect('/contact');

        Mail::assertSent(ContactMessage::class, function (ContactMessage $mail) {
            // Envoi via le mailer `contact`, expéditeur = la boîte de contact.
            $mail->assertTo('bugs@example.org');
            $mail->assertFrom('bugs@example.org');

            $replyTo = collect($mail->replyTo)->map(fn ($a) => $a['address']);

            return $replyTo->contains('visitor@example.com')
                && $mail->email === 'visitor@example.com';
        });
        Mail::assertSent(ContactMessage::class, 1);

        $this->get('/contact')->assertOk()->assertInertia(fn ($page) => $page
            ->where('flash.success', 'Merci ! Votre message a bien été envoyé, il sera lu très vite.'));
    }

    public function test_contact_form_rejects_missing_fields(): void
    {
        Mail::fake();

        $this->from('/contact')->post('/contact', [
            'email' => 'pas-un-email',
            'message' => 'trop court',
        ])->assertSessionHasErrors(['email', 'message']);

        Mail::assertNothingSent();
    }

    public function test_contact_mailer_falls_back_to_default_smtp(): void
    {
        $contact = config('mail.mailers.contact');
        $smtp = config('mail.mailers.smtp');

        $this->assertSame('smtp', $contact['transport']);
        $this->assertSame($smtp['host'], $contact['host']);
        $this->assertSame($smtp['port'], $contact['port']);
        $this->assertSame($smtp['username'], $contact['username']);
        $this->assertSame($smtp['password'], $contact['password']);
    }

    public function test_contact_form_is_rate_limited(): void
    {
        Mail::fake();

        $payload = [
            'email' => 'visitor@example.com',
            'message' => 'Un message suffisamment long pour passer la validation minimale.',
        ];

        for ($i = 0; $i < 3; $i++) {
            $this->post('/contact', $payload)->assertRedirect('/contact');
        }

        $this->post('/contact', $payload)->assertStatus(429);
        Mail::assertSent(ContactMessage::class, 3);
    }

    public function test_contact_form_survives_smtp_failure(): void
    {
        Mail::extend('failing', fn () => new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new TransportException('Connection refused (SMTP injoignable)');
            }

            public function __toString(): string
            {
                return 'failing';
            }
        });

        config()->set('mail.mailers.contact.transport', 'failing');

        $this->from('/contact')->post('/contact', [
            'email' => 'visitor@example.com',
            'message' => 'Un message suffisamment long pour passer la validation minimale.',
        ])->assertRedirect('/contact')->assertSessionHas('error');

        $this->get('/contact')->assertOk()->assertInertia(fn ($page) => $page
            ->where('flash.success', null)
            ->where('flash.error', "L'envoi a échoué pour un problème technique de notre côté. Merci de réessayer dans quelques minutes."));
    }
}
