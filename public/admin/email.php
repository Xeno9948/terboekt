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
            admin_publish_public_rates($app);
            admin_set_flash('success', 'E-mailgegevens opgeslagen.');
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
                    $error = 'Geen mailer ingesteld. Zet RESEND_API_KEY op Railway.';
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
$resendOk = $config->resendConfigured();
$mailOk = $app->email()->mailConfigured();
$s = $app->settings();
$path = $app->email()->sendPath();
$pathLabel = match ($path) {
    'resend' => 'Resend',
    'smtp' => 'SMTP',
    default => 'Niet ingesteld',
};

admin_layout_start('E-mail', 'email', $user);
?>
<section class="admin-card">
    <h2>Status</h2>
    <p class="admin-status-line">
        <span class="admin-badge <?= $mailOk ? 'is-confirmed' : 'is-negative' ?>"><?= $mailOk ? 'Verzenden lukt' : 'Verzenden faalt' ?></span>
        <span><?= h($pathLabel) ?></span>
    </p>
    <?php if ($config->smtpUnreachableFromThisHost() && !$resendOk): ?>
        <p class="admin-help">Zet <code>RESEND_API_KEY</code> op Railway. SMTP vanaf deze host werkt niet.</p>
    <?php endif; ?>
</section>

<section class="admin-card">
    <h2>Adressen</h2>
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
                <label for="manager_email">Aanvragen naar</label>
                <input type="email" id="manager_email" name="manager_email" value="<?= h((string) ($s->get('manager_email', $config->managerEmail) ?? '')) ?>">
            </div>
        </div>
        <button class="btn btn-primary" type="submit">Bewaar</button>
    </form>
</section>

<section class="admin-card">
    <h2>Testmail</h2>
    <form class="admin-form admin-inline-form" method="post">
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
            <table class="admin-table admin-table-cards">
                <thead>
                    <tr><th>Wanneer</th><th>Sjabloon</th><th>Naar</th><th>Fout</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($failed as $row): ?>
                    <tr>
                        <td data-label="Wanneer"><?= h(admin_format_dt((string) $row['created_at'])) ?></td>
                        <td data-label="Sjabloon"><?= h((string) $row['template_key']) ?></td>
                        <td data-label="Naar"><?= h((string) $row['to_email']) ?></td>
                        <td data-label="Fout"><?= h((string) ($row['error'] ?: '—')) ?></td>
                        <td data-label="">
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
