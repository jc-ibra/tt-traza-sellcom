<?php
declare(strict_types=1);

$GLOBALS['CFG'] = require __DIR__ . '/../config.php';
date_default_timezone_set($GLOBALS['CFG']['timezone']);
mb_internal_encoding('UTF-8');

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', '28800');   // 8 horas sin cerrarse sola
    session_name('TRAZAPULSO');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'path' => '/']);
    session_start();
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

require_once __DIR__ . '/equipos.php';

function cfg(string $k)
{
    return $GLOBALS['CFG'][$k] ?? null;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset={$c['charset']}";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function e($s): string
{
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $tipo, string $msg): void
{
    $_SESSION['flash'][] = [$tipo, $msg];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!is_string($t) || !hash_equals(csrf_token(), $t)) {
        http_response_code(400);
        exit('Solicitud inválida (token de seguridad). Recarga la página e inténtalo de nuevo.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function p(string $k, $def = null)
{
    $v = $_POST[$k] ?? $def;
    return is_string($v) ? trim($v) : $v;
}

function g(string $k, $def = null)
{
    $v = $_GET[$k] ?? $def;
    return is_string($v) ? trim($v) : $v;
}

// ---------- Autenticación y roles ----------
const ROLES = ['admin' => 'Administrador', 'editor' => 'Editor', 'consulta' => 'Solo consulta'];

function user(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = q('SELECT * FROM usuarios WHERE id = ? AND activo = 1', [$_SESSION['uid']])->fetch() ?: null;
            if (!$u) {
                unset($_SESSION['uid']);
            }
        }
    }
    return $u;
}

function uid(): ?int
{
    return user() ? (int)user()['id'] : null;
}

function puede(string $rol): bool
{
    $u = user();
    if (!$u) return false;
    $nivel = ['consulta' => 1, 'editor' => 2, 'admin' => 3];
    return ($nivel[$u['rol']] ?? 0) >= ($nivel[$rol] ?? 99);
}

function require_login(): void
{
    if (!user()) {
        $back = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php' . ($back ? '?r=' . urlencode($back) : ''));
    }
    glpi_recalcular_periodico();
    // respaldo automático diario (se ejecuta al terminar la página, como máximo un intento por hora)
    static $programado = false;
    if (!$programado) {
        $programado = true;
        register_shutdown_function(function () {
            require_once __DIR__ . '/respaldo.php';
            respaldo_auto();
        });
    }
    if ((int)user()['debe_cambiar'] === 1 && basename($_SERVER['SCRIPT_NAME']) !== 'perfil.php') {
        flash('warning', 'Por seguridad, cambia tu contraseña antes de continuar.');
        redirect('perfil.php');
    }
}

function require_rol(string $rol): void
{
    require_login();
    if (!puede($rol)) {
        http_response_code(403);
        $titulo = 'Sin permiso';
        require __DIR__ . '/header.php';
        echo '<div class="alert alert-danger">No tienes permiso para realizar esta acción.</div>';
        require __DIR__ . '/footer.php';
        exit;
    }
}

// ---------- Formato ----------
function fecha(?string $dt, bool $hora = true): string
{
    if (!$dt) return '';
    $t = strtotime($dt);
    return $t ? date($hora ? 'd/m/Y H:i' : 'd/m/Y', $t) : '';
}

function num($n): string
{
    return number_format((int)$n, 0, '.', ',');
}

/** Color de badge estable por texto (para conceptos). */
function color_badge(?string $s): string
{
    if (!$s) return 'secondary';
    $u = mb_strtoupper($s);
    if (str_contains($u, 'EXTRAVIAD')) return 'danger';
    if (str_contains($u, 'DESTRUCC')) return 'dark';
    if (str_contains($u, 'ALMACEN')) return 'success';
    if (str_contains($u, 'FRONTERA')) return 'warning';
    if (str_contains($u, 'RETORN')) return 'primary';
    if (str_contains($u, 'SIN REGISTRO') || str_contains($u, 'SIN COINCID')) return 'danger-subtle';
    return 'info';
}

function badge(?string $s): string
{
    if (!$s) return '<span class="text-body-tertiary">—</span>';
    $c = color_badge($s);
    $cls = str_ends_with($c, '-subtle') ? "bg-$c text-danger-emphasis border border-danger-subtle" : "text-bg-$c";
    return '<span class="badge ' . $cls . ' text-wrap text-start">' . e($s) . '</span>';
}

function qs(array $cambios = []): string
{
    $p = array_merge($_GET, $cambios);
    $p = array_filter($p, fn($v) => $v !== null && $v !== '');
    return http_build_query($p);
}
