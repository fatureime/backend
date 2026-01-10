<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class EmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private ParameterBagInterface $parameterBag
    ) {
    }

    /**
     * Send email verification email to user
     */
    public function sendVerificationEmail(User $user): void
    {
        $frontendUrl = $this->parameterBag->get('frontend_url');

        $verificationUrl = sprintf(
            '%s/verify-email?token=%s',
            $frontendUrl,
            $user->getEmailVerificationToken()
        );

        $fromEmail = $this->parameterBag->get('mailer_from_email');
        $fromName = $this->parameterBag->get('mailer_from_name');

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($user->getEmail())
            ->subject('Verifikoni Email-in tuaj - Faturëime')
            ->html($this->getVerificationEmailTemplate($user, $verificationUrl))
            ->text($this->getVerificationEmailText($user, $verificationUrl));

        $this->mailer->send($email);
    }

    /**
     * Get HTML template for verification email
     */
    private function getVerificationEmailTemplate(User $user, string $verificationUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            background-color: #f9f9f9;
            padding: 30px;
            border-radius: 8px;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 20px 0;
        }
        .button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Mirë se vini në Faturëime!</h1>
        <p>Përshëndetje,</p>
        <p>Faleminderit që u regjistruat në Faturëime. Për të aktivizuar llogarinë tuaj, ju lutem verifikoni adresën tuaj të email-it duke klikuar butonin më poshtë:</p>
        <a href="{$verificationUrl}" class="button">Verifiko Email-in</a>
        <p>Ose kopjoni dhe ngjisni këtë link në shfletuesin tuaj:</p>
        <p><a href="{$verificationUrl}">{$verificationUrl}</a></p>
        <p>Nëse nuk keni krijuar një llogari, ju lutem injoroni këtë email.</p>
        <p>Linku i verifikimit skadon pas 24 orësh.</p>
        <p>Me respekt,<br>Ekipi i Faturëime</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get plain text version of verification email
     */
    private function getVerificationEmailText(User $user, string $verificationUrl): string
    {
        return <<<TEXT
Mirë se vini në Faturëime!

Përshëndetje,

Faleminderit që u regjistruat në Faturëime. Për të aktivizuar llogarinë tuaj, ju lutem verifikoni adresën tuaj të email-it duke klikuar linkun më poshtë:

{$verificationUrl}

Nëse nuk keni krijuar një llogari, ju lutem injoroni këtë email.

Linku i verifikimit skadon pas 24 orësh.

Me respekt,
Ekipi i Faturëime
TEXT;
    }

    /**
     * Send invitation email to user
     */
    public function sendInvitationEmail(User $user, string $invitationToken): void
    {
        $frontendUrl = $this->parameterBag->get('frontend_url');

        $invitationUrl = sprintf(
            '%s/accept-invitation?token=%s',
            $frontendUrl,
            $invitationToken
        );

        $fromEmail = $this->parameterBag->get('mailer_from_email');
        $fromName = $this->parameterBag->get('mailer_from_name');

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromEmail))
            ->to($user->getEmail())
            ->subject('Ftesë për të bashkuar Faturëime')
            ->html($this->getInvitationEmailTemplate($user, $invitationUrl))
            ->text($this->getInvitationEmailText($user, $invitationUrl));

        $this->mailer->send($email);
    }

    /**
     * Get HTML template for invitation email
     */
    private function getInvitationEmailTemplate(User $user, string $invitationUrl): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            background-color: #f9f9f9;
            padding: 30px;
            border-radius: 8px;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #007bff;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 20px 0;
        }
        .button:hover {
            background-color: #0056b3;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Ftesë për të bashkuar Faturëime</h1>
        <p>Përshëndetje,</p>
        <p>Ju jeni ftuar të bashkoheni në Faturëime. Për të aktivizuar llogarinë tuaj dhe të vendosni fjalëkalimin tuaj, ju lutem klikoni butonin më poshtë:</p>
        <a href="{$invitationUrl}" class="button">Prano Ftesën</a>
        <p>Ose kopjoni dhe ngjisni këtë link në shfletuesin tuaj:</p>
        <p><a href="{$invitationUrl}">{$invitationUrl}</a></p>
        <p>Nëse nuk prisni këtë ftesë, ju lutem injoroni këtë email.</p>
        <p>Linku i ftesës skadon pas 7 ditësh.</p>
        <p>Me respekt,<br>Ekipi i Faturëime</p>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Get plain text version of invitation email
     */
    private function getInvitationEmailText(User $user, string $invitationUrl): string
    {
        return <<<TEXT
Ftesë për të bashkuar Faturëime

Përshëndetje,

Ju jeni ftuar të bashkoheni në Faturëime. Për të aktivizuar llogarinë tuaj dhe të vendosni fjalëkalimin tuaj, ju lutem klikoni linkun më poshtë:

{$invitationUrl}

Nëse nuk prisni këtë ftesë, ju lutem injoroni këtë email.

Linku i ftesës skadon pas 7 ditësh.

Me respekt,
Ekipi i Faturëime
TEXT;
    }
}
