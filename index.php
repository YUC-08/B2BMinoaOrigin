<?php
session_start();
if (!isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// SAP'den veri çek (her sayfada sorguyu değiştir)
$data = $sap->get("SQLQueries('OWTQ_LIST')/List?value1='PROD'&value2='WhsCode'");
?>


<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CREMMAVERSE - Anasayfa</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="app-container">
        <aside class="sidebar">
            <div class="logo">
                <h1>CREMMA<span>VERSE</span></h1>
            </div>
            <?php include 'navbar.php'; ?>
            <div class="user-info">
                <div class="user-avatar">K1</div>
                <div class="user-details">
                    <div class="user-name">Koşuyolu 1000 - Koşuyolu</div>
                    <div class="version">v1.0.43</div>
                </div>
            </div>
        </aside>

         Main Content 
        <main class="main-content">
            <header class="page-header">
                <h2>Anasayfa</h2>
                <a href="config/login.php"><button class="btn-exit">Çıkış Yap ↗</button></a>
            </header>

            <div class="content-wrapper">
                 Sipariş ve İşlemler Section 
                <section class="card">
                    <h3 class="section-title">Sipariş ve İşlemler</h3>
                    <div class="button-grid">
                        <button class="btn btn-primary">+ Hızlı Sipariş Oluştur</button>
                        <button class="btn btn-secondary">+ Dış Tedarik Sipariş Oluştur</button>
                        <button class="btn btn-secondary">+ Üretim Sipariş Oluştur</button>
                    </div>
                    <div class="button-grid mt-2">
                        <button class="btn btn-outline">🕐 Sipariş Geçmişi</button>
                        <button class="btn btn-outline">☰ Aktif Siparişler</button>
                    </div>
                </section>

                 Stok ve Envanter Yönetimi Section 
                <section class="card">
                    <h3 class="section-title">Stok ve Envanter Yönetimi</h3>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <span class="stat-icon">🔄</span>
                            <span class="stat-label">Stok Yenileme</span>
                            <span class="badge badge-danger">14</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-icon">⚙️</span>
                            <span class="stat-label">Açık Üretim Siparişleri</span>
                            <span class="badge badge-danger">14</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-icon">✓</span>
                            <span class="stat-label">Onay Bekleyen Transfer</span>
                            <span class="badge badge-danger">14</span>
                        </div>
                        <div class="stat-card">
                            <span class="stat-icon">💰</span>
                            <span class="stat-label">Açık Dış Tedarik</span>
                            <span class="badge badge-danger">14</span>
                        </div>
                    </div>
                </section>

                 Ticket İşlemleri Section 
                <section class="card">
                    <h3 class="section-title">Ticket İşlemleri</h3>
                    <div class="button-grid">
                        <button class="btn btn-outline">
                            ✉️ Okunmamış Ticketlar
                            <span class="badge badge-danger">14</span>
                        </button>
                        <button class="btn btn-outline">+ Yeni Ticket Oluştur</button>
                    </div>
                </section>

                 En Çok Satılan 10 Ürün Chart 
                <section class="card">
                    <h3 class="section-title">En Çok Satılan 10 Ürün</h3>
                    <div class="chart-container">
                        <div class="bar-chart">
                            <div class="bar" style="height: 30%">
                                <div class="bar-fill" data-value="300"></div>
                                <div class="bar-label">Cheesecake</div>
                            </div>
                            <div class="bar" style="height: 95%">
                                <div class="bar-fill" data-value="950"></div>
                                <div class="bar-label">Beef</div>
                                <div class="bar-tooltip">Beef<br>Satış: 950 adet</div>
                            </div>
                            <div class="bar" style="height: 32%">
                                <div class="bar-fill" data-value="320"></div>
                                <div class="bar-label">Acuka</div>
                            </div>
                            <div class="bar" style="height: 75%">
                                <div class="bar-fill" data-value="750"></div>
                                <div class="bar-label">Füme Eti</div>
                            </div>
                            <div class="bar" style="height: 35%">
                                <div class="bar-fill" data-value="350"></div>
                                <div class="bar-label">Lotus</div>
                            </div>
                            <div class="bar" style="height: 28%">
                                <div class="bar-fill" data-value="280"></div>
                                <div class="bar-label">Strawberry</div>
                            </div>
                            <div class="bar" style="height: 42%">
                                <div class="bar-fill" data-value="420"></div>
                                <div class="bar-label">Tiramisu</div>
                            </div>
                            <div class="bar" style="height: 52%">
                                <div class="bar-fill" data-value="520"></div>
                                <div class="bar-label">Fit Cake</div>
                            </div>
                            <div class="bar" style="height: 100%">
                                <div class="bar-fill" data-value="1000"></div>
                                <div class="bar-label">Rocher</div>
                            </div>
                            <div class="bar" style="height: 5%">
                                <div class="bar-fill" data-value="50"></div>
                                <div class="bar-label">Frappe</div>
                            </div>
                        </div>
                    </div>
                </section>

                 Stok Raporu 
                <section class="card">
                    <h3 class="section-title">Stok Raporu</h3>
                    <div class="table-controls">
                        <div class="show-entries">
                            Show 
                            <select class="entries-select">
                                <option>5</option>
                                <option>10</option>
                                <option>25</option>
                            </select>
                            entries
                        </div>
                        <div class="search-box">
                            <label>Search:</label>
                            <input type="text" class="search-input">
                        </div>
                    </div>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Stok Kodu</th>
                                <th>Stok Adı</th>
                                <th>Girilen Miktar</th>
                                <th>Giriş Tarihi ▼</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>STK004</td>
                                <td>Organik Bal</td>
                                <td>1.500</td>
                                <td>26.10.2024</td>
                            </tr>
                            <tr>
                                <td>STK003</td>
                                <td>Türk Kahvesi</td>
                                <td>500</td>
                                <td>25.10.2024</td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="table-footer">
                        <div>Showing 1 to 2 of 2 entries</div>
                        <div class="pagination">
                            <button class="page-btn">Previous</button>
                            <button class="page-btn active">1</button>
                            <button class="page-btn">Next</button>
                        </div>
                    </div>
                </section>

                 En Çok Satış Yapan 3 Personel 
                <section class="card">
                    <h3 class="section-title">En Çok Satış Yapan 3 Personel</h3>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Sıra</th>
                                <th>Personel Adı</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="rank-badge gold">🥇</span></td>
                                <td>Cameron Williamson</td>
                            </tr>
                            <tr>
                                <td><span class="rank-badge silver">🥈</span></td>
                                <td>Jane Cooper</td>
                            </tr>
                            <tr>
                                <td><span class="rank-badge bronze">🥉</span></td>
                                <td>Esther Howard</td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>
        </main>
    </div>

    <script src="script.js"></script>
</body>
</html>