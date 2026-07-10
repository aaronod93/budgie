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
            <div style="font-family: 'Work Sans', system-ui, sans-serif; max-width: 32rem; margin: 0 auto;
                        background: #191E2A; border-radius: 12px; padding: 24px; color: #E7E6E2;">
                <h2 style="color: #E3854E; margin-top: 0;">Lil' Budgie</h2>
                <p><strong>{$inviter}</strong> invited you to share the budget
                   <strong>{$budget}</strong> as {$role}.</p>
                <p style="color: #A3C0D0;">Sign in (or register with this email address) and accept the
                   invitation from your budget list.</p>
                <p><a href="{$app}" style="display: inline-block; background: #E3854E; color: #131722;
                      font-weight: 600; padding: 10px 18px; border-radius: 6px;
                      text-decoration: none;">Open Lil' Budgie</a></p>
            </div>
            HTML);
    }
}
