<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

hh_session_start();

$downloadBrochureUrl = '';
if (!empty($_SESSION['download_brochure_url'])) {
    $downloadBrochureUrl = (string) $_SESSION['download_brochure_url'];
    unset($_SESSION['download_brochure_url']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thank You - HouzzHunt Mortgage</title>
    <link rel="shortcut icon" href="assets/images/logo/favicon.png" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }

        .thankyou-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .thankyou-box {
            background: #fff;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.08);
        }

        .thankyou-box h1 {
            font-size: 2.5rem;
            color: #004a44;
        }

        .thankyou-box p {
            font-size: 16px;
            color: #555;
        }

        .btn-home {
            margin-top: 20px;
            background-color: #004a44;
            color: #fff;
            transition: ease;
            /* border: none; */
            border-radius: 50px;
        }

        .btn-home:hover {
            background-color: #edbb68;
            color: #111;
            border-radius: 50px;
        }
    </style>
</head>

<body>

    <div class="thankyou-container">
        <div class="thankyou-box">
            <h1>Thank You!</h1>
            <p>Your submission has been received. One of expert will contact you shortly.</p>
            <a href="index.php" class="btn btn-home">Back to Home</a>
        </div>
    </div>

    <?php if ($downloadBrochureUrl !== ''): ?>
        <script>
            window.addEventListener('DOMContentLoaded', function () {
                var link = document.createElement('a');
                link.href = <?= json_encode($downloadBrochureUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
                link.download = '';
                link.rel = 'noopener';
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                window.setTimeout(function () {
                    if (link.parentNode) {
                        link.parentNode.removeChild(link);
                    }
                }, 500);
            });
        </script>
    <?php endif; ?>
</body>

</html>
