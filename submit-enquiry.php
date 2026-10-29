<?php
declare(strict_types=1);

/**
 * E'lique Advisory - Contact Form Handler
 * Loads configuration from .env, logs enquiries to phpMyAdmin MySQL (when enabled),
 * and dispatches HTML emails via cPanel SMTP (with automatic PHP mail() fallback).
 */

// ============================================================================
// 1. LOAD .ENV FILE & CONFIGURATION
// ============================================================================
function loadDotEnv(string $filePath): array
{
    $vars = [];
    if (!is_readable($filePath)) {
        return $vars;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $vars;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $key = trim($parts[0]);
        $val = trim($parts[1]);

        // Strip surrounding quotes if present
        if (
            (str_starts_with($val, '"') && str_ends_with($val, '"')) ||
            (str_starts_with($val, "'") && str_ends_with($val, "'"))
        ) {
            $val = substr($val, 1, -1);
        }

        $vars[$key] = $val;
        $_ENV[$key] = $val;
    }

    return $vars;
}

$env = loadDotEnv(__DIR__ . '/.env');
$envVal = static function (string $key, string $default = '') use ($env): string {
    if (isset($env[$key]) && $env[$key] !== '') {
        return $env[$key];
    }
    $sys = getenv($key);
    return ($sys !== false && $sys !== '') ? (string)$sys : $default;
};

$config = [
    // phpMyAdmin / cPanel MySQL Settings
    'enable_db'  => filter_var($envVal('DB_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
    'db_host'    => $envVal('DB_HOST', 'localhost'),
    'db_port'    => $envVal('DB_PORT', '3306'),
    'db_name'    => $envVal('DB_DATABASE', 'cpaneluser_elique_db'),
    'db_user'    => $envVal('DB_USERNAME', 'cpaneluser_elique_user'),
    'db_pass'    => $envVal('DB_PASSWORD', ''),
    'db_charset' => $envVal('DB_CHARSET', 'utf8mb4'),

    // cPanel SMTP & Email Settings
    'mail_mailer'     => strtolower($envVal('MAIL_MAILER', 'smtp')),
    'smtp_host'       => $envVal('SMTP_HOST', 'mail.eliqueadvisory.com'),
    'smtp_port'       => (int)$envVal('SMTP_PORT', '465'),
    'smtp_encryption' => strtolower($envVal('SMTP_ENCRYPTION', 'ssl')),
    'smtp_user'       => $envVal('SMTP_USERNAME', 'no-reply@eliqueadvisory.com'),
    'smtp_pass'       => $envVal('SMTP_PASSWORD', ''),

    'recipient_email' => $envVal('MAIL_TO_ADDRESS', 'hello@eliqueadvisory.com'),
    'from_email'      => $envVal('MAIL_FROM_ADDRESS', 'no-reply@eliqueadvisory.com'),
    'from_name'       => $envVal('MAIL_FROM_NAME', "E'lique Advisory"),
];

// Always return JSON for AJAX requests
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Please submit the form using POST.'
    ]);
    exit;
}

// Support both standard form submissions and JSON payloads
$input = $_POST;
if (empty($input)) {
    $rawBody = file_get_contents('php://input');
    if (!empty($rawBody)) {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
}

// ============================================================================
// 2. SPAM PROTECTION & INPUT VALIDATION
// ============================================================================

// Honeypot check: bots fill hidden fields, humans leave them blank
if (!empty($input['website'])) {
    echo json_encode([
        'success' => true,
        'message' => "Thank you for contacting E'lique Advisory."
    ]);
    exit;
}

$name        = trim(strip_tags((string)($input['name'] ?? '')));
$email       = trim(filter_var((string)($input['email'] ?? ''), FILTER_SANITIZE_EMAIL));
$company     = trim(strip_tags((string)($input['company'] ?? '')));
$country     = trim(strip_tags((string)($input['country'] ?? '')));
$enquiryType = trim(strip_tags((string)($input['enquiry_type'] ?? '')));
$message     = trim(strip_tags((string)($input['message'] ?? '')));

$errors = [];

if ($name === '' || mb_strlen($name) < 2) {
    $errors[] = 'Please provide your full name.';
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide a valid email address.';
}
if ($company === '') {
    $errors[] = 'Please provide your company or organisation name.';
}
if ($country === '') {
    $errors[] = 'Please select your country.';
}
if ($enquiryType === '') {
    $errors[] = 'Please select an enquiry type.';
}
if ($message === '' || mb_strlen($message) < 5) {
    $errors[] = 'Please enter a message describing your enquiry.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => implode(' ', $errors),
        'errors'  => $errors
    ]);
    exit;
}

// Prevent email header injection in single-line fields
$cleanHeaderValue = static function (string $value): string {
    return str_replace(["\r", "\n", "%0a", "%0d"], '', $value);
};

$safeName        = $cleanHeaderValue($name);
$safeEmail       = $cleanHeaderValue($email);
$safeCompany     = $cleanHeaderValue($company);
$safeCountry     = $cleanHeaderValue($country);
$safeEnquiryType = $cleanHeaderValue($enquiryType);

$ipAddress = isset($_SERVER['REMOTE_ADDR']) ? substr((string)$_SERVER['REMOTE_ADDR'], 0, 45) : null;
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null;

// ============================================================================
// 3. OPTIONAL: LOG TO MYSQL DATABASE (phpMyAdmin on cPanel)
// ============================================================================
$dbLogged = false;
$insertedId = null;
$pdo = null;

if (!empty($config['enable_db']) && $config['db_pass'] !== '' && $config['db_pass'] !== 'your_cpanel_db_password_here') {
    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $config['db_host'],
            $config['db_port'],
            $config['db_name'],
            $config['db_charset']
        );

        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Ensure the table exists automatically
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `enquiries` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(150) NOT NULL,
                `email` VARCHAR(191) NOT NULL,
                `company` VARCHAR(191) NOT NULL,
                `country` VARCHAR(100) NOT NULL,
                `enquiry_type` VARCHAR(100) NOT NULL,
                `message` TEXT NOT NULL,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` VARCHAR(255) DEFAULT NULL,
                `email_sent` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_email` (`email`),
                INDEX `idx_enquiry_type` (`enquiry_type`),
                INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $stmt = $pdo->prepare("
            INSERT INTO `enquiries` (
                `name`, `email`, `company`, `country`, `enquiry_type`, `message`, `ip_address`, `user_agent`, `email_sent`
            ) VALUES (
                :name, :email, :company, :country, :enquiry_type, :message, :ip_address, :user_agent, 0
            )
        ");

        $stmt->execute([
            ':name'         => $name,
            ':email'        => $email,
            ':company'      => $company,
            ':country'      => $country,
            ':enquiry_type' => $enquiryType,
            ':message'      => $message,
            ':ip_address'   => $ipAddress,
            ':user_agent'   => $userAgent,
        ]);

        $insertedId = (int)$pdo->lastInsertId();
        $dbLogged = true;
    } catch (PDOException $e) {
        error_log("E'lique Advisory DB Error: " . $e->getMessage());
    }
}

// ============================================================================
// 4. SEND EMAIL VIA CPANEL SMTP (WITH AUTOMATIC PHP MAIL() FALLBACK)
// ============================================================================

/**
 * Dispatches an HTML email using authenticated SMTP if configured in .env,
 * falling back to native PHP mail() on cPanel if SMTP password is not set yet.
 */
function dispatchEmail(
    array $config,
    string $toEmail,
    string $subject,
    string $htmlBody,
    string $replyToEmail,
    string $replyToName = ''
): bool {
    $fromEmail = $config['from_email'];
    $fromName  = $config['from_name'];

    $useSmtp = (
        $config['mail_mailer'] === 'smtp' &&
        $config['smtp_host'] !== '' &&
        $config['smtp_user'] !== '' &&
        $config['smtp_pass'] !== '' &&
        $config['smtp_pass'] !== 'your_cpanel_email_password_here'
    );

    if ($useSmtp) {
        // On cPanel Exim servers, the envelope sender (MAIL FROM) must match the authenticated SMTP user
        $smtpFromEmail = filter_var($config['smtp_user'], FILTER_VALIDATE_EMAIL)
            ? $config['smtp_user']
            : $fromEmail;

        $smtpSent = sendViaSmtp(
            $config['smtp_host'],
            $config['smtp_port'],
            $config['smtp_encryption'],
            $config['smtp_user'],
            $config['smtp_pass'],
            $smtpFromEmail,
            $fromName,
            $toEmail,
            $replyToEmail,
            $replyToName,
            $subject,
            $htmlBody
        );
        if ($smtpSent) {
            return true;
        }
    }

    // Fallback to native cPanel PHP mail()
    // Format headers and subject according to RFC 2047 to prevent MTA rejection on Exim / remote mail servers
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $encodedSubject  = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $replyHeader = $replyToName !== ''
        ? sprintf('Reply-To: =?UTF-8?B?%s?= <%s>', base64_encode($replyToName), $replyToEmail)
        : sprintf('Reply-To: <%s>', $replyToEmail);

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        sprintf('From: %s <%s>', $encodedFromName, $fromEmail),
        $replyHeader,
        'X-Mailer: PHP/' . PHP_VERSION,
    ];

    // The -f flag sets the envelope sender (MAIL FROM) in Exim, preventing cPanel from falling back to unix user
    $mailParams = '-f ' . escapeshellarg($fromEmail);

    return @mail($toEmail, $encodedSubject, $htmlBody, implode("\r\n", $headers), $mailParams);
}

/**
 * Lightweight, zero-dependency SMTP client supporting SSL (465) and STARTTLS (587).
 */
function sendViaSmtp(
    string $host,
    int $port,
    string $encryption,
    string $username,
    string $password,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $replyToEmail,
    string $replyToName,
    string $subject,
    string $htmlBody
): bool {
    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ]);

    $fp = @stream_socket_client($remote, $errno, $errstr, 12, STREAM_CLIENT_CONNECT, $context);
    if (!$fp) {
        error_log("E'lique SMTP Connect Error ($errno): $errstr");
        return false;
    }

    stream_set_timeout($fp, 12);

    $readResponse = static function ($fp): string {
        $data = '';
        while ($str = fgets($fp, 515)) {
            $data .= $str;
            if (isset($str[3]) && $str[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $sendCmd = static function ($fp, string $cmd, array $expectedCodes) use ($readResponse): bool {
        fwrite($fp, $cmd . "\r\n");
        $resp = $readResponse($fp);
        $code = (int)substr($resp, 0, 3);
        return in_array($code, $expectedCodes, true);
    };

    $greeting = $readResponse($fp);
    if ((int)substr($greeting, 0, 3) !== 220) {
        fclose($fp);
        return false;
    }

    $serverName = $_SERVER['SERVER_NAME'] ?? 'eliqueadvisory.com';
    if (!$sendCmd($fp, "EHLO {$serverName}", [250])) {
        fclose($fp);
        return false;
    }

    if ($encryption === 'tls') {
        if (!$sendCmd($fp, 'STARTTLS', [220])) {
            fclose($fp);
            return false;
        }
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        if (!$sendCmd($fp, "EHLO {$serverName}", [250])) {
            fclose($fp);
            return false;
        }
    }

    if (
        !$sendCmd($fp, 'AUTH LOGIN', [334]) ||
        !$sendCmd($fp, base64_encode($username), [334]) ||
        !$sendCmd($fp, base64_encode($password), [235]) ||
        !$sendCmd($fp, "MAIL FROM:<{$fromEmail}>", [250]) ||
        !$sendCmd($fp, "RCPT TO:<{$toEmail}>", [250, 251]) ||
        !$sendCmd($fp, 'DATA', [354])
    ) {
        fclose($fp);
        return false;
    }

    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $replyHeader = $replyToName !== ''
        ? sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($replyToName), $replyToEmail)
        : sprintf('<%s>', $replyToEmail);

    $headers = [
        'Date: ' . date('r'),
        sprintf('From: %s <%s>', $encodedFromName, $fromEmail),
        sprintf('To: <%s>', $toEmail),
        sprintf('Reply-To: %s', $replyHeader),
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
    ];

    $encodedBody = chunk_split(base64_encode($htmlBody));
    $payload = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody . "\r\n.";

    $ok = $sendCmd($fp, $payload, [250]);
    $sendCmd($fp, 'QUIT', [221, 250]);
    fclose($fp);

    return $ok;
}

// All form submissions are routed strictly to the designated advisory inbox
$to = $config['recipient_email'];

// Sanitize subject to safe ASCII to ensure strict compatibility with all MTAs and Exim filters
$cleanEnquiryType = preg_replace('/[^A-Za-z0-9 _-]/', '', $enquiryType);
$cleanName        = preg_replace('/[^A-Za-z0-9 _-]/', '', $name);
$cleanCompany     = preg_replace('/[^A-Za-z0-9 _-]/', '', $company);

$subject = sprintf(
    "[Elique Advisory] New %s Enquiry from %s",
    $cleanEnquiryType !== '' ? $cleanEnquiryType : 'Website',
    $cleanName !== '' ? $cleanName : 'Visitor'
);
if ($cleanCompany !== '') {
    $subject .= " (" . $cleanCompany . ")";
}

$submittedAt        = gmdate('Y-m-d H:i:s') . ' UTC';
$escapedName        = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$escapedEmail       = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$escapedCompany     = htmlspecialchars($company, ENT_QUOTES, 'UTF-8');
$escapedCountry     = htmlspecialchars($country, ENT_QUOTES, 'UTF-8');
$escapedEnquiryType = htmlspecialchars($enquiryType, ENT_QUOTES, 'UTF-8');
$escapedMessage     = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

$htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New Website Enquiry</title>
</head>
<body style="margin:0;padding:24px;background-color:#FAF8F5;font-family:Arial,Helvetica,sans-serif;color:#1F1624;">
    <table width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;margin:0 auto;background:#FFFFFF;border:1px solid #E5DFD5;border-radius:8px;overflow:hidden;">
        <tr>
            <td style="background:#20002C;padding:24px 32px;border-bottom:3px solid #C4A052;">
                <h1 style="margin:0;font-size:20px;color:#FFFFFF;font-weight:600;">E'lique Advisory — Website Enquiry</h1>
                <p style="margin:6px 0 0;font-size:13px;color:#D8B464;">Submitted on {$submittedAt}</p>
            </td>
        </tr>
        <tr>
            <td style="padding:28px 32px;">
                <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse:collapse;font-size:14px;">
                    <tr>
                        <td width="150" style="font-weight:bold;color:#5D5363;border-bottom:1px solid #F0ECE1;">Full Name</td>
                        <td style="color:#180022;border-bottom:1px solid #F0ECE1;">{$escapedName}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;color:#5D5363;border-bottom:1px solid #F0ECE1;">Email Address</td>
                        <td style="color:#180022;border-bottom:1px solid #F0ECE1;"><a href="mailto:{$escapedEmail}" style="color:#C4A052;text-decoration:none;">{$escapedEmail}</a></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;color:#5D5363;border-bottom:1px solid #F0ECE1;">Company / Org</td>
                        <td style="color:#180022;border-bottom:1px solid #F0ECE1;">{$escapedCompany}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;color:#5D5363;border-bottom:1px solid #F0ECE1;">Country</td>
                        <td style="color:#180022;border-bottom:1px solid #F0ECE1;">{$escapedCountry}</td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold;color:#5D5363;border-bottom:1px solid #F0ECE1;">Enquiry Type</td>
                        <td style="color:#180022;border-bottom:1px solid #F0ECE1;"><strong>{$escapedEnquiryType}</strong></td>
                    </tr>
                </table>

                <div style="margin-top:22px;">
                    <div style="font-size:13px;font-weight:bold;color:#5D5363;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;">Message</div>
                    <div style="padding:16px;background:#FAF8F5;border-left:3px solid #C4A052;border-radius:4px;font-size:14px;line-height:1.6;color:#180022;">
                        {$escapedMessage}
                    </div>
                </div>
            </td>
        </tr>
        <tr>
            <td style="background:#F6F3EC;padding:14px 32px;font-size:12px;color:#7A7080;text-align:center;">
                Reply directly to this email to respond to {$escapedName} ({$escapedEmail}).
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

$emailSent = dispatchEmail($config, $to, $subject, $htmlBody, $safeEmail, $safeName);

// If database insert succeeded and email was sent, mark email_sent = 1 in phpMyAdmin
if ($dbLogged && $emailSent && $pdo !== null && $insertedId !== null) {
    try {
        $updateStmt = $pdo->prepare("UPDATE `enquiries` SET `email_sent` = 1 WHERE `id` = :id");
        $updateStmt->execute([':id' => $insertedId]);
    } catch (PDOException $e) {
        error_log("E'lique Advisory DB Update Error: " . $e->getMessage());
    }
}

// ============================================================================
// 5. RETURN RESPONSE
// ============================================================================
if (!$dbLogged && !$emailSent) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'We could not process your enquiry at this moment. Please email us directly at ' . $config['recipient_email'] . '.'
    ]);
    exit;
}

echo json_encode([
    'success'    => true,
    'message'    => "Thank you for contacting E'lique Advisory. We've received your enquiry and a member of our team will respond within one business day.",
    'db_logged'  => $dbLogged,
    'email_sent' => $emailSent,
]);
