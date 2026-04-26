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
<style>
  .nav-icon i.bi, .sidebar-logo-icon i.bi { font-size: 18px; line-height: 1; }
  .sidebar-logo-icon { display:flex;align-items:center;justify-content:center }
</style>
</head>
<body>

<?php if (auth_user()): ?>
<?php
  $currentPage = basename($_SERVER['PHP_SELF'], '.php');
  $nav = [
    ['page' => 'index',          'icon' => '<i class="bi bi-grid-1x2-fill"></i>', 'label' => 'Dashboard'],
    ['section' => 'Performance'],
    ['page' => 'performance',    'icon' => '<i class="bi bi-graph-up-arrow"></i>', 'label' => 'Campanhas & Métricas'],
    ['page' => 'creatives',      'icon' => '<i class="bi bi-trophy-fill"></i>',    'label' => 'Galeria de Criativos'],
    ['section' => 'Gerenciar'],
    ['page' => 'clients',        'icon' => '<i class="bi bi-people-fill"></i>',    'label' => 'Clientes'],
    ['page' => 'accounts',       'icon' => '<i class="bi bi-link-45deg"></i>',     'label' => 'Contas Meta'],
    ['page' => 'report_settings','icon' => '<i class="bi bi-clipboard-data"></i>', 'label' => 'Relatórios'],
    ['section' => 'Sistema'],
    ['page' => 'alerts',         'icon' => '<i class="bi bi-bell-fill"></i>',      'label' => 'Alertas'],
    ['page' => 'settings',       'icon' => '<i class="bi bi-gear-fill"></i>',      'label' => 'Configurações'],
  ];
  $user = auth_user();
  $initials = strtoupper(substr($user['username'], 0, 2));
?>
<div class="app-shell">

  <!-- Sidebar Overlay (mobile) -->
  <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <div class="sidebar-logo-icon"><i class="bi bi-wallet2"></i></div>
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
             class="nav-item <?= $currentPage === $item['page'] ? 'active' : '' ?>">
            <span class="nav-icon"><?= $item['icon'] /* SVG inline */ ?></span>
            <?= e($item['label']) ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
      <div class="sidebar-divider"></div>
      <a href="<?= e(base_url('logout.php')) ?>" class="sidebar-user">
        <div class="sidebar-avatar"><?= e($initials) ?></div>
        <div class="sidebar-user-info">
          <div class="sidebar-user-name"><?= e($user['username']) ?></div>
          <div class="sidebar-user-role">Sair da conta</div>
        </div>
        <span style="font-size:14px;color:var(--text-4);margin-left:auto"><i class="bi bi-box-arrow-right"></i></span>
      </a>
    </div>
  </aside>

  <!-- Main Content -->
  <div class="main-wrap">
    <!-- Topbar -->
    <header class="topbar">
      <button class="topbar-menu-btn" id="menuBtn" onclick="toggleSidebar()" aria-label="Menu">
<i class="bi bi-list" style="font-size:22px"></i>
      </button>
      <span class="topbar-title"><?= e(explode(' · ', $title ?? 'SALDO WEB')[0]) ?></span>
    </header>

    <!-- Flash Messages -->
    <?php if ($f = flash()): ?>
    <div class="flash-wrap" id="flashWrap">
      <div class="flash flash-<?= e($f['type']) ?>">
        <span><?= e($f['msg']) ?></span>
        <button class="flash-close" onclick="document.getElementById('flashWrap').remove()"><i class="bi bi-x-lg"></i></button>
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
// Close sidebar on nav click (mobile)
document.querySelectorAll('.nav-item').forEach(function(el) {
  el.addEventListener('click', function() {
    if (window.innerWidth < 768) closeSidebar();
  });
});
</script>

<?php else: ?>
<!-- Auth pages — no sidebar, just center content -->
<div style="min-height:100vh;background:var(--bg);display:flex;align-items:center;justify-content:center;padding:24px">
  <?= $content ?>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
