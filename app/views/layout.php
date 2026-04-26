<?php /** @var string $title */ /** @var string $content */ ?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title ?? 'SALDO WEB') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= e(BASE_URL) ?>/assets/css/app.css" rel="stylesheet">
</head>
<body>

<?php if (auth_user()): ?>
<?php
  $currentPage = basename($_SERVER['PHP_SELF'], '.php');
  $nav = [
    ['page' => 'index',          'icon' => 'bi-grid-1x2-fill',    'label' => 'Dashboard'],
    ['section' => 'Performance'],
    ['page' => 'performance',    'icon' => 'bi-graph-up-arrow',   'label' => 'Campanhas & Métricas'],
    ['page' => 'creatives',      'icon' => 'bi-trophy-fill',      'label' => 'Galeria de Criativos'],
    ['section' => 'Gerenciar'],
    ['page' => 'clients',        'icon' => 'bi-people-fill',      'label' => 'Clientes'],
    ['page' => 'accounts',       'icon' => 'bi-link-45deg',       'label' => 'Contas Meta'],
    ['page' => 'report_settings','icon' => 'bi-clipboard-data',   'label' => 'Relatórios'],
    ['section' => 'Sistema'],
    ['page' => 'alerts',         'icon' => 'bi-bell-fill',        'label' => 'Alertas'],
    ['page' => 'settings',       'icon' => 'bi-gear-fill',        'label' => 'Configurações'],
  ];
  $user = auth_user();
  $initials = strtoupper(substr($user['username'], 0, 2));
  $pageLabel = explode(' · ', $title ?? 'SALDO WEB')[0];
?>
<div class="app-shell">

  <!-- Sidebar Overlay (mobile) -->
  <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">

    <div class="sidebar-logo">
      <div class="sidebar-logo-icon">
        <i class="bi bi-wallet2" style="font-size:15px;line-height:1"></i>
      </div>
      <div class="sidebar-logo-text">
        <span class="sidebar-logo-name">SALDO WEB</span>
        <span class="sidebar-logo-sub">Meta Ads Monitor</span>
      </div>
    </div>

    <nav class="sidebar-nav">
      <?php foreach ($nav as $item): ?>
        <?php if (isset($item['section'])): ?>
          <div class="nav-section-label"><?= e($item['section']) ?></div>
        <?php else: ?>
          <a href="<?= e(base_url($item['page'].'.php')) ?>"
             class="nav-item<?= $currentPage === $item['page'] ? ' active' : '' ?>">
            <span class="nav-icon"><i class="bi <?= e($item['icon']) ?>"></i></span>
            <?= e($item['label']) ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <a href="<?= e(base_url('logout.php')) ?>" class="sidebar-user">
        <div class="sidebar-avatar"><?= e($initials) ?></div>
        <div class="sidebar-user-info">
          <div class="sidebar-user-name"><?= e($user['username']) ?></div>
          <div class="sidebar-user-role">Sair da conta</div>
        </div>
        <i class="bi bi-box-arrow-right" style="font-size:13px;color:var(--text-5);margin-left:auto;flex-shrink:0"></i>
      </a>
    </div>

  </aside>

  <!-- Main Content -->
  <div class="main-wrap">

    <!-- Topbar -->
    <header class="topbar">
      <button class="topbar-menu-btn" id="menuBtn" onclick="toggleSidebar()" aria-label="Menu">
        <i class="bi bi-list" style="font-size:20px;line-height:1"></i>
      </button>
      <span class="topbar-title"><?= e($pageLabel) ?></span>
    </header>

    <!-- Flash Messages -->
    <?php if ($f = flash()): ?>
    <div class="flash-wrap" id="flashWrap">
      <div class="flash flash-<?= e($f['type']) ?>">
        <?php $flashIcon = ['success'=>'bi-check-circle-fill','danger'=>'bi-exclamation-circle-fill','warning'=>'bi-exclamation-triangle-fill','info'=>'bi-info-circle-fill']; ?>
        <i class="bi <?= $flashIcon[$f['type']] ?? 'bi-info-circle-fill' ?>" style="font-size:15px;flex-shrink:0"></i>
        <span><?= e($f['msg']) ?></span>
        <button class="flash-close" onclick="document.getElementById('flashWrap').remove()">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>
    </div>
    <?php endif; ?>

    <!-- Page Content -->
    <main class="page-content">
      <?= $content ?>
    </main>

  </div><!-- /main-wrap -->

</div><!-- /app-shell -->

<script>
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('visible');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('visible');
}
document.querySelectorAll('.nav-item').forEach(function(el) {
  el.addEventListener('click', function() {
    if (window.innerWidth < 768) closeSidebar();
  });
});
</script>

<?php else: ?>
<!-- Auth pages — no sidebar -->
<div style="min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;position:relative;z-index:1">
  <?= $content ?>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
