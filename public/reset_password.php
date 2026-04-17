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

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/
if (!function_exists('pm_reset_password_error')) {
    function pm_reset_password_error(string $password): string
    {
        if (mb_strlen($password) < 8) {
            return 'A senha deve ter pelo menos 8 caracteres.';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            return 'A senha deve conter pelo menos uma letra maiúscula.';
        }

        if (!preg_match('/[a-z]/', $password)) {
            return 'A senha deve conter pelo menos uma letra minúscula.';
        }

        if (!preg_match('/[0-9]/', $password)) {
            return 'A senha deve conter pelo menos um número.';
        }

        return '';
    }
}

/*
|--------------------------------------------------------------------------
| Processamento
|--------------------------------------------------------------------------
*/
$error = '';
$success = '';
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameValue = trim((string)($_POST['username'] ?? ''));
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    if ($usernameValue === '' || $newPassword === '' || $confirmPassword === '') {
        $error = 'Preencha todos os campos.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/', $usernameValue)) {
        $error = 'Informe um usuário válido.';
    } else {
        $passwordError = pm_reset_password_error($newPassword);

        if ($passwordError !== '') {
            $error = $passwordError;
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'A confirmação de senha não confere.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, username, is_active
                    FROM users
                    WHERE lower(username) = :username
                    LIMIT 1
                ");
                $stmt->execute([
                    ':username' => auth_mb_strtolower($usernameValue),
                ]);

                $user = $stmt->fetch();

                if (!$user) {
                    $error = 'Usuário não encontrado.';
                } elseif ((int)($user['is_active'] ?? 0) !== 1) {
                    $error = 'Este usuário está inativo.';
                } else {
                    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                    $update = $pdo->prepare("
                        UPDATE users
                        SET password_hash = :password_hash,
                            updated_at = datetime('now')
                        WHERE id = :id
                    ");
                    $update->execute([
                        ':password_hash' => $newHash,
                        ':id' => (int)$user['id'],
                    ]);

                    $success = 'Senha redefinida com sucesso. Agora você já pode entrar com a nova senha.';
                    $usernameValue = '';
                }
            } catch (Throwable $e) {
                $error = 'Não foi possível redefinir a senha.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Redefinir senha | Palmeiras Manager</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <style>
        :root {
            --text: #e5e7eb;
            --muted: #9ca3af;
            --border: rgba(255,255,255,0.10);
            --green: #16a34a;
            --green-dark: #15803d;
            --gray-btn: #374151;
            --gray-btn-dark: #2b3441;
            --danger-border: rgba(220,38,38,0.35);
            --danger-bg: rgba(220,38,38,0.12);
            --success-border: rgba(22,163,74,0.35);
            --success-bg: rgba(22,163,74,0.14);
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

        .reset-shell {
            width: 100%;
            max-width: 480px;
        }

        .reset-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));
            border: 1px solid var(--border);
            border-radius: 18px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .reset-header {
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

        .reset-title {
            margin: 0;
            font-size: 28px;
            line-height: 1.2;
            font-weight: 700;
        }

        .reset-subtitle {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .reset-body {
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

        .alert-success {
            border: 1px solid var(--success-border);
            background: var(--success-bg);
            color: #bbf7d0;
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

        .password-hint {
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
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

        .btn-secondary {
            background: linear-gradient(180deg, var(--gray-btn), var(--gray-btn-dark));
            color: #fff;
        }

        .btn-primary:hover,
        .btn-secondary:hover {
            opacity: 0.96;
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

            .reset-header,
            .reset-body {
                padding-left: 18px;
                padding-right: 18px;
            }

            .reset-title {
                font-size: 24px;
            }

            .logo-badge {
                width: 82px;
                height: 82px;
            }
        }
    </style>
</head>
<body>
    <div class="reset-shell">
        <div class="reset-card">
            <div class="reset-header">
                <div class="logo-badge">
                    <img src="/assets/escudos-inst_3.png" alt="Palmeiras Manager">
                </div>
                <h1 class="reset-title">Redefinir senha</h1>
                <p class="reset-subtitle">Atualize a senha do usuário mantendo o mesmo padrão visual do projeto.</p>
            </div>

            <div class="reset-body">
                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($success !== ''): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <form method="post" action="/reset_password.php" autocomplete="off">
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
                        <label for="new_password">Nova senha</label>
                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            class="form-control"
                            maxlength="255"
                            required
                        >
                        <div class="password-hint">
                            A senha deve ter ao menos 8 caracteres, com letra maiúscula, letra minúscula e número.
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Confirmar nova senha</label>
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            class="form-control"
                            maxlength="255"
                            required
                        >
                    </div>

                    <div class="btn-stack">
                        <button type="submit" class="btn btn-primary">Redefinir senha</button>
                        <a href="/login.php" class="btn btn-secondary">Voltar para login</a>
                    </div>
                </form>

                <div class="footer-note">
                    Após redefinir a senha, utilize a nova credencial para acessar o sistema.
                </div>
            </div>
        </div>
    </div>
</body>
</html>