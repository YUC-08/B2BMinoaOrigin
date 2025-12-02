<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPage = basename($_SERVER['PHP_SELF']);

// Session'dan kullanıcı bilgilerini al
$firstName  = $_SESSION['FirstName']  ?? '';
$lastName   = $_SESSION['LastName']   ?? '';
$userName   = $_SESSION['UserName']   ?? '';
$ownerCode  = $_SESSION['U_AS_OWNR']  ?? '';
$whsCode    = $_SESSION['WhsCode']    ?? '';
// Branch2 bilgisini kontrol et: Önce Description (açıklayıcı isim), yoksa Name, yoksa boş
$branchDesc = '';
if (isset($_SESSION['Branch2']) && is_array($_SESSION['Branch2'])) {
    $branchDesc = $_SESSION['Branch2']['Description'] ?? $_SESSION['Branch2']['Name'] ?? '';
}

// Görünecek isim: Önce Ad Soyad, yoksa username, o da yoksa 'Misafir'
$displayName = trim($firstName . ' ' . $lastName);
if ($displayName === '') {
    $displayName = $userName ?: 'Misafir';
}

// Avatar içi: Önce OWNER (KT gibi). Yoksa ad-soyad baş harfleri.
$avatarText = $ownerCode;
if ($avatarText === '') {
    $initials = '';
    if ($firstName !== '') {
        $initials .= mb_substr($firstName, 0, 1, 'UTF-8');
    }
    if ($lastName !== '') {
        $initials .= mb_substr($lastName, 0, 1, 'UTF-8');
    }
    if ($initials === '' && $userName !== '') {
        $initials = mb_strtoupper(mb_substr($userName, 0, 2, 'UTF-8'), 'UTF-8');
    }
    $avatarText = $initials ?: '?';
}

// Kullanıcı tipine göre başlık belirleme (U_AS_OWNR'a göre)
$sectionTitle = '';
$sectionPage = '';
// Session'dan U_AS_OWNR al (login.php'de kaydediliyor)
$uAsOwnr = $_SESSION['U_AS_OWNR'] ?? '';
if ($uAsOwnr === 'MS') {
    $sectionTitle = 'Etkinlik';
    $sectionPage = 'Muse.php';
} elseif ($uAsOwnr === 'CF' || $uAsOwnr === 'YE') {
    // Kafe ve Restorant için Üretim (CF veya YE sektör kodları)
    $sectionTitle = 'Üretim';
    $sectionPage = 'Uretim.php';
}
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        background: #f5f7fa;
        --sidebar-width: 70px;
        --sidebar-expanded-width: 260px;
    }

    /* Updated sidebar styling for proper collapsed state */
    /* Collapsible Sidebar - Full Height */
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        width: var(--sidebar-width);
        background: linear-gradient(180deg, #3d5a80 0%, #2c4563 100%);
        transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
        z-index: 1000;
        box-shadow: 2px 0 12px rgba(0, 0, 0, 0.15);
        display: flex;
        flex-direction: column;
        border-radius: 0 20px 20px 0;
    }

    .sidebar.expanded {
        width: var(--sidebar-expanded-width);
    }

    /* Logo header with proper centering */
    .sidebar-header {
        padding: 20px 10px;
        text-align: center;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        background: rgba(0, 0, 0, 0.2);
        height: 80px;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: padding 0.3s ease;
        box-sizing: border-box;
        border-radius: 0 20px 0 0;
    }

    .sidebar:not(.expanded) .sidebar-header {
        padding: 20px 5px;
        height: 80px;
    }

    .sidebar-logo {
        font-size: 24px;
        font-weight: 700;
        letter-spacing: 2px;
        color: white;
        text-shadow: 0 2px 8px rgba(0, 82, 204, 0.3);
        animation: logoGlow 3s ease-in-out infinite;
        white-space: nowrap;
        transition: font-size 0.3s ease;
    }

    .sidebar:not(.expanded) .sidebar-logo {
        font-size: 18px;
        letter-spacing: 1px;
    }

    @keyframes logoGlow {
        0%, 100% {
            text-shadow: 0 2px 8px rgba(0, 82, 204, 0.3);
        }
        50% {
            text-shadow: 0 2px 16px rgba(0, 82, 204, 0.6), 0 0 24px rgba(0, 82, 204, 0.4);
        }
    }

    .sidebar-nav {
        flex: 1;
        padding: 24px 0;
        overflow-y: auto;
        overflow-x: hidden;
    }

    .sidebar-nav::-webkit-scrollbar {
        width: 4px;
    }

    .sidebar-nav::-webkit-scrollbar-track {
        background: rgba(255, 255, 255, 0.05);
    }

    .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 2px;
    }

    .sidebar-item {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 14px 20px;
        color: rgba(255, 255, 255, 0.85);
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s ease;
        cursor: pointer;
        border-radius: 12px;
        margin: 4px 8px;
        white-space: nowrap;
        position: relative;
        justify-content: flex-start;
    }

    /* Center icons when collapsed */
    .sidebar:not(.expanded) .sidebar-item {
        justify-content: center;
        padding: 14px 0;
    }


    .sidebar-item:hover {
        background: rgba(255, 255, 255, 0.1);
        color: white;
    }

    .sidebar-item.active {
        background: linear-gradient(90deg, rgba(0, 82, 204, 0.3) 0%, transparent 100%);
        color: white;
        border-left: 3px solid #0052CC;
    }

    .sidebar-icon {
        font-size: 24px;
        min-width: 30px;
        text-align: center;
        flex-shrink: 0;
    }

    .sidebar-text {
        display: none;
        transition: opacity 0.3s ease 0.1s;
    }

    .sidebar.expanded .sidebar-text {
        display: inline;
    }

    /* Section Title - Normal sidebar-item gibi */
    .sidebar-section-title {
        display: flex;
        align-items: center;
        gap: 16px;
        padding: 14px 20px;
        color: rgba(255, 255, 255, 0.85);
        text-decoration: none;
        font-size: 14px;
        font-weight: 500;
        transition: all 0.3s ease;
        border-radius: 12px;
        margin: 4px 8px;
        white-space: nowrap;
        position: relative;
        justify-content: flex-start;
    }

    .sidebar:not(.expanded) .sidebar-section-title {
        justify-content: center;
        padding: 14px 0;
    }

    .sidebar-section-title:hover {
        background: rgba(255, 255, 255, 0.1);
        color: white;
    }

    .sidebar-section-title.active {
        background: linear-gradient(90deg, rgba(0, 82, 204, 0.3) 0%, transparent 100%);
        color: white;
        border-left: 3px solid #0052CC;
    }

    .sidebar-item.invisible {
        display: none !important;
    }

    /* Updated footer with better collapsed state */
    .sidebar-footer {
        padding: 16px 12px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        background: rgba(0, 0, 0, 0.2);
        transition: padding 0.3s ease;
        border-radius: 0 0 20px 0;
    }

    .sidebar:not(.expanded) .sidebar-footer {
        padding: 12px 8px;
    }

    /* Action buttons layout - stack when collapsed, side by side when expanded */
    .sidebar-actions {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
        justify-content: center;
        transition: gap 0.3s ease;
    }

    .sidebar:not(.expanded) .sidebar-actions {
        flex-direction: column;
        gap: 6px;
    }

    .sidebar-action-btn {
        flex: 1;
        padding: 10px 8px;
        border-radius: 12px;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.2);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 4px;
        color: white;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.3s ease;
        font-size: 20px;
        min-width: 40px;
    }

    .sidebar:not(.expanded) .sidebar-action-btn {
        flex: 0;
        padding: 10px;
        min-width: 40px;
    }

    .sidebar-action-btn:hover {
        background: rgba(255, 255, 255, 0.15);
        border-color: rgba(255, 255, 255, 0.3);
        transform: translateY(-2px);
    }

    .sidebar-action-btn.logout-btn {
        background: rgba(220, 53, 69, 0.15);
        border-color: rgba(220, 53, 69, 0.3);
        color: #ff6b6b;
    }

    .sidebar-action-btn.logout-btn:hover {
        background: rgba(220, 53, 69, 0.25);
        border-color: rgba(220, 53, 69, 0.4);
    }

    .sidebar-action-label {
        font-size: 10px;
        font-weight: 500;
        display: none;
        transition: opacity 0.3s ease 0.1s;
        white-space: nowrap;
    }

    .sidebar.expanded .sidebar-action-label {
        display: block;
    }

    /* User info with better collapsed alignment */
    .sidebar-user {
        display: flex;
        align-items: center;
        gap: 12px;
        color: white;
        justify-content: center;
        padding: 0 8px;
    }

    .sidebar:not(.expanded) .sidebar-user {
        justify-content: center;
        gap: 0;
    }

    .sidebar-user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #0052CC 0%, #003d99 100%);
        border: 2px solid rgba(255, 255, 255, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 14px;
        flex-shrink: 0;
    }

    .sidebar-user-info {
        display: none;
        transition: opacity 0.3s ease 0.1s;
        overflow: hidden;
        flex: 1;
        min-width: 0;
    }

    .sidebar.expanded .sidebar-user-info {
        display: block;
    }

    .sidebar-user-name {
        font-size: 13px;
        font-weight: 600;
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sidebar-user-owner {
        font-size: 11px;
        opacity: 0.75;
    }

    /* Main content adjustment - only applies when sidebar exists */
    /* Use sibling selector to avoid affecting other pages */
    /* Force override with !important to ensure it works on all pages */
    .sidebar ~ .main-content,
    .sidebar ~ main.main-content {
        margin-left: var(--sidebar-width) !important;
        width: calc(100% - var(--sidebar-width)) !important;
        transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        box-sizing: border-box !important;
    }

    .sidebar.expanded ~ .main-content,
    .sidebar.expanded ~ main.main-content {
        margin-left: var(--sidebar-expanded-width) !important;
        width: calc(100% - var(--sidebar-expanded-width)) !important;
    }

    /* Page header styling for all pages */
    .page-header {
        background: white;
        padding: 20px 2rem;
        border-radius: 0 0 0 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 0;
        position: sticky;
        top: 0;
        z-index: 100;
        height: 80px;
        box-sizing: border-box;
    }

    .page-header h2 {
        color: #1e40af;
        font-size: 1.75rem;
        font-weight: 600;
        margin: 0;
    }

    /* Content wrapper styling */
    .content-wrapper {
        padding: 24px 32px;
        max-width: 1400px;
        margin: 0 auto;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .sidebar {
            width: 60px;
        }

        .sidebar.expanded {
            width: 220px;
        }

        :root {
            --sidebar-width: 60px;
            --sidebar-expanded-width: 220px;
        }

        .sidebar ~ .main-content,
        .sidebar ~ main.main-content {
            margin-left: var(--sidebar-width) !important;
            width: calc(100% - var(--sidebar-width)) !important;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1), width 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
        }

        .sidebar.expanded ~ .main-content,
        .sidebar.expanded ~ main.main-content {
            margin-left: var(--sidebar-expanded-width) !important;
            width: calc(100% - var(--sidebar-expanded-width)) !important;
        }

        .sidebar-header {
            height: 80px;
            padding: 20px 5px;
        }

        .sidebar:not(.expanded) .mobile-logo-svg {
            width: 50px;
            height: 45px;
        }

        .sidebar:not(.expanded) .mobile-logo-text {
            font-size: 20px;
        }

        .sidebar.expanded .mobile-logo-svg {
            width: 220px;
            height: 50px;
        }

        .page-header {
            height: 80px;
            padding: 20px 1rem;
        }

        .sidebar-item {
            padding: 12px 16px;
            font-size: 13px;
        }

        .sidebar-icon {
            font-size: 20px;
            min-width: 28px;
        }

        .sidebar-logo {
            font-size: 20px;
        }
    }

    @media (max-width: 480px) {
        .sidebar {
            width: 0;
            transform: translateX(-100%);
        }

        .sidebar.expanded {
            width: 240px;
            transform: translateX(0);
        }

        .main-content {
            margin-left: 0;
        }

        .sidebar.expanded ~ .main-content {
            margin-left: 0;
        }
    } 


    
    /* Logo Animasyon Stilleri (mevcut bıraktım) */
    #writing-animation {
        background-color: transparent;
        display: flex;
        justify-content: flex-start;
        align-items: center;
        height: auto;
        padding: 0;
    }

    .logo-animation {
        text-decoration: none;
        display: block;
        flex-shrink: 0;
    }

    .text {
        font-size: 36px;
        font-family: 'Dancing Script', cursive;
        fill: transparent;
        stroke: #4caf50;
        stroke-width: 2;
        stroke-dasharray: 800;
        stroke-dashoffset: 800;
        animation: draw 11s forwards;
    }

    @keyframes draw {
        to {
            stroke-dashoffset: 0;
            fill: #4caf50;
        }
    }

    .logo-mobile {
        text-decoration: none;
        display: block;
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .mobile-logo-svg {
        width: 240px;
        height: 50px;
        transition: width 0.3s ease, height 0.3s ease;
    }

    .mobile-logo-text {
        font-size: 28px;
        font-family: 'Dancing Script', cursive;
        fill: transparent;
        stroke: #ffffff;
        stroke-width: 2;
        stroke-dasharray: 500;
        stroke-dashoffset: 500;
        animation: mobileDraw 11s forwards;
        transition: font-size 0.3s ease;
    }

    /* Sidebar kapalıyken logo küçül - sadece "M" harfi görünsün */
    .sidebar:not(.expanded) .mobile-logo-svg {
        width: 60px;
        height: 50px;
    }

    .sidebar:not(.expanded) .mobile-logo-text {
        font-size: 24px;
        font-weight: 700;
    }


    /* Sidebar açıkken tam logo */
    .sidebar.expanded .mobile-logo-svg {
        width: 240px;
        height: 50px;
    }

    .sidebar.expanded .mobile-logo-text {
        font-size: 28px;
    }

    @keyframes mobileDraw {
        to {
            stroke-dashoffset: 0;
            fill: #ffffff;
        }
    }
</style>

<!-- Removed toggle icon from top, kept everything else -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
         <!-- Animasyonlu Logo -->
         <a href="index.php" class="logo-mobile">
            <svg class="mobile-logo-svg" viewBox="0 0 240 50" preserveAspectRatio="xMidYMid meet">
                <text x="100" y="25" dominant-baseline="middle" class="mobile-logo-text" id="logo-text">
                    MINOA
                </text>
            </svg>
        </a> 
    </div>

    <div class="sidebar-nav">
        <a href="index.php" class="sidebar-item <?= ($currentPage == 'index.php') ? 'active' : '' ?>">
            <span class="sidebar-icon">🏠</span>
            <span class="sidebar-text">Anasayfa</span>
        </a>

        <a href="DisTedarik.php" class="sidebar-item <?= ($currentPage == 'DisTedarik.php' || $currentPage == 'DisTedarikSO.php' || $currentPage == 'DisTedarik-Detay.php' || $currentPage == 'DisTedarik-TeslimAl.php') ? 'active' : '' ?>">
            <span class="sidebar-icon">🛒</span>
            <span class="sidebar-text">Dış Tedarik</span>
        </a>

        <a href="AnaDepo.php" class="sidebar-item <?= ($currentPage == 'AnaDepo.php' || $currentPage == 'AnaDepoSO.php' || $currentPage == 'AnaDepo-Detay.php' || $currentPage == 'anadepo_teslim_al.php') ? 'active' : '' ?>">
            <span class="sidebar-icon">📦</span>
            <span class="sidebar-text">Ana Depo</span>
        </a>

        <a href="Transferler.php" class="sidebar-item <?= ($currentPage == 'Transferler.php') ? 'active' : '' ?>">
            <span class="sidebar-icon">🔄</span>
            <span class="sidebar-text">Transferler</span>
        </a>

        <?php if (!empty($sectionTitle) && !empty($sectionPage)): ?>
        <a href="<?= htmlspecialchars($sectionPage) ?>" class="sidebar-item sidebar-section-title <?= ($currentPage == $sectionPage || ($sectionPage == 'Uretim.php' && ($currentPage == 'UretimDetay.php' || $currentPage == 'UretimSO.php')) || ($sectionPage == 'Muse.php' && strpos($currentPage, 'Muse') !== false)) ? 'active' : '' ?>">
            <span class="sidebar-icon"><?= $sectionTitle === 'Etkinlik' ? '🎭' : '🍳' ?></span>
            <span class="sidebar-text"><?= htmlspecialchars($sectionTitle) ?></span>
        </a>
        <?php endif; ?>

        <a href="Fire-Zayi.php" class="sidebar-item <?= ($currentPage == 'Fire-Zayi.php') ? 'active' : '' ?>">
            <span class="sidebar-icon">⚠️</span>
            <span class="sidebar-text">Fire & Zayi</span>
        </a>

        <a href="Stok.php" class="sidebar-item <?= ($currentPage == 'Stok.php') ? 'active' : '' ?>">
            <span class="sidebar-icon">📊</span>
            <span class="sidebar-text">Stok Sayımı</span>
        </a>

        <!-- Check List - tüm kullanıcılar için invisible -->
        <a href="Check-List.php" class="sidebar-item invisible">
            <span class="sidebar-icon">✓</span>
            <span class="sidebar-text">Check List</span>
        </a>

        <!-- Ticket - tüm kullanıcılar için invisible -->
        <a href="Ticket.php" class="sidebar-item invisible">
            <span class="sidebar-icon">🎫</span>
            <span class="sidebar-text">Ticket</span>
        </a>

        <!-- Durum menü öğesi - her zaman invisible -->
        <a href="#" class="sidebar-item invisible">
            <span class="sidebar-icon">📋</span>
            <span class="sidebar-text">Durum</span>
        </a>
    </div>

    <div class="sidebar-footer">
        <div class="sidebar-actions">
            <a href="config/logout.php" class="sidebar-action-btn logout-btn" title="Çıkış Yap">
                <span>🚪</span>
                <span class="sidebar-action-label">Çıkış</span>
            </a>
            <div class="sidebar-action-btn" onclick="toggleSidebar()" title="Daralt/Genişlet">
                <span>⬌</span>
                <span class="sidebar-action-label" id="toggleLabelFooter">Daralt</span>
            </div>
        </div>
        
        <div class="sidebar-user">
            <div class="sidebar-user-avatar">
                <?= htmlspecialchars($avatarText, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name">
                    <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php if ($userName): ?>
                    <div class="sidebar-user-owner" style="font-size: 10px; opacity: 0.7; margin-top: 2px;">
                        @<?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
                <?php if ($whsCode): ?>
                    <div class="sidebar-user-owner" style="font-size: 10px; opacity: 0.8; margin-top: 2px;">
                        <?= htmlspecialchars($whsCode, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>
                <?php if ($ownerCode): ?>
                    <div class="sidebar-user-owner">
                        Giriş: <?= htmlspecialchars($ownerCode, ENT_QUOTES, 'UTF-8') ?><?php if (!empty($branchDesc)): ?> - <?= htmlspecialchars($branchDesc, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const toggleLabelFooter = document.getElementById('toggleLabelFooter');
    const logoText = document.getElementById('logo-text');
    
    sidebar.classList.toggle('expanded');
    
    if (sidebar.classList.contains('expanded')) {
        toggleLabelFooter.textContent = 'Daralt';
        if (logoText) {
            logoText.setAttribute('text-anchor', 'start');
            logoText.setAttribute('x', '10');
        }
    } else {
        toggleLabelFooter.textContent = 'Genişlet';
        if (logoText) {
            logoText.setAttribute('text-anchor', 'middle');
            logoText.setAttribute('x', '120');
        }
    }
    
    localStorage.setItem('sidebarExpanded', sidebar.classList.contains('expanded'));
}

document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const toggleLabelFooter = document.getElementById('toggleLabelFooter');
    const logoText = document.getElementById('logo-text');
    const sidebarExpanded = localStorage.getItem('sidebarExpanded') === 'true';
    
    if (sidebarExpanded) {
        sidebar.classList.add('expanded');
        toggleLabelFooter.textContent = 'Daralt';
        if (logoText) {
            logoText.setAttribute('text-anchor', 'start');
            logoText.setAttribute('x', '10');
        }
    } else {
        toggleLabelFooter.textContent = 'Genişlet';
        if (logoText) {
            logoText.setAttribute('text-anchor', 'middle');
            logoText.setAttribute('x', '120');
        }
    }
});
</script>