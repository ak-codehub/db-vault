<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('dbvault.two_factor.issuer', 'DB Vault') }}</title>

    {{--
        Boot payload consumed by resources/js/api.js. `basePath` configures
        vue-router's history base, `apiBase` the axios baseURL, and `csrf`
        guards every mutating request the same way Laravel's VerifyCsrfToken
        middleware expects for cookie-session SPA auth.
    --}}
    <script>
        window.DbVault = {
            basePath: @json($basePath),
            apiBase: @json($apiBase),
            csrf: @json(csrf_token()),
        };
    </script>

    <link rel="stylesheet" href="{{ $cssUrl }}">
</head>
<body class="antialiased">
    <div id="db-vault-app"></div>
    <script type="module" src="{{ $jsUrl }}"></script>
</body>
</html>
