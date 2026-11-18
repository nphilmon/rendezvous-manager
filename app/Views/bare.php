<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($title ?? $appName ?? '') ?> – RendezVous Manager</title>
<link rel="icon" href="/assets/icons/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/css/app.css">
<script src="/assets/js/theme.js"></script>
</head>
<body class="bare">
<?= $content ?>
<script src="/assets/js/app.js" defer></script>
</body>
</html>
