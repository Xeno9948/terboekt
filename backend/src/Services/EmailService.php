<?php
declare(strict_types=1);

namespace Terboekt\Services;

use Terboekt\Config;
use Terboekt\Mail\OutgoingMessage;
use Terboekt\Mail\ResendTransport;
use Terboekt\Mail\SmtpTransport;
use Terboekt\Money;
use Terboekt\Repositories\EmailLogRepository;
use Terboekt\Repositories\PropertySettingsRepository;

final class EmailService
{
    public const TEMPLATES = [
        'booking_request_received',
        'bank_transfer_instructions',
        'booking_confirmed',
        'booking_rejected',
        'booking_expired',
        'booking_cancelled',
        'booking_changed',
        'manager_new_booking_request',
        'manager_payment_deadline_warning',
        'manager_calendar_sync_warning',
        'manager_booking_expired',
        'test_email',
        'admin_otp',
    ];

    /** Fail-fast when MailProtect SMTP is the only path on Railway. */
    public const MAILPROTECT_BLOCKED_ERROR = 'mailprotect_blocked_use_resend: Combell MailProtect (smtp-auth.mailprotect.be) weigert verbindingen vanaf Railway (TCP-timeout). Productie moet Resend gebruiken: zet RESEND_API_KEY op Railway en verifieer het afzenderdomein (hometerboekt.be).';

    public function __construct(
        private readonly Config $config,
        private readonly EmailLogRepository $logs,
        private readonly PropertySettingsRepository $settings,
        private readonly SmtpTransport $transport,
        private readonly ResendTransport $resend,
    ) {
    }

    /**
     * Persist an EmailLog row and attempt send. Never throws to the caller for SMTP failures.
     *
     * @param array<string, mixed> $vars
     * @return array{id: int, sent: bool, status: string, error: ?string}
     */
    public function sendTemplate(string $template, string $to, array $vars = [], ?int $bookingId = null): array
    {
        if (!in_array($template, self::TEMPLATES, true)) {
            throw new \InvalidArgumentException('Unknown email template: ' . $template);
        }
        $rendered = $this->render($template, $vars);
        $id = $this->logs->insert([
            'booking_id' => $bookingId,
            'template_key' => $template,
            'to_email' => $to,
            'subject' => $rendered['subject'],
            'body_html' => $rendered['html'],
            'body_text' => $rendered['text'],
            'status' => 'pending',
            'error' => null,
            'attempts' => 0,
        ]);
        return $this->attemptSend($id);
    }

    /** @return array{id: int, sent: bool, status: string, error: ?string} */
    public function retry(int $emailLogId): array
    {
        $row = $this->logs->findById($emailLogId);
        if ($row === null) {
            throw new \Terboekt\Domain\NotFoundException('Email log not found');
        }
        return $this->attemptSend($emailLogId);
    }

    public function smtpConfigured(): bool
    {
        return $this->mailConfigured();
    }

    public function mailConfigured(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function sendPath(): string
    {
        if ($this->resend->configured()) {
            return 'resend';
        }
        if ($this->transport->configured() && !$this->config->smtpUnreachableFromThisHost()) {
            return 'smtp';
        }
        return 'none';
    }

    public function unavailableReason(): ?string
    {
        if ($this->resend->configured()) {
            return null;
        }
        if ($this->config->smtpUnreachableFromThisHost()) {
            return self::MAILPROTECT_BLOCKED_ERROR;
        }
        if ($this->transport->configured()) {
            return null;
        }
        return 'smtp_not_configured';
    }

    public function managerEmail(): string
    {
        $fromSettings = trim((string) ($this->settings->get('manager_email') ?? ''));
        if ($fromSettings !== '' && filter_var($fromSettings, FILTER_VALIDATE_EMAIL)) {
            return $fromSettings;
        }
        return trim($this->config->managerEmail);
    }

    /**
     * @param array<string, mixed> $vars
     * @return array{subject: string, html: string, text: string}
     */
    public function render(string $template, array $vars): array
    {
        $lang = (string) ($vars['language'] ?? 'nl');
        $vars += $this->defaultVars();
        $copy = $this->copy($template, $lang, $vars);
        $html = $this->wrapHtml($copy['subject'], $copy['body_html']);
        return [
            'subject' => $copy['subject'],
            'html' => $html,
            'text' => $copy['body_text'],
        ];
    }

    /** @return array{id: int, sent: bool, status: string, error: ?string} */
    private function attemptSend(int $id): array
    {
        $row = $this->logs->findById($id);
        if ($row === null) {
            throw new \Terboekt\Domain\NotFoundException('Email log not found');
        }
        $attempts = (int) $row['attempts'] + 1;
        $this->logs->update($id, [
            'attempts' => $attempts,
            'last_attempt_at' => $this->config->timezone ? gmdate('Y-m-d H:i:s') : gmdate('Y-m-d H:i:s'),
        ]);

        $blocked = $this->unavailableReason();
        if ($blocked !== null) {
            $this->logs->update($id, [
                'status' => 'failed',
                'error' => $blocked,
            ]);
            return ['id' => $id, 'sent' => false, 'status' => 'failed', 'error' => $blocked];
        }

        try {
            $this->activeTransportSend(new OutgoingMessage(
                to: [(string) $row['to_email']],
                subject: (string) $row['subject'],
                text: (string) $row['body_text'],
                html: (string) $row['body_html'],
            ));
            $this->logs->update($id, [
                'status' => 'sent',
                'error' => null,
                'sent_at' => gmdate('Y-m-d H:i:s'),
            ]);
            return ['id' => $id, 'sent' => true, 'status' => 'sent', 'error' => null];
        } catch (\Throwable $e) {
            $this->logs->update($id, [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            return ['id' => $id, 'sent' => false, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    private function activeTransportSend(OutgoingMessage $message): void
    {
        if ($this->resend->configured()) {
            $this->resend->send($message);
            return;
        }
        $this->transport->send($message);
    }

    /** @return array<string, string> */
    private function defaultVars(): array
    {
        $s = $this->settings;
        return [
            'property_name' => $s->get('property_name', 'Home Terboekt') ?? 'Home Terboekt',
            'property_address' => $s->get('property_address', 'Terboekt 28, 3600 Genk') ?? '',
            'contact_email' => $s->get('contact_email', 'info@hometerboekt.be') ?? 'info@hometerboekt.be',
            'bank_account_holder' => $s->get('bank_account_holder', '') ?? '',
            'bank_iban' => $s->get('bank_iban', '') ?? '',
            'bank_bic' => $s->get('bank_bic', '') ?? '',
            'bank_name' => $s->get('bank_name', '') ?? '',
            'house_rules_url' => $s->get('house_rules_url', '/voorwaarden') ?? '/voorwaarden',
            'cancellation_url' => $s->get('cancellation_url', '/annulatie') ?? '/annulatie',
            'app_base_url' => $this->config->appBaseUrl,
            'deposit_percentage' => (string) $s->depositPercentage(),
        ];
    }

    /**
     * @param array<string, mixed> $vars
     * @return array{subject: string, body_html: string, body_text: string}
     */
    private function copy(string $template, string $lang, array $vars): array
    {
        $ref = (string) ($vars['reference'] ?? '');
        $name = (string) ($vars['guest_name'] ?? '');
        $checkIn = (string) ($vars['check_in'] ?? '');
        $checkOut = (string) ($vars['check_out'] ?? '');
        $deposit = isset($vars['deposit_cents']) ? Money::formatEuro((int) $vars['deposit_cents']) : '';
        $total = isset($vars['total_cents']) ? Money::formatEuro((int) $vars['total_cents']) : '';
        $remaining = isset($vars['remaining_cents']) ? Money::formatEuro((int) $vars['remaining_cents']) : '';
        $due = (string) ($vars['deposit_due_at'] ?? '');
        $reason = (string) ($vars['reason'] ?? '');
        $property = (string) $vars['property_name'];
        $guestEmail = (string) ($vars['guest_email'] ?? '');
        $guestCount = (string) ($vars['guests'] ?? '');
        $syncError = (string) ($vars['error'] ?? '');
        $bankBlock = $this->bankBlock($vars, $lang);
        $depositPct = (string) (int) ($vars['deposit_percentage'] ?? $this->settings->depositPercentage());
        $approveUrl = trim((string) ($vars['approve_url'] ?? ''));
        $rejectUrl = trim((string) ($vars['reject_url'] ?? ''));
        $otpCode = trim((string) ($vars['otp_code'] ?? ''));

        $map = [
            'booking_request_received' => [
                'nl' => ["Aanvraag ontvangen {$ref}", "Beste {$name},\n\nWe hebben uw reservatieaanvraag {$ref} voor {$checkIn} tot {$checkOut} ontvangen. Dit is nog geen bevestiging. We nemen zo snel mogelijk contact op.\n\n{$property}"],
                'en' => ["Request received {$ref}", "Dear {$name},\n\nWe received your booking request {$ref} for {$checkIn} to {$checkOut}. This is not a confirmation yet. We will contact you shortly.\n\n{$property}"],
                'fr' => ["Demande reçue {$ref}", "Bonjour {$name},\n\nNous avons reçu votre demande {$ref} du {$checkIn} au {$checkOut}. Ceci n'est pas encore une confirmation.\n\n{$property}"],
                'de' => ["Anfrage erhalten {$ref}", "Hallo {$name},\n\nWir haben Ihre Anfrage {$ref} für {$checkIn} bis {$checkOut} erhalten. Dies ist noch keine Bestätigung.\n\n{$property}"],
            ],
            'bank_transfer_instructions' => [
                'nl' => ["Voorschot voor {$ref}", "Beste {$name},\n\nOm reservatie {$ref} te bevestigen vragen we een voorschot van {$deposit} ({$depositPct}%) vóór {$due}.\nHet restbedrag {$remaining} volgt later. Totaal huur: {$total}.\n\n{$bankBlock}\n\nNa ontvangst bevestigen wij de boeking. Zonder managerbevestiging is de reservatie niet definitief."],
                'en' => ["Deposit for {$ref}", "Dear {$name},\n\nTo proceed with {$ref} please transfer a {$depositPct}% deposit of {$deposit} before {$due}.\nRemaining balance {$remaining}. Stay total: {$total}.\n\n{$bankBlock}\n\nThe stay is only confirmed after the manager approves."],
                'fr' => ["Acompte {$ref}", "Bonjour {$name},\n\nPour {$ref}, veuillez verser un acompte de {$depositPct}% ({$deposit}) avant le {$due}.\nSolde {$remaining}. Total {$total}.\n\n{$bankBlock}"],
                'de' => ["Anzahlung {$ref}", "Hallo {$name},\n\nFür {$ref} überweisen Sie bitte {$depositPct}% Anzahlung ({$deposit}) vor {$due}.\nRestbetrag {$remaining}. Gesamt {$total}.\n\n{$bankBlock}"],
            ],
            'booking_confirmed' => [
                'nl' => ["Bevestigd: {$ref}", "Beste {$name},\n\nUw verblijf {$ref} van {$checkIn} tot {$checkOut} is bevestigd. Welkom in {$property}."],
                'en' => ["Confirmed: {$ref}", "Dear {$name},\n\nYour stay {$ref} from {$checkIn} to {$checkOut} is confirmed. Welcome to {$property}."],
                'fr' => ["Confirmé : {$ref}", "Bonjour {$name},\n\nVotre séjour {$ref} du {$checkIn} au {$checkOut} est confirmé."],
                'de' => ["Bestätigt: {$ref}", "Hallo {$name},\n\nIhr Aufenthalt {$ref} vom {$checkIn} bis {$checkOut} ist bestätigt."],
            ],
            'booking_rejected' => [
                'nl' => ["Aanvraag {$ref} niet weerhouden", "Beste {$name},\n\nUw aanvraag {$ref} konden we niet weerhouden." . ($reason !== '' ? "\n\n{$reason}" : '')],
                'en' => ["Request {$ref} not accepted", "Dear {$name},\n\nWe could not accept request {$ref}." . ($reason !== '' ? "\n\n{$reason}" : '')],
                'fr' => ["Demande {$ref} refusée", "Bonjour {$name},\n\nNous ne pouvons pas retenir la demande {$ref}."],
                'de' => ["Anfrage {$ref} abgelehnt", "Hallo {$name},\n\nIhre Anfrage {$ref} konnten wir nicht annehmen."],
            ],
            'booking_expired' => [
                'nl' => ["Aanvraag {$ref} verlopen", "Beste {$name},\n\nReservatie {$ref} is verlopen omdat de termijn verstreken is."],
                'en' => ["Request {$ref} expired", "Dear {$name},\n\nBooking {$ref} expired because the deadline passed."],
                'fr' => ["Demande {$ref} expirée", "Bonjour {$name},\n\nLa réservation {$ref} a expiré."],
                'de' => ["Anfrage {$ref} abgelaufen", "Hallo {$name},\n\nReservierung {$ref} ist abgelaufen."],
            ],
            'booking_cancelled' => [
                'nl' => ["Annulatie {$ref}", "Beste {$name},\n\nReservatie {$ref} is geannuleerd." . ($reason !== '' ? "\n\n{$reason}" : '')],
                'en' => ["Cancellation {$ref}", "Dear {$name},\n\nBooking {$ref} has been cancelled." . ($reason !== '' ? "\n\n{$reason}" : '')],
                'fr' => ["Annulation {$ref}", "Bonjour {$name},\n\nLa réservation {$ref} a été annulée."],
                'de' => ["Stornierung {$ref}", "Hallo {$name},\n\nReservierung {$ref} wurde storniert."],
            ],
            'booking_changed' => [
                'nl' => ["Wijziging {$ref}", "Beste {$name},\n\nReservatie {$ref} is gewijzigd. Nieuwe data: {$checkIn} tot {$checkOut}."],
                'en' => ["Change {$ref}", "Dear {$name},\n\nBooking {$ref} was updated. New dates: {$checkIn} to {$checkOut}."],
                'fr' => ["Modification {$ref}", "Bonjour {$name},\n\nLa réservation {$ref} a été modifiée : {$checkIn} – {$checkOut}."],
                'de' => ["Änderung {$ref}", "Hallo {$name},\n\nReservierung {$ref} wurde geändert: {$checkIn} bis {$checkOut}."],
            ],
            'manager_new_booking_request' => [
                'nl' => ["Nieuwe aanvraag {$ref}", $this->managerRequestText('nl', $ref, $name, $guestEmail, $checkIn, $checkOut, $guestCount, $total, $deposit, $approveUrl, $rejectUrl, (string) $vars['app_base_url'])],
                'en' => ["New request {$ref}", $this->managerRequestText('en', $ref, $name, $guestEmail, $checkIn, $checkOut, $guestCount, $total, $deposit, $approveUrl, $rejectUrl, (string) $vars['app_base_url'])],
                'fr' => ["Nouvelle demande {$ref}", $this->managerRequestText('fr', $ref, $name, $guestEmail, $checkIn, $checkOut, $guestCount, $total, $deposit, $approveUrl, $rejectUrl, (string) $vars['app_base_url'])],
                'de' => ["Neue Anfrage {$ref}", $this->managerRequestText('de', $ref, $name, $guestEmail, $checkIn, $checkOut, $guestCount, $total, $deposit, $approveUrl, $rejectUrl, (string) $vars['app_base_url'])],
            ],
            'manager_payment_deadline_warning' => [
                'nl' => ["Voorschottermijn {$ref}", "Het voorschot voor {$ref} is nog niet gemarkeerd als ontvangen. Deadline: {$due}."],
                'en' => ["Deposit deadline {$ref}", "Deposit for {$ref} is not marked received. Deadline: {$due}."],
                'fr' => ["Échéance acompte {$ref}", "Acompte {$ref} non reçu. Échéance : {$due}."],
                'de' => ["Anzahlung Frist {$ref}", "Anzahlung für {$ref} fehlt. Frist: {$due}."],
            ],
            'manager_calendar_sync_warning' => [
                'nl' => ['iCal-sync waarschuwing', "De externe kalender kon niet worden vernieuwd. Bestaande geblokkeerde data blijven staan (geen stille vrijgave).\n" . $syncError],
                'en' => ['iCal sync warning', "External calendar refresh failed. Existing blocked dates were kept.\n" . $syncError],
                'fr' => ['Alerte sync iCal', "Échec de la synchro. Les dates bloquées existantes sont conservées.\n" . $syncError],
                'de' => ['iCal-Sync Warnung', "Kalender-Sync fehlgeschlagen. Bestehende Sperren bleiben.\n" . $syncError],
            ],
            'manager_booking_expired' => [
                'nl' => ["Verlopen {$ref}", "Reservatie {$ref} van {$name} is verlopen (termijn voorschot verstreken). Data zijn vrijgegeven.\n{$checkIn} → {$checkOut}."],
                'en' => ["Expired {$ref}", "Booking {$ref} for {$name} expired (deposit deadline passed). Dates were released.\n{$checkIn} → {$checkOut}."],
                'fr' => ["Expiré {$ref}", "La réservation {$ref} de {$name} a expiré. Les dates sont libérées."],
                'de' => ["Abgelaufen {$ref}", "Reservierung {$ref} von {$name} ist abgelaufen. Daten wurden freigegeben."],
            ],
            'test_email' => [
                'nl' => ['Testbericht Home Terboekt', "Dit is een testbericht van de Home Terboekt mailer."],
                'en' => ['Home Terboekt test email', "This is a test message from the Home Terboekt mailer."],
                'fr' => ['E-mail test Home Terboekt', "Ceci est un message test."],
                'de' => ['Test-E-Mail Home Terboekt', "Dies ist eine Testnachricht."],
            ],
            'admin_otp' => [
                'nl' => ['Uw aanmeldcode', "Beste {$name},\n\nUw code om aan te melden in het beheer is: {$otpCode}\n\nDe code is 10 minuten geldig. Heeft u dit niet gevraagd? Wijzig dan uw wachtwoord."],
                'en' => ['Your sign-in code', "Hello {$name},\n\nYour admin sign-in code is: {$otpCode}\n\nIt expires in 10 minutes. If you did not request this, change your password."],
                'fr' => ['Votre code de connexion', "Bonjour {$name},\n\nVotre code d’administration est : {$otpCode}\n\nIl expire dans 10 minutes."],
                'de' => ['Ihr Anmeldecode', "Hallo {$name},\n\nIhr Admin-Anmeldecode lautet: {$otpCode}\n\nEr gilt 10 Minuten."],
            ],
        ];

        $langKey = in_array($lang, ['nl', 'en', 'fr', 'de'], true) ? $lang : 'nl';
        [$subject, $text] = $map[$template][$langKey];
        $htmlBody = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false);
        if ($template === 'manager_new_booking_request' && $approveUrl !== '') {
            $htmlBody .= $this->managerActionButtonsHtml($approveUrl, $rejectUrl);
        }
        return ['subject' => $subject, 'body_html' => $htmlBody, 'body_text' => $text];
    }

    private function managerRequestText(
        string $lang,
        string $ref,
        string $name,
        string $guestEmail,
        string $checkIn,
        string $checkOut,
        string $guestCount,
        string $total,
        string $deposit,
        string $approveUrl,
        string $rejectUrl,
        string $baseUrl,
    ): string {
        $adminUrl = rtrim($baseUrl, '/') . '/admin/booking.php?ref=' . rawurlencode($ref);
        $intro = match ($lang) {
            'en' => "New booking request {$ref} from {$name} ({$guestEmail}).\n{$checkIn} → {$checkOut}, {$guestCount} guests.\nTotal {$total}, deposit {$deposit}.\n\nTap Approve to confirm the stay now. The guest then receives a confirmation email.",
            'fr' => "Nouvelle demande {$ref} de {$name} ({$guestEmail}).\n{$checkIn} → {$checkOut}, {$guestCount} personnes.\nTotal {$total}, acompte {$deposit}.\n\nAppuyez sur Approuver pour confirmer le séjour. Le client recevra alors un e-mail de confirmation.",
            'de' => "Neue Anfrage {$ref} von {$name} ({$guestEmail}).\n{$checkIn} → {$checkOut}, {$guestCount} Personen.\nGesamt {$total}, Anzahlung {$deposit}.\n\nTippen Sie auf Genehmigen, um den Aufenthalt jetzt zu bestätigen. Der Gast erhält dann eine Bestätigung.",
            default => "Nieuwe reservatieaanvraag {$ref} van {$name} ({$guestEmail}).\n{$checkIn} → {$checkOut}, {$guestCount} personen.\nTotaal {$total}, voorschot {$deposit}.\n\nTik op Goedkeuren om de boeking meteen te bevestigen. De gast krijgt dan een bevestigingsmail.",
        };
        $links = '';
        if ($approveUrl !== '') {
            $links .= match ($lang) {
                'en' => "\n\nApprove:\n{$approveUrl}\n\nDecline:\n{$rejectUrl}",
                'fr' => "\n\nApprouver :\n{$approveUrl}\n\nRefuser :\n{$rejectUrl}",
                'de' => "\n\nGenehmigen:\n{$approveUrl}\n\nAblehnen:\n{$rejectUrl}",
                default => "\n\nGoedkeuren:\n{$approveUrl}\n\nWeigeren:\n{$rejectUrl}",
            };
        }
        return $intro . $links . "\n\n" . $adminUrl;
    }

    private function managerActionButtonsHtml(string $approveUrl, string $rejectUrl): string
    {
        $approve = htmlspecialchars($approveUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $reject = htmlspecialchars($rejectUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = '<p style="margin:28px 0 12px;"><a href="' . $approve
            . '" style="display:inline-block;background:#2f6b4f;color:#ffffff;padding:14px 22px;border-radius:10px;text-decoration:none;font-weight:600;">Goedkeuren</a></p>';
        if ($rejectUrl !== '') {
            $html .= '<p style="margin:0;"><a href="' . $reject
                . '" style="color:#8a3b32;text-decoration:underline;">Weigeren</a></p>';
        }
        return $html;
    }

    /** @param array<string, mixed> $vars */
    private function bankBlock(array $vars, string $lang): string
    {
        $iban = trim((string) $vars['bank_iban']);
        $holder = trim((string) $vars['bank_account_holder']);
        $name = trim((string) ($vars['guest_name'] ?? ''));
        $ref = trim((string) ($vars['reference'] ?? ''));
        $comm = trim($name . ' ' . $ref);
        $commBlock = $comm === '' ? '' : match ($lang) {
            'en' => "Payment reference (required): {$comm}\nUse exactly this text as the transfer communication so we can match your payment.",
            'fr' => "Communication (obligatoire) : {$comm}\nUtilisez exactement ce texte comme communication du virement.",
            'de' => "Verwendungszweck (Pflicht): {$comm}\nVerwenden Sie genau diesen Text als Verwendungszweck der Überweisung.",
            default => "Mededeling (verplicht): {$comm}\nZet exact deze tekst bij de overschrijving, zodat we uw betaling herkennen.",
        };
        if ($iban === '' && $holder === '') {
            $missing = match ($lang) {
                'en' => 'Bank details will be sent by the manager. They are not stored in this message because they are not configured yet.',
                'fr' => 'Les coordonnées bancaires seront communiquées par le gestionnaire (pas encore configurées).',
                'de' => 'Bankverbindung folgt durch den Verwalter (noch nicht hinterlegt).',
                default => 'De overschrijvingsgegevens volgen via de beheerder. Ze zijn nog niet ingesteld, dus staan ze niet in dit bericht.',
            };
            return $commBlock !== '' ? $missing . "\n\n" . $commBlock : $missing;
        }
        $parts = array_filter([
            $holder !== '' ? 'Naam: ' . $holder : null,
            $iban !== '' ? 'IBAN: ' . $iban : null,
            trim((string) $vars['bank_bic']) !== '' ? 'BIC: ' . $vars['bank_bic'] : null,
            trim((string) $vars['bank_name']) !== '' ? 'Bank: ' . $vars['bank_name'] : null,
            $commBlock !== '' ? $commBlock : null,
        ]);
        return implode("\n", $parts);
    }

    private function wrapHtml(string $subject, string $inner): string
    {
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return '<!DOCTYPE html><html lang="nl"><head><meta charset="UTF-8"><title>'
            . $safeSubject . '</title></head><body style="font-family:Inter,Arial,sans-serif;color:#1c1c1c;background:#f6f4f0;padding:24px;">'
            . '<div style="max-width:640px;margin:0 auto;background:#fff;padding:24px;border-radius:14px;">'
            . '<p style="color:#c0a063;letter-spacing:.12em;text-transform:uppercase;font-size:12px;">Home Terboekt</p>'
            . '<h1 style="font-family:Georgia,serif;font-size:22px;">' . $safeSubject . '</h1>'
            . '<div>' . $inner . '</div>'
            . '</div></body></html>';
    }
}
