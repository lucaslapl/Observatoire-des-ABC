<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Contact');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'min:20', 'max:5000'],
        ]);

        Mail::to(config('abc.contact_email'))->send(new ContactMessage(
            (string) ($data['name'] ?? ''),
            $data['email'],
            $data['message'],
        ));

        return redirect()->route('contact')
            ->with('success', 'Merci ! Votre message a bien été envoyé, il sera lu très vite.');
    }
}
