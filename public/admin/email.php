<?php
declare(strict_types=1);

require __DIR__ . '/_layout.php';

[$app, $user] = admin_require();
$config = $app->config;

if (admin_is_post()) {
    if (!admin_verify_csrf()) {
        admin_set_flash('error', 'Beveiligingscontrole mislukt.');
        admin_redirect('email.php');
    }
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'save_identity') {
            $fromName = trim((string) ($_POST['mail_from_name'] ?? ''));
            $fromEmail = trim((string) ($_POST['mail_from_email'] ?? ''));
            $replyTo = trim((string) ($_POST['mail_reply_to'] ?? ''));
            $manager = trim((string) ($_POST['manager_email'] ?? ''));
            if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Ongeldig afzenderadres.');
            }
            if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Ongeldig reply-to-adres.');
            }
            if ($manager !== '' && !filter_var($manager, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Ongeldig manageradres.');
            }
            $app->settings()->upsert('mail_from_name', $fromName !== '' ? $fromName : null);
            $app->settings()->upsert('mail_from_email', $fromEmail !== '' ? $fromEmail : null);
            $app->settings()->upsert('mail_reply_to', $replyTo !== '' ? $replyTo : null);
            $app->settings()->upsert('manager_email', $manager !== '' ? $manager : null);
            if ($fromEmail !== '') {
                $app->settings()->upsert('contact_email', $fromEmail);
            }
            admin_publish_public_rates($app);
            admin_set_flash('success', 'E-mailgegevens opgeslagen. Nieuwe aanvragen gaan naar het manageradres.');
        } elseif ($action === 'send_test') {
            $to = trim((string) ($_POST['to'] ?? $user['email']));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Ongeldig testadres.');
            }
            $result = $app->email()->sendTemplate('test_email', $to, ['language' => 'nl']);
            if ($result['sent']) {
                admin_set_flash('success', 'Testmail verstuurd naar ' . $to . '.');
            } else {
                $error = (string) ($result['error'] ?? 'onbekend');
                if ($error === 'smtp_not_configured') {
                    $error = 'Geen mailer ingesteld. Op Railway: zet RESEND_API_KEY. Lokaal kan SMTP (MailProtect) werken als uw IP is toegelaten.';
                }
                admin_set_flash('error', 'Verzenden mislukt: ' . $error);
            }
        } elseif ($action === 'retry') {
            $id = (int) ($_POST['id'] ?? 0);
            $result = $app->email()->retry($id);
            admin_set_flash(
                $result['sent'] ? 'success' : 'error',
                $result['sent'] ? 'Opnieuw verstuurd.' : ('Opnieuw mislukt: ' . ($result['error'] ?? 'onbekend'))
            );
        }
    } catch (Throwable $e) {
        admin_handle_action_error($e);
    }
    admin_redirect('email.php');
}

$failed = $app->emailLogs()->findFailed(50);
$smtpOk = $config->smtpConfigured();
$resendOk = $config->resendConfigured();
$sendPath = $app->email()->sendPath();
$mailOk = $app->email()->mailConfigured();
$s = $app->settings();

$sendPathLabel = match ($sendPath) {
    'resend' => 'Resend (HTTP-API) — productiepad',
    'smtp' => 'SMTP — lokaal/dev',
    default => 'Geen — berichten worden gelogd als mislukt tot Resend (of lokale SMTP) is ingesteld',
};

admin_layout_start('E-mail', 'email', $user);
?>
<section class="admin-card">
    <h2>Verzenden</h2>
    <div class="admin-confirm-box">
        <p class="admin-help" style="margin:0 0 0.6rem;">
            Combell MailProtect (<code>smtp-auth.mailprotect.be</code>) laat doorgaans alleen verbindingen toe vanaf hun eigen hosting.
            Railway-IPs krijgen een TCP-timeout (geen inlogfout). Poort 587 of 465 maakt geen verschil.
            Productie moet een HTTP-mailer gebruiken: Resend (<code>RESEND_API_KEY</code> op Railway), met geverifieerd afzenderdomein (hometerboekt.be).
        </p>
        <?php if ($config->smtpUnreachableFromThisHost() && !$resendOk): ?>
            <p class="admin-help" style="margin:0;color:#8a241c;">
                Deze server draait op Railway en MailProtect is het enige pad. SMTP wordt niet geprobeerd (dat zou ~20s time-outen). Zet <code>RESEND_API_KEY</code> op de Railway-service.
            </p>
        <?php endif; ?>
    </div>
    <dl class="admin-dl">
        <dt>Actief pad</dt>
        <dd><?= h($sendPathLabel) ?></dd>
        <dt>Resend</dt>
        <dd><?= $resendOk ? 'Ja — HTTP-API ingesteld' : 'Nee — RESEND_API_KEY ontbreekt' ?></dd>
        <dt>SMTP-gegevens aanwezig</dt>
        <dd><?= $smtpOk ? 'Ja' : 'Nee' ?></dd>
        <dt>Verzenden mogelijk</dt>
        <dd><?= $mailOk ? 'Ja' : 'Nee — testmail hangt niet; de fout legt uit wat ontbreekt' ?></dd>
        <dt>Afzender (server)</dt>
        <dd><?= h($config->smtpFromName) ?> &lt;<?= h($config->smtpFromEmail) ?>&gt;</dd>
        <dt>Reply-to (server)</dt>
        <dd><?= h($config->smtpReplyTo) ?></dd>
        <dt>Aanvragen gaan naar</dt>
        <dd><?= h($app->email()->managerEmail() ?: '—') ?></dd>
        <dt>SMTP-host</dt>
        <dd><?= h($config->smtpHost ?: '—') ?></dd>
        <dt>SMTP-wachtwoord</dt>
        <dd>Niet zichtbaar</dd>
    </dl>
</section>

<section class="admin-card">
    <h2>Afzendergegevens</h2>
    <p class="admin-help">Deze velden worden bewaard in de instellingen. De echte envelop komt uit de serverconfiguratie hierboven.</p>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="save_identity">
        <div class="form-row">
            <div class="form-group">
                <label for="mail_from_name">Van naam</label>
                <input type="text" id="mail_from_name" name="mail_from_name" value="<?= h((string) ($s->get('mail_from_name', $config->smtpFromName) ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="mail_from_email">Van e-mail</label>
                <input type="email" id="mail_from_email" name="mail_from_email" value="<?= h((string) ($s->get('mail_from_email', $config->smtpFromEmail) ?? '')) ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="mail_reply_to">Reply-to</label>
                <input type="email" id="mail_reply_to" name="mail_reply_to" value="<?= h((string) ($s->get('mail_reply_to', $config->smtpReplyTo) ?? '')) ?>">
            </div>
            <div class="form-group">
                <label for="manager_email">Manager e-mail (aanvragen + goedkeuren)</label>
                <input type="email" id="manager_email" name="manager_email" value="<?= h((string) ($s->get('manager_email', $config->managerEmail) ?? '')) ?>" placeholder="bijv. mama@…">
                <p class="form-help">Hier komt de mail bij een nieuwe reservatie, met een link naar beheer om het voorschot te bevestigen. Geen publieke goedkeuringslink.</p>
            </div>
        </div>
        <button class="btn btn-primary" type="submit">Bewaar gegevens</button>
    </form>
</section>

<section class="admin-card">
    <h2>Testmail</h2>
    <p class="admin-help">Zelfde pad als gast- en managermails bij een boeking. Boekingen blijven bewaard als mail mislukt.</p>
    <form class="admin-form" method="post">
        <?= admin_csrf_field() ?>
        <input type="hidden" name="action" value="send_test">
        <div class="form-group">
            <label for="to">Naar</label>
            <input type="email" id="to" name="to" value="<?= h((string) $user['email']) ?>" required>
        </div>
        <button class="btn btn-secondary" type="submit">Verstuur test</button>
    </form>
</section>

<section class="admin-card">
    <h2>Mislukte berichten</h2>
    <?php if ($failed === []): ?>
        <p class="admin-empty">Geen mislukte leveringen.</p>
    <?php else: ?>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Wanneer</th><th>Sjabloon</th><th>Naar</th><th>Fout</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($failed as $row): ?>
                    <tr>
                        <td><?= h(admin_format_dt((string) $row['created_at'])) ?></td>
                        <td><?= h((string) $row['template_key']) ?></td>
                        <td><?= h((string) $row['to_email']) ?></td>
                        <td><?= h((string) ($row['error'] ?: '—')) ?></td>
                        <td>
                            <form method="post">
                                <?= admin_csrf_field() ?>
                                <input type="hidden" name="action" value="retry">
                                <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                <button class="btn btn-outline" type="submit">Opnieuw</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php
admin_layout_end();
