<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content, Envelope};

class PartnerTeamInvitationMail extends Mailable
{
    public function __construct(public readonly string $invitationUrl,public readonly string $business,public readonly string $role) {}
    public function envelope(): Envelope { return new Envelope(subject:'You are invited to a Travela partner team'); }
    public function content(): Content
    {
        $business=e($this->business); $url=e($this->invitationUrl); $role=e(str_replace('_',' ',$this->role));
        return new Content(htmlString:"<div style='font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:24px'><h2>Join your Travela team</h2><p>$business invited you as $role.</p><p><a href='$url'>Review and accept invitation</a></p><p>This invitation expires in 7 days. You will verify your own email with a one-time code before access is granted. If you did not expect this invitation, ignore it.</p></div>");
    }
}
