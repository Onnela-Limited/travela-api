<?php
namespace App\Mail;
use Illuminate\Mail\Mailable;
class PartnerUpdateMail extends Mailable {
 public function __construct(public readonly array $update,public readonly string $portalUrl){}
 public function build(): static {return $this->subject('Travela: '.$this->update['title'])->html('<h1>'.e($this->update['title']).'</h1><p>'.e($this->update['body']).'</p><p><a href="'.e($this->portalUrl.$this->update['path']).'">Open Travela partner portal</a></p>');}
}
