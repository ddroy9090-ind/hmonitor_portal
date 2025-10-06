<?php
/****************************************************
 * PROCESS OFFPLAN LEAD FORM SUBMISSION
 * Table: offplan_leads
 * Config: includes/config.php
 ****************************************************/

require_once __DIR__ . '/includes/config.php';

// --- Initialize PDO from config ---
$pdo = hh_db();
$recaptchaSecret = hh_recaptcha_secret_key();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Validate required fields ---
    if (empty($_POST['name']) || empty($_POST['email']) || empty($_POST['phone']) || empty($_POST['country'])) {
        die('Missing required fields.');
    }

    // --- Verify reCAPTCHA ---
    if (empty($_POST['g-recaptcha-response'])) {
        die('Please verify the reCAPTCHA.');
    }

    $recaptchaResponse = $_POST['g-recaptcha-response'];
    $verify = file_get_contents(
        "https://www.google.com/recaptcha/api/siteverify?secret={$recaptchaSecret}&response={$recaptchaResponse}"
    );
    $captchaResult = json_decode($verify, true);

    if (empty($captchaResult['success']) || !$captchaResult['success']) {
        die('reCAPTCHA verification failed. Please try again.');
    }

    // --- Prepare sanitized inputs ---
    $lead_type      = 'popup';
    $property_id    = 0;
    $property_title = '';
    $name           = trim($_POST['name']);
    $email          = trim($_POST['email']);
    $phone          = trim($_POST['phone']);
    $country        = trim($_POST['country']);
    $brochure_url   = '';
    $ip_address     = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent     = $_SERVER['HTTP_USER_AGENT'] ?? '';

    // --- Insert data ---
    $stmt = $pdo->prepare("
        INSERT INTO offplan_leads 
        (lead_type, property_id, property_title, name, email, phone, country, brochure_url, ip_address, user_agent, created_at)
        VALUES 
        (:lead_type, :property_id, :property_title, :name, :email, :phone, :country, :brochure_url, :ip_address, :user_agent, NOW())
    ");

    $stmt->execute([
        ':lead_type'      => $lead_type,
        ':property_id'    => $property_id,
        ':property_title' => $property_title,
        ':name'           => $name,
        ':email'          => $email,
        ':phone'          => $phone,
        ':country'        => $country,
        ':brochure_url'   => $brochure_url,
        ':ip_address'     => $ip_address,
        ':user_agent'     => $user_agent,
    ]);

    // --- Redirect on success ---
    header('Location: thankyou.php');
    exit;
} else {
    header('Location: index.php');
    exit;
}
?>
