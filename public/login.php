<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/auth/auth.php';

/*
|--------------------------------------------------------------------------
| Conexão com o banco
|--------------------------------------------------------------------------
*/
$dbCandidates = [
    __DIR__ . '/../data/app.sqlite',
    __DIR__ . '/../app.sqlite',
    __DIR__ . '/../database.sqlite',
];

$dbPath = null;
foreach ($dbCandidates as $candidate) {
    if (is_file($candidate)) {
        $dbPath = $candidate;
        break;
    }
}

if ($dbPath === null) {
    http_response_code(500);
    exit('Banco de dados não encontrado.');
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    exit('Erro ao conectar ao banco de dados.');
}

auth_start_session();

if (auth_check()) {
    header('Location: /index.php?page=dashboard');
    exit;
}

$error = '';
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameValue = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($usernameValue === '' || trim($password) === '') {
        $error = 'Informe usuário e senha.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id, username, password_hash, is_active
            FROM users
            WHERE lower(username) = :username
            LIMIT 1
        ");
        $stmt->execute([
            ':username' => auth_mb_strtolower($usernameValue),
        ]);

        $user = $stmt->fetch();

        if (
            !$user ||
            (int)($user['is_active'] ?? 0) !== 1 ||
            !password_verify($password, (string)($user['password_hash'] ?? ''))
        ) {
            $error = 'Usuário ou senha inválidos.';
        } else {
            auth_login($pdo, $usernameValue, $password);

            header('Location: /index.php?page=dashboard');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Login | Palmeiras Manager</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --bg: #0f172a;
            --card: #111827;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --border: rgba(255,255,255,0.10);
            --green: #16a34a;
            --green-dark: #15803d;
            --gray-btn: #374151;
            --gray-btn-dark: #2b3441;
            --danger-border: rgba(220,38,38,0.35);
            --danger-bg: rgba(220,38,38,0.12);
            --input-bg: #0b1220;
            --shadow: 0 20px 50px rgba(0, 0, 0, 0.35);
        }

        * {
            box-sizing: border-box;
        }

        html, body {
            margin: 0;
            padding: 0;
            min-height: 100%;
            font-family: Arial, Helvetica, sans-serif;
            background:
                radial-gradient(circle at top, rgba(22, 163, 74, 0.18), transparent 28%),
                linear-gradient(180deg, #081019 0%, #0f172a 100%);
            color: var(--text);
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .login-shell {
            width: 100%;
            max-width: 460px;
        }

        .login-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .login-header {
            padding: 28px 28px 18px;
            text-align: center;
            background: linear-gradient(180deg, rgba(22,163,74,0.14), rgba(22,163,74,0.03));
            border-bottom: 1px solid var(--border);
        }

        .logo-badge {
            width: 96px;
            height: 96px;
            margin: 0 auto 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-badge img {
            display: block;
            max-width: 100%;
            max-height: 100%;
            width: auto;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 10px 25px rgba(22, 163, 74, 0.28));
        }

        .login-title {
            margin: 0;
            font-size: 28px;
            line-height: 1.2;
            font-weight: 700;
        }

        .login-subtitle {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .login-body {
            padding: 24px 28px 28px;
        }

        .alert {
            margin-bottom: 18px;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 14px;
        }

        .alert-danger {
            border: 1px solid var(--danger-border);
            background: var(--danger-bg);
            color: #fecaca;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 600;
            color: var(--text);
        }

        .form-control {
            width: 100%;
            height: 46px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--input-bg);
            color: var(--text);
            padding: 0 14px;
            font-size: 15px;
            outline: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-control:focus {
            border-color: rgba(22,163,74,0.65);
            box-shadow: 0 0 0 4px rgba(22,163,74,0.18);
        }

        .btn-stack {
            display: grid;
            gap: 10px;
            margin-top: 20px;
        }

        .btn {
            display: inline-flex;
            width: 100%;
            height: 46px;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: transform 0.05s ease, opacity 0.15s ease, background 0.15s ease;
        }

        .btn:active {
            transform: translateY(1px);
        }

        .btn-primary {
            background: linear-gradient(180deg, var(--green), var(--green-dark));
            color: #fff;
        }

        .btn-primary:hover {
            opacity: 0.96;
        }

        .btn-secondary {
            background: linear-gradient(180deg, var(--gray-btn), var(--gray-btn-dark));
            color: #fff;
        }

        .btn-secondary:hover {
            opacity: 0.96;
        }

        .helper-links {
            margin-top: 18px;
            text-align: center;
            font-size: 13px;
            color: var(--muted);
        }

        .helper-links a {
            color: #86efac;
            text-decoration: none;
        }

        .helper-links a:hover {
            text-decoration: underline;
        }

        .footer-note {
            margin-top: 16px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
        }

        @media (max-width: 520px) {
            body {
                padding: 16px;
            }

            .login-header,
            .login-body {
                padding-left: 18px;
                padding-right: 18px;
            }

            .login-title {
                font-size: 24px;
            }

            .logo-badge {
                width: 150px;
                height: 150px;
            }
        }
    </style>
</head>
<body>
    <div class="login-shell">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-badge">
                    <img src="/assets/palmeiras_manager.png" alt="Palmeiras Manager">
                </div>
                <h1 class="login-title">Palmeiras Manager</h1>
                <p class="login-subtitle">Acesse seu save para continuar a gestão do clube.</p>
            </div>

            <div class="login-body">
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <form method="post" action="/login.php" autocomplete="off">
                    <div class="form-group">
                        <label for="username">Usuário</label>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            class="form-control"
                            maxlength="80"
                            value="<?= htmlspecialchars($usernameValue, ENT_QUOTES, 'UTF-8') ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="password">Senha</label>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            class="form-control"
                            maxlength="255"
                            required
                        >
                    </div>

                    <div class="btn-stack">
                        <button type="submit" class="btn btn-primary">Entrar</button>
                        <a href="/register.php" class="btn btn-secondary">Criar usuário</a>
                    </div>
                </form>

                <div class="helper-links">
                    Esqueceu a senha?
                    <a href="/reset_password.php">Redefinir senha</a>
                </div>

                <div class="footer-note">
                    Seu save fica vinculado ao usuário autenticado.
                </div>
            </div>
        </div>
    </div>
</body>
</html>