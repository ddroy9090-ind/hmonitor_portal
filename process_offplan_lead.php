<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

hh_session_start();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '/'));
    exit;
}

$redirectTarget = trim((string) ($_POST['redirect'] ?? '/'));
if ($redirectTarget === '' || strpbrk($redirectTarget, "\r\n") !== false || preg_match('#^(?:https?:)?//#i', $redirectTarget)) {
    $redirectTarget = '/';
}
if ($redirectTarget === '' || $redirectTarget[0] !== '/') {
    $redirectTarget = '/' . ltrim($redirectTarget, '/');
}

$redirectWithError = static function (string $message) use ($redirectTarget): void {
    $_SESSION['offplan_lead_error'] = $message;
    header('Location: ' . $redirectTarget);
    exit;
};

$formType = strtolower(trim((string) ($_POST['form_type'] ?? 'popup')));
if ($formType !== 'brochure') {
    $formType = 'popup';
}

if ($formType === 'brochure') {
    $nameInput = trim((string) ($_POST['brochure_name'] ?? ''));
    $emailInput = trim((string) ($_POST['brochure_email'] ?? ''));
    $countryInput = trim((string) ($_POST['brochure_country'] ?? ''));
    $phoneInput = trim((string) ($_POST['brochure_phone'] ?? ''));
    $brochureUrlInput = (string) ($_POST['brochure_url'] ?? '');
} else {
    $nameInput = trim((string) ($_POST['name'] ?? ''));
    $emailInput = trim((string) ($_POST['email'] ?? ''));
    $countryInput = trim((string) ($_POST['country'] ?? ''));
    $phoneInput = trim((string) ($_POST['phone'] ?? ''));
    $brochureUrlInput = (string) ($_POST['brochure_url'] ?? '');
}

if ($nameInput === '' || $emailInput === '' || $countryInput === '' || $phoneInput === '') {
    $redirectWithError('Please fill in all required fields with valid details.');
}

$emailValidated = filter_var($emailInput, FILTER_VALIDATE_EMAIL);
if ($emailValidated === false) {
    $redirectWithError('Please enter a valid email address.');
}

$recaptchaResponse = trim((string) ($_POST['g-recaptcha-response'] ?? ''));
if ($recaptchaResponse === '') {
    $redirectWithError('Please verify the reCAPTCHA.');
}

$secretKey = hh_recaptcha_secret_key();
if ($secretKey === '' || $secretKey === 'your-secret-key-here') {
    error_log('Off-plan lead form: reCAPTCHA secret key is not configured.');
    $redirectWithError('Captcha verification is temporarily unavailable. Please try again later.');
}

$postData = http_build_query([
    'secret'   => $secretKey,
    'response' => $recaptchaResponse,
    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
]);

$context = stream_context_create([
    'http' => [
        'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
        'method'  => 'POST',
        'content' => $postData,
        'timeout' => 10,
    ],
]);

$verifyResponse = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
if ($verifyResponse === false && function_exists('curl_init')) {
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
    if ($ch !== false) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postData,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $curlResponse = curl_exec($ch);
        if ($curlResponse !== false) {
            $verifyResponse = $curlResponse;
        }
        curl_close($ch);
    }
}

$recaptchaVerified = false;
if ($verifyResponse !== false) {
    $decodedResponse = json_decode((string) $verifyResponse, true);
    $recaptchaVerified = is_array($decodedResponse) && ($decodedResponse['success'] ?? false) === true;
}

if (!$recaptchaVerified) {
    $redirectWithError('reCAPTCHA verification failed. Please try again.');
}

$pdo = hh_db();

$propertyId = isset($_POST['property_id']) ? (int) $_POST['property_id'] : 0;
$propertyTitle = trim((string) ($_POST['property_title'] ?? ''));

if ($propertyTitle === '' && $propertyId > 0) {
    $propertyTitleCandidates = [
        ['table' => 'properties_list', 'columns' => ['property_title', 'project_name']],
        ['table' => 'buy_properties_list', 'columns' => ['property_title', 'project_name', 'title']],
        ['table' => 'rent_properties_list', 'columns' => ['property_title', 'project_name', 'title']],
    ];

    foreach ($propertyTitleCandidates as $candidate) {
        try {
            $columns = array_unique($candidate['columns']);
            $columnList = implode(', ', array_map(static fn(string $column): string => '`' . $column . '`', $columns));
            $stmt = $pdo->prepare("SELECT $columnList FROM {$candidate['table']} WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $propertyId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $exception) {
            $row = false;
        }

        if (is_array($row)) {
            foreach ($candidate['columns'] as $column) {
                if (!empty($row[$column]) && is_string($row[$column])) {
                    $propertyTitle = trim($row[$column]);
                    if ($propertyTitle !== '') {
                        break 2;
                    }
                }
            }
        }
    }
}

$name = mb_substr($nameInput, 0, 150);
$email = mb_substr(strtolower($emailValidated), 0, 190);
$country = mb_substr($countryInput, 0, 150);
$phone = mb_substr(preg_replace('/[^0-9+()\-\s]/', '', $phoneInput), 0, 64);
$propertyTitle = mb_substr($propertyTitle, 0, 190);

$normalizeBrochureUrl = static function (string $value): string {
    $value = trim(str_replace('\\', '/', $value));
    if ($value === '') {
        return '';
    }

    if (preg_match('#^(https?:)?//#i', $value)) {
        return $value;
    }

    return '/' . ltrim($value, '/');
};

$brochureUrl = '';
if ($formType === 'brochure') {
    $brochureUrl = $normalizeBrochureUrl($brochureUrlInput);
}

$ipAddress = mb_substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 100);
$userAgent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO offplan_leads '
        . '(lead_type, property_id, property_title, name, email, phone, country, brochure_url, ip_address, user_agent, created_at) '
        . 'VALUES (:lead_type, :property_id, :property_title, :name, :email, :phone, :country, :brochure_url, :ip_address, :user_agent, NOW())'
    );

    $stmt->execute([
        ':lead_type'      => $formType,
        ':property_id'    => $propertyId,
        ':property_title' => $propertyTitle !== '' ? $propertyTitle : null,
        ':name'           => $name,
        ':email'          => $email,
        ':phone'          => $phone,
        ':country'        => $country,
        ':brochure_url'   => $brochureUrl !== '' ? $brochureUrl : null,
        ':ip_address'     => $ipAddress !== '' ? $ipAddress : null,
        ':user_agent'     => $userAgent !== '' ? $userAgent : null,
    ]);
} catch (Throwable $exception) {
    error_log('Off-plan lead form: database error - ' . $exception->getMessage());
    $redirectWithError('We could not process your request at this time. Please try again later.');
}

unset($_SESSION['offplan_lead_error']);

if ($formType === 'brochure' && $brochureUrl !== '') {
    $_SESSION['download_brochure_url'] = $brochureUrl;
}

header('Location: thankyou.php');
exit;
