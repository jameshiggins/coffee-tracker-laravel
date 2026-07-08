<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in — Roastmap Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #8B4513 0%, #D2691E 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.25);
            padding: 36px 32px;
            width: 100%;
            max-width: 380px;
        }
        .card h1 { color: #6F4E37; font-size: 1.5em; margin-bottom: 4px; }
        .card p.sub { color: #888; font-size: 14px; margin-bottom: 24px; }
        .field { margin-bottom: 16px; }
        .field label { display: block; margin-bottom: 6px; font-weight: 500; color: #333; font-size: 14px; }
        .field input {
            width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; font-size: 15px;
        }
        .field input:focus { outline: none; border-color: #8B4513; }
        .error {
            background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24;
            padding: 10px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 14px;
        }
        button[type=submit] {
            width: 100%; padding: 12px; border: none; border-radius: 8px; cursor: pointer;
            background: #8B4513; color: white; font-size: 15px; font-weight: 600;
            transition: background 0.2s;
        }
        button[type=submit]:hover { background: #6F4E37; }
    </style>
</head>
<body>
    <main class="card">
        <h1>🫘 Roastmap Admin</h1>
        <p class="sub">Operator console — sign in to continue</p>

        @if ($errors->any())
            <div class="error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('admin.login.attempt') }}">
            @csrf
            <div class="field">
                <label for="username">Username</label>
                <input id="username" name="username" type="text" required autofocus
                       autocomplete="username" spellcheck="false" value="{{ old('username') }}">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input id="password" name="password" type="password" required
                       autocomplete="current-password">
            </div>
            <button type="submit">Sign in</button>
        </form>
    </main>
</body>
</html>
