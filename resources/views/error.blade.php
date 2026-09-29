<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login gagal</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f5f6f8; color: #1f2328; margin: 0; }
        .box { max-width: 420px; margin: 12vh auto; background: #fff; border: 1px solid #e3e5e8; border-radius: 10px; padding: 28px; }
        h1 { font-size: 18px; margin: 0 0 12px; }
        p { margin: 0 0 20px; line-height: 1.5; }
        a { display: inline-block; background: #1f2328; color: #fff; text-decoration: none; padding: 9px 16px; border-radius: 6px; }
    </style>
</head>
<body>
<div class="box">
    <h1>Login SSO gagal</h1>
    <p>{{ $message }}</p>
    <a href="{{ route('sso.login') }}">Coba lagi</a>
</div>
</body>
</html>
