<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<!-- Complete navbar redesign - top header bar with horizontal navigation -->
<style>
    /* Modern Top Header Navigation */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    }

    /* Top Header Bar */
    .navbar-header {
        background: linear-gradient(135deg, #0052CC 0%, #003d99 100%);
        color: white;
        padding: 12px 24px;
        display: grid;
        grid-template-columns: 1fr auto 1fr;
        align-items: center;
        box-shadow: 0 2px 8px rgba(0, 82, 204, 0.15);
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        z-index: 1001;
        height: 60px;
    }

    .navbar-header-left {
        display: flex;
        justify-content: flex-start;
    }

    .navbar-header-center {
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .navbar-header-right {
        display: flex;
        justify-content: flex-end;
    }

    .navbar-brand {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .navbar-logo {
        font-size: 24px;
        font-weight: 700;
        letter-spacing: 1px;
        background: rgba(255, 255, 255, 0.95);
        color: #0052CC;
        padding: 6px 14px;
        border-radius: 6px;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        min-width: 50px;
        text-align: center;
    }

    .navbar-title {
        font-size: 16px;
        font-weight: 500;
        opacity: 0.95;
        letter-spacing: 0.3px;
    }

   

    .navbar-right {
        display: flex;
        align-items: center;
        gap: 16px;
    }

    .navbar-user {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        opacity: 0.95;
    }

    .navbar-avatar {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.25);
        border: 2px solid rgba(255, 255, 255, 0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        font-size: 14px;
    }

    /* Fixed horizontal navigation bar styling for white background */
    .navbar-nav {
        background: white;
        border-bottom: 3px solid #f0f4f8;
        display: flex;
        align-items: center;
        padding: 0 24px;
        position: fixed;
        top: 60px;
        left: 0;
        right: 0;
        z-index: 1000;
        height: 56px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
    }

    .nav-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 16px 18px;
        color: #2c3e50;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border-bottom: 3px solid transparent;
        cursor: pointer;
        white-space: nowrap;
        position: relative;
        height: 100%;
    }

    .nav-item:hover {
        color: #0052CC;
        background: #f8fafc;
    }

    .nav-item.active {
        color: #0052CC;
        border-bottom-color: #0052CC;
        background: #f0f9ff;
    }

    .nav-icon {
        font-size: 16px;
    }

    /* Main content adjustment */
    body .main-content-spacer {
        margin-top: 116px;
    }

    /* Responsive */
    @media (max-width: 1024px) {
        .navbar-nav {
            overflow-x: auto;
            overflow-y: hidden;
        }

        .navbar-nav::-webkit-scrollbar {
            height: 4px;
        }

        .navbar-nav::-webkit-scrollbar-track {
            background: #f0f4f8;
        }

        .navbar-nav::-webkit-scrollbar-thumb {
            background: #d0dce6;
            border-radius: 2px;
        }

        .nav-item {
            padding: 16px 14px;
            font-size: 12px;
        }
    }

    @media (max-width: 768px) {
        .navbar-header {
            height: 56px;
            padding: 10px 16px;
        }

        .navbar-logo {
            font-size: 18px;
            padding: 4px 10px;
            min-width: 42px;
        }

        .navbar-title {
            font-size: 13px;
            display: none;
        }

    

        .navbar-user {
            font-size: 11px;
            gap: 4px;
        }

        .navbar-nav {
            top: 56px;
            height: 50px;
            padding: 0 16px;
        }

        .nav-item {
            padding: 14px 12px;
            font-size: 11px;
        }

        .nav-icon {
            font-size: 14px;
        }

        body .main-content-spacer {
            margin-top: 106px;
        }
    }

    @media (max-width: 480px) {
        .navbar-brand {
            gap: 8px;
        }

        .navbar-logo {
            font-size: 14px;
            padding: 3px 8px;
            min-width: auto;
        }

        .navbar-avatar {
            width: 32px;
            height: 32px;
            font-size: 12px;
        }

        .nav-item {
            padding: 12px 8px;
            font-size: 10px;
            gap: 4px;
        }
    } 


  /* Desktop Logo Animasyon Stilleri */
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

/* Mobil Logo Stili */
.logo-mobile {
    text-decoration: none;
    display: block;
    margin: 0 auto;
}

.mobile-logo-svg {
    width: 200px;
    height: 40px;
}

.mobile-logo-text {
    font-size: 24px;
    font-family: 'Dancing Script', cursive;
    fill: transparent;
    stroke: #ffffffff;
    stroke-width: 2;
    stroke-dasharray: 500;
    stroke-dashoffset: 500;
    animation: mobileDraw 11s forwards;
}

@keyframes mobileDraw {
    to {
        stroke-dashoffset: 0;
        fill: #ffffffff;
    }
}

</style>

<!-- Top Header -->
<div class="navbar-header">
    <div class="navbar-header-left"></div>
    
    <div class="navbar-header-center">
        <!-- Mobil Logo (SVG Animasyonlu) -->
        <a href="/" class="logo-mobile">
            <svg width="200" height="40" class="mobile-logo-svg">
                <text x="0" y="50%" dominant-baseline="middle" text-anchor="start" class="mobile-logo-text"> 
                    MINOA
                </text> 
            </svg>
        </a>
    </div>

    <div class="navbar-header-right">
        <div class="navbar-right">
            <div class="navbar-user">
                <span>user@minoa.com</span>
                <div class="navbar-avatar">K1</div>
            </div>
        </div>
    </div>
</div>

<!-- Horizontal Navigation Bar -->
<nav class="navbar-nav">
    <a href="index.php" class="nav-item <?= ($currentPage == 'index.php') ? 'active' : '' ?>">
        <span class="nav-icon">🏠</span>
        <span>Anasayfa</span>
    </a>

    <a href="Dis-Tedarik.php" class="nav-item <?= ($currentPage == 'Dis-Tedarik.php') ? 'active' : '' ?>">
        <span class="nav-icon">📦</span>
        <span>Dış Tedarik</span>
    </a>

    <a href="AnaDepo.php" class="nav-item <?= ($currentPage == 'AnaDepo.php') ? 'active' : '' ?>">
        <span class="nav-icon">🏪</span>
        <span>Ana Depo</span>
    </a>

    <a href="Transferler.php" class="nav-item <?= ($currentPage == 'Transferler.php') ? 'active' : '' ?>">
        <span class="nav-icon">🔄</span>
        <span>Transferler</span>
    </a>

    <a href="Check-List.php" class="nav-item <?= ($currentPage == 'Check-List.php') ? 'active' : '' ?>">
        <span class="nav-icon">✓</span>
        <span>Check List</span>
    </a>

    <a href="Fire-Zayi.php" class="nav-item <?= ($currentPage == 'Fire-Zayi.php') ? 'active' : '' ?>">
        <span class="nav-icon">⚠️</span>
        <span>Fire & Zayi</span>
    </a>

    <a href="Ticket.php" class="nav-item <?= ($currentPage == 'Ticket.php') ? 'active' : '' ?>">
        <span class="nav-icon">🎫</span>
        <span>Ticket</span>
    </a>

    <a href="Stok.php" class="nav-item <?= ($currentPage == 'Stok.php') ? 'active' : '' ?>">
        <span class="nav-icon">📊</span>
        <span>Stok Sayımı</span>
    </a>
</nav>
