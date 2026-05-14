<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="app-name" content="{{ config('app.name', 'Repair Service Management System') }}">
    <meta name="app-tagline" content="{{ config('repair.tagline', 'Electronics Repair Service Management') }}">
    <meta name="admin-login-hint" content="{{ config('repair.admin_login_hint', 'admin@example.com') }}">
    <title>{{ config('app.name', 'Repair Service Management System') }}</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
    @vite('resources/js/app.js')
</head>
<body class="bg-gray-50">
    <div id="app"></div>
</body>
</html>
