<?php
/**
 * Facebook OAuth — inicia ou recebe o callback.
 *
 * Fluxo:
 *   1. Usuário clica "Conectar Facebook" em accounts.php → redireciona pra cá com ?start=1
 *   2. Redirecionamos para facebook.com/.../dialog/oauth com state CSRF
 *   3. Facebook redireciona de volta com ?code=...&state=...
 *   4. Trocamos code por short-lived token, depois por long-lived token (60 dias)
 *   5. Salvamos em settings.fb_user_access_token e voltamos pra accounts.php
 */
require_once __DIR__ . '/app/bootstrap.php';
require_auth();

$appId     = Db::getSetting('fb_app_id');
$appSecret = Db::getSetting('fb_app_secret');

if (!$appId || !$appSecret) {
    flash('Configure App ID e App Secret do Facebook em Configurações antes de conectar.', 'warning');
    header('Location: ' . base_url('settings.php'));
    exit;
}

$schema   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host     = $_SERVER['HTTP_HOST'];
$redirect = $schema . '://' . $host . BASE_URL . '/oauth_fb.php';
$apiVer   = Db::getSetting('meta_api_version') ?: 'v19.0';

// Passo 1 — iniciar
if (isset($_GET['start'])) {
    $state = bin2hex(random_bytes(16));
    $_SESSION['fb_oauth_state'] = $state;

    $scopes = 'ads_read,ads_management,business_management,read_insights';
    $authUrl = 'https://www.facebook.com/' . $apiVer . '/dialog/oauth?' . http_build_query([
        'client_id'     => $appId,
        'redirect_uri'  => $redirect,
        'state'         => $state,
        'scope'         => $scopes,
        'response_type' => 'code',
        'auth_type'     => 'rerequest',
    ]);
    header('Location: ' . $authUrl);
    exit;
}

// Passo 2 — callback
if (isset($_GET['error'])) {
    flash('Facebook negou acesso: ' . ($_GET['error_description'] ?? $_GET['error']), 'danger');
    header('Location: ' . base_url('accounts.php'));
    exit;
}

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

if (!$code) {
    flash('Callback inválido: code ausente.', 'danger');
    header('Location: ' . base_url('accounts.php'));
    exit;
}
if (!hash_equals($_SESSION['fb_oauth_state'] ?? '', $state)) {
    flash('State CSRF inválido. Tente novamente.', 'danger');
    header('Location: ' . base_url('accounts.php'));
    exit;
}
unset($_SESSION['fb_oauth_state']);

try {
    // Trocar code por short-lived token
    $tokUrl = 'https://graph.facebook.com/' . $apiVer . '/oauth/access_token?' . http_build_query([
        'client_id'     => $appId,
        'client_secret' => $appSecret,
        'redirect_uri'  => $redirect,
        'code'          => $code,
    ]);
    $resp = HttpClient::get($tokUrl);
    if ($resp['status'] >= 400 || empty($resp['body']['access_token'])) {
        throw new RuntimeException($resp['body']['error']['message'] ?? 'Falha ao trocar code por token');
    }
    $shortToken = $resp['body']['access_token'];

    // Short → Long-lived (60 dias)
    $longUrl = 'https://graph.facebook.com/' . $apiVer . '/oauth/access_token?' . http_build_query([
        'grant_type'        => 'fb_exchange_token',
        'client_id'         => $appId,
        'client_secret'     => $appSecret,
        'fb_exchange_token' => $shortToken,
    ]);
    $resp2 = HttpClient::get($longUrl);
    $longToken = $resp2['body']['access_token'] ?? $shortToken;
    $expiresIn = $resp2['body']['expires_in'] ?? 0;

    Db::setSetting('fb_user_access_token', $longToken, true);
    Db::setSetting('fb_user_token_expires_at', $expiresIn ? (string) (time() + (int) $expiresIn) : '');

    flash('Conta Facebook conectada com sucesso. Agora importe as contas de anúncios.', 'success');
} catch (Throwable $e) {
    flash('Erro ao conectar Facebook: ' . $e->getMessage(), 'danger');
}

header('Location: ' . base_url('accounts.php'));
exit;
