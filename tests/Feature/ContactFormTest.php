<?php

namespace Tests\Feature;

use App\Mail\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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
            $mail->assertTo('bugs@example.org');

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
}
