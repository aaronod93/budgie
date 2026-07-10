<?php

namespace App\Mail;

use App\Models\BudgetInvitation;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class BudgetInvitationMail extends Mailable
{
    public function __construct(public BudgetInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->invitation->inviter->name} invited you to a budget on Lil' Budgie",
        );
    }

    public function content(): Content
    {
        $app = config('app.frontend_url', env('FRONTEND_URL', config('app.url')));
        $budget = $this->invitation->budget->name;
        $inviter = $this->invitation->inviter->name;
        $role = $this->invitation->role;

        return new Content(htmlString: <<<HTML
            <div style="font-family: system-ui, sans-serif; max-width: 32rem; margin: 0 auto;">
                <h2 style="color: #047857;">Lil' Budgie</h2>
                <p><strong>{$inviter}</strong> invited you to share the budget
                   <strong>{$budget}</strong> as {$role}.</p>
                <p>Sign in (or register with this email address) and accept the
                   invitation from your budget list.</p>
                <p><a href="{$app}" style="display: inline-block; background: #059669; color: #fff;
                      padding: 10px 18px; border-radius: 6px; text-decoration: none;">Open Lil' Budgie</a></p>
            </div>
            HTML);
    }
}
