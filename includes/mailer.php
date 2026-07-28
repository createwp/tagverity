<?php
// ─── Mailer — wraps PHPMailer with SendGrid SMTP ─────────────────────────────
require_once __DIR__ . '/config.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send a transactional email via SendGrid SMTP.
 *
 * @param string $to_email   Recipient email address
 * @param string $to_name    Recipient display name
 * @param string $subject    Email subject line
 * @param string $html_body  HTML message body
 * @param string $text_body  Plain-text fallback body (auto-generated from html if empty)
 * @return bool              True on success, false on failure
 */
function send_email(
    string $to_email,
    string $to_name,
    string $subject,
    string $html_body,
    string $text_body = ''
): bool {
    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->Port       = MAIL_PORT;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($to_email, $to_name);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = $text_body ?: strip_tags($html_body);

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[GTMAuditor][Mailer] Failed to send to ' . $to_email . ': ' . $e->getMessage());
        return false;
    }
}
