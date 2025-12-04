<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece MS (Muse) kullanıcıları giriş yapabilir
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'MS') {
    header("Location: index.php");
    exit;
}

$etkinlikId = $_GET['id'] ?? '';

if (empty($etkinlikId)) {
    header("Location: Muse.php");
    exit;
}

// Statik etkinlik verileri (gerçekte veritabanından gelecek)
$etkinlikler = [
    1 => [
        'id' => 1,
        'ad' => 'Yılbaşı Kutlaması',
        'aciklama' => 'Yeni yıla özel müzik ve eğlence gecesi',
        'baslangicTarihi' => '2024-12-31',
        'baslangicSaati' => '20:00',
        'bitisTarihi' => '2025-01-01',
        'bitisSaati' => '02:00',
        'kapasite' => 150,
        'yer' => 'Taksim Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Ahmet Yılmaz',
        'iletisimNotu' => 'Sahne kurulumu için 18:00\'de hazır olunmalı',
        'durum' => 'Planlandı',
        'olusturmaTarihi' => '2024-11-15',
        'olusturan' => 'Ayşe Demir'
    ],
    2 => [
        'id' => 2,
        'ad' => 'Müzik Gecesi',
        'aciklama' => 'Yerli sanatçıların canlı performansı',
        'baslangicTarihi' => '2024-12-15',
        'baslangicSaati' => '19:30',
        'bitisTarihi' => '2024-12-15',
        'bitisSaati' => '23:00',
        'kapasite' => 80,
        'yer' => 'Kadıköy Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Mehmet Kaya',
        'iletisimNotu' => '',
        'durum' => 'Tamamlandı',
        'olusturmaTarihi' => '2024-11-10',
        'olusturan' => 'Zeynep Özkan'
    ],
    3 => [
        'id' => 3,
        'ad' => 'Workshop: Dijital Pazarlama',
        'aciklama' => 'Dijital pazarlama stratejileri ve sosyal medya yönetimi workshop\'u',
        'baslangicTarihi' => '2024-12-20',
        'baslangicSaati' => '14:00',
        'bitisTarihi' => '2024-12-20',
        'bitisSaati' => '17:00',
        'kapasite' => 50,
        'yer' => 'Harici Lokasyon',
        'yerTipi' => 'harici',
        'lokasyonAdi' => 'İstanbul Teknoloji Merkezi',
        'adres' => 'Maslak Mahallesi, Büyükdere Cad. No:123, Sarıyer/İstanbul',
        'sorumlu' => 'Ayşe Demir',
        'iletisimNotu' => 'Projeksiyon cihazı ve mikrofon gerekli',
        'durum' => 'Planlandı',
        'olusturmaTarihi' => '2024-11-20',
        'olusturan' => 'Ahmet Yılmaz'
    ],
    4 => [
        'id' => 4,
        'ad' => 'Sanat Sergisi',
        'aciklama' => 'Yerel sanatçıların eserlerinin sergilendiği etkinlik',
        'baslangicTarihi' => '2024-11-10',
        'baslangicSaati' => '10:00',
        'bitisTarihi' => '2024-11-20',
        'bitisSaati' => '18:00',
        'kapasite' => 200,
        'yer' => 'Taksim Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Zeynep Özkan',
        'iletisimNotu' => '',
        'durum' => 'İptal',
        'olusturmaTarihi' => '2024-10-15',
        'olusturan' => 'Mehmet Kaya'
    ],
    5 => [
        'id' => 5,
        'ad' => 'Konser: Yerli Sanatçılar',
        'aciklama' => 'Türk müziğinin önde gelen isimlerinin konseri',
        'baslangicTarihi' => '2024-12-25',
        'baslangicSaati' => '21:00',
        'bitisTarihi' => '2024-12-25',
        'bitisSaati' => '00:30',
        'kapasite' => 120,
        'yer' => 'Kadıköy Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Ahmet Yılmaz',
        'iletisimNotu' => 'Ses sistemi ve ışık kurulumu gerekli',
        'durum' => 'Planlandı',
        'olusturmaTarihi' => '2024-11-25',
        'olusturan' => 'Ayşe Demir'
    ]
];

// Etkinlik içeriği (sepet verileri - gerçekte veritabanından gelecek)
$etkinlikIcerikleri = [
    1 => [
        ['kaynak' => 'Kitapevi', 'urun' => 'Sapiens', 'birim' => 'Adet', 'miktar' => 20, 'not' => 'İmza günü için'],
        ['kaynak' => 'Kafe', 'urun' => 'Espresso', 'birim' => 'Porsiyon', 'miktar' => 100, 'not' => ''],
        ['kaynak' => 'Kafe', 'urun' => 'Cheesecake', 'birim' => 'Porsiyon', 'miktar' => 50, 'not' => ''],
        ['kaynak' => 'Restoran', 'urun' => 'Izgara Tavuk', 'birim' => 'Porsiyon', 'miktar' => 80, 'not' => 'Vejetaryen seçenek de gerekli']
    ],
    2 => [
        ['kaynak' => 'Kafe', 'urun' => 'Cappuccino', 'birim' => 'Porsiyon', 'miktar' => 60, 'not' => ''],
        ['kaynak' => 'Kafe', 'urun' => 'Brownie', 'birim' => 'Porsiyon', 'miktar' => 40, 'not' => ''],
        ['kaynak' => 'Restoran', 'urun' => 'Mantı', 'birim' => 'Porsiyon', 'miktar' => 50, 'not' => '']
    ],
    3 => [
        ['kaynak' => 'Kitapevi', 'urun' => 'Dune', 'birim' => 'Adet', 'miktar' => 15, 'not' => 'Workshop katılımcılarına hediye'],
        ['kaynak' => 'Kafe', 'urun' => 'Latte', 'birim' => 'Porsiyon', 'miktar' => 50, 'not' => '']
    ],
    4 => [],
    5 => [
        ['kaynak' => 'Kafe', 'urun' => 'Soğuk Kahve', 'birim' => 'Porsiyon', 'miktar' => 80, 'not' => ''],
        ['kaynak' => 'Restoran', 'urun' => 'Çoban Salata', 'birim' => 'Porsiyon', 'miktar' => 100, 'not' => '']
    ]
];

$etkinlik = $etkinlikler[$etkinlikId] ?? null;
$icerik = $etkinlikIcerikleri[$etkinlikId] ?? [];

if (!$etkinlik) {
    header("Location: Muse.php");
    exit;
}

$durumClassMap = [
    'Planlandı' => 'status-planlandi',
    'Tamamlandı' => 'status-tamamlandi',
    'İptal' => 'status-iptal'
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etkinlik Detay - MINOA</title>
    <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: #f5f7fa;
    color: #2c3e50;
    line-height: 1.6;
}

.main-content {
    width: 100%;
    background: whitesmoke;
    padding: 0;
    min-height: 100vh;
}

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
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible;
}

.card-header {
    padding: 24px;
    border-bottom: 1px solid #e5e7eb;
}

.card-header h3 {
    color: #1e40af;
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 20px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.info-label {
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    font-size: 16px;
    color: #1f2937;
    font-weight: 500;
}

.card-body {
    padding: 24px;
}

.card-body h4 {
    color: #1e40af;
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 20px;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    display: inline-block;
}

.status-planlandi {
    background: #dbeafe;
    color: #1e40af;
}

.status-tamamlandi {
    background: #d1fae5;
    color: #065f46;
}

.status-iptal {
    background: #fee2e2;
    color: #991b1b;
}

.btn {
    padding: 0.625rem 1.25rem;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #f0f9ff;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

thead {
    background: #f8fafc;
}

thead th {
    padding: 16px 20px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    white-space: nowrap;
}

tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.15s ease;
}

tbody tr:hover {
    background: #f9fafb;
}

tbody td {
    padding: 16px 20px;
    color: #4b5563;
}

.kaynak-badge {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 500;
    display: inline-block;
}

.kaynak-kitapevi {
    background: #dbeafe;
    color: #1e40af;
}

.kaynak-kafe {
    background: #fef3c7;
    color: #92400e;
}

.kaynak-restoran {
    background: #d1fae5;
    color: #065f46;
}

.kaynak-dis-tedarik {
    background: #e9d5ff;
    color: #6b21a8;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #9ca3af;
    font-size: 14px;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header {
        padding: 16px 1rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        height: auto;
    }

    .content-wrapper {
        padding: 16px;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2>Etkinlik Detay</h2>
            <a href="Muse.php" class="btn btn-secondary">← Geri</a>
        </div>

        <div class="content-wrapper">
            <!-- Etkinlik Bilgileri -->
            <div class="card">
                <div class="card-header">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3><?= htmlspecialchars($etkinlik['ad']) ?></h3>
                        <span class="status-badge <?= $durumClassMap[$etkinlik['durum']] ?? '' ?>">
                            <?= htmlspecialchars($etkinlik['durum']) ?>
                        </span>
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Etkinlik Adı</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['ad']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Açıklama</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['aciklama'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Başlangıç Tarihi / Saati</span>
                            <span class="info-value">
                                <?= htmlspecialchars($etkinlik['baslangicTarihi']) ?> 
                                <?= htmlspecialchars($etkinlik['baslangicSaati']) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Bitiş Tarihi / Saati</span>
                            <span class="info-value">
                                <?= htmlspecialchars($etkinlik['bitisTarihi']) ?> 
                                <?= htmlspecialchars($etkinlik['bitisSaati']) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Kapasite</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['kapasite']) ?> kişi</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Etkinlik Yeri</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['yer']) ?></span>
                        </div>
                        <?php if ($etkinlik['yerTipi'] === 'harici'): ?>
                        <div class="info-item">
                            <span class="info-label">Lokasyon Adı</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['lokasyonAdi'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Adres</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['adres'] ?? '') ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <span class="info-label">Etkinlik Sorumlusu</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['sorumlu'] ?? '') ?></span>
                        </div>
                        <?php if (!empty($etkinlik['iletisimNotu'])): ?>
                        <div class="info-item">
                            <span class="info-label">İletişim Notu</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['iletisimNotu']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Etkinlik İçeriği -->
            <div class="card">
                <div class="card-body">
                    <h4>Etkinlik İçeriği</h4>
                
                    <?php if (empty($icerik)): ?>
                    <div class="empty-state">Bu etkinlik için henüz içerik eklenmemiş.</div>
                    <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Kaynak</th>
                                    <th>Ürün / İçerik Adı</th>
                                    <th>Birim</th>
                                    <th>Miktar</th>
                                    <th>Not</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $kaynakClassMap = [
                                    'Kitapevi' => 'kaynak-kitapevi',
                                    'Kafe' => 'kaynak-kafe',
                                    'Restoran' => 'kaynak-restoran',
                                    'Dış Tedarik' => 'kaynak-dis-tedarik'
                                ];
                                
                                foreach ($icerik as $item): 
                                    $kaynakClass = $kaynakClassMap[$item['kaynak']] ?? '';
                                ?>
                                <tr>
                                    <td>
                                        <span class="kaynak-badge <?= $kaynakClass ?>">
                                            <?= htmlspecialchars($item['kaynak']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($item['urun']) ?></td>
                                    <td><?= htmlspecialchars($item['birim']) ?></td>
                                    <td><?= htmlspecialchars($item['miktar']) ?></td>
                                    <td><?= htmlspecialchars($item['not'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sistem Bilgileri -->
            <div class="card">
                <div class="card-header">
                    <h3>Sistem Bilgileri</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Oluşturma Tarihi</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['olusturmaTarihi'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Oluşturan</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['olusturan'] ?? '') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>


if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece MS (Muse) kullanıcıları giriş yapabilir
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'MS') {
    header("Location: index.php");
    exit;
}

$etkinlikId = $_GET['id'] ?? '';

if (empty($etkinlikId)) {
    header("Location: Muse.php");
    exit;
}

// Statik etkinlik verileri (gerçekte veritabanından gelecek)
$etkinlikler = [
    1 => [
        'id' => 1,
        'ad' => 'Yılbaşı Kutlaması',
        'aciklama' => 'Yeni yıla özel müzik ve eğlence gecesi',
        'baslangicTarihi' => '2024-12-31',
        'baslangicSaati' => '20:00',
        'bitisTarihi' => '2025-01-01',
        'bitisSaati' => '02:00',
        'kapasite' => 150,
        'yer' => 'Taksim Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Ahmet Yılmaz',
        'iletisimNotu' => 'Sahne kurulumu için 18:00\'de hazır olunmalı',
        'durum' => 'Planlandı',
        'olusturmaTarihi' => '2024-11-15',
        'olusturan' => 'Ayşe Demir'
    ],
    2 => [
        'id' => 2,
        'ad' => 'Müzik Gecesi',
        'aciklama' => 'Yerli sanatçıların canlı performansı',
        'baslangicTarihi' => '2024-12-15',
        'baslangicSaati' => '19:30',
        'bitisTarihi' => '2024-12-15',
        'bitisSaati' => '23:00',
        'kapasite' => 80,
        'yer' => 'Kadıköy Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Mehmet Kaya',
        'iletisimNotu' => '',
        'durum' => 'Tamamlandı',
        'olusturmaTarihi' => '2024-11-10',
        'olusturan' => 'Zeynep Özkan'
    ],
    3 => [
        'id' => 3,
        'ad' => 'Workshop: Dijital Pazarlama',
        'aciklama' => 'Dijital pazarlama stratejileri ve sosyal medya yönetimi workshop\'u',
        'baslangicTarihi' => '2024-12-20',
        'baslangicSaati' => '14:00',
        'bitisTarihi' => '2024-12-20',
        'bitisSaati' => '17:00',
        'kapasite' => 50,
        'yer' => 'Harici Lokasyon',
        'yerTipi' => 'harici',
        'lokasyonAdi' => 'İstanbul Teknoloji Merkezi',
        'adres' => 'Maslak Mahallesi, Büyükdere Cad. No:123, Sarıyer/İstanbul',
        'sorumlu' => 'Ayşe Demir',
        'iletisimNotu' => 'Projeksiyon cihazı ve mikrofon gerekli',
        'durum' => 'Planlandı',
        'olusturmaTarihi' => '2024-11-20',
        'olusturan' => 'Ahmet Yılmaz'
    ],
    4 => [
        'id' => 4,
        'ad' => 'Sanat Sergisi',
        'aciklama' => 'Yerel sanatçıların eserlerinin sergilendiği etkinlik',
        'baslangicTarihi' => '2024-11-10',
        'baslangicSaati' => '10:00',
        'bitisTarihi' => '2024-11-20',
        'bitisSaati' => '18:00',
        'kapasite' => 200,
        'yer' => 'Taksim Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Zeynep Özkan',
        'iletisimNotu' => '',
        'durum' => 'İptal',
        'olusturmaTarihi' => '2024-10-15',
        'olusturan' => 'Mehmet Kaya'
    ],
    5 => [
        'id' => 5,
        'ad' => 'Konser: Yerli Sanatçılar',
        'aciklama' => 'Türk müziğinin önde gelen isimlerinin konseri',
        'baslangicTarihi' => '2024-12-25',
        'baslangicSaati' => '21:00',
        'bitisTarihi' => '2024-12-25',
        'bitisSaati' => '00:30',
        'kapasite' => 120,
        'yer' => 'Kadıköy Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Ahmet Yılmaz',
        'iletisimNotu' => 'Ses sistemi ve ışık kurulumu gerekli',
        'durum' => 'Planlandı',
        'olusturmaTarihi' => '2024-11-25',
        'olusturan' => 'Ayşe Demir'
    ]
];

// Etkinlik içeriği (sepet verileri - gerçekte veritabanından gelecek)
$etkinlikIcerikleri = [
    1 => [
        ['kaynak' => 'Kitapevi', 'urun' => 'Sapiens', 'birim' => 'Adet', 'miktar' => 20, 'not' => 'İmza günü için'],
        ['kaynak' => 'Kafe', 'urun' => 'Espresso', 'birim' => 'Porsiyon', 'miktar' => 100, 'not' => ''],
        ['kaynak' => 'Kafe', 'urun' => 'Cheesecake', 'birim' => 'Porsiyon', 'miktar' => 50, 'not' => ''],
        ['kaynak' => 'Restoran', 'urun' => 'Izgara Tavuk', 'birim' => 'Porsiyon', 'miktar' => 80, 'not' => 'Vejetaryen seçenek de gerekli']
    ],
    2 => [
        ['kaynak' => 'Kafe', 'urun' => 'Cappuccino', 'birim' => 'Porsiyon', 'miktar' => 60, 'not' => ''],
        ['kaynak' => 'Kafe', 'urun' => 'Brownie', 'birim' => 'Porsiyon', 'miktar' => 40, 'not' => ''],
        ['kaynak' => 'Restoran', 'urun' => 'Mantı', 'birim' => 'Porsiyon', 'miktar' => 50, 'not' => '']
    ],
    3 => [
        ['kaynak' => 'Kitapevi', 'urun' => 'Dune', 'birim' => 'Adet', 'miktar' => 15, 'not' => 'Workshop katılımcılarına hediye'],
        ['kaynak' => 'Kafe', 'urun' => 'Latte', 'birim' => 'Porsiyon', 'miktar' => 50, 'not' => '']
    ],
    4 => [],
    5 => [
        ['kaynak' => 'Kafe', 'urun' => 'Soğuk Kahve', 'birim' => 'Porsiyon', 'miktar' => 80, 'not' => ''],
        ['kaynak' => 'Restoran', 'urun' => 'Çoban Salata', 'birim' => 'Porsiyon', 'miktar' => 100, 'not' => '']
    ]
];

$etkinlik = $etkinlikler[$etkinlikId] ?? null;
$icerik = $etkinlikIcerikleri[$etkinlikId] ?? [];

if (!$etkinlik) {
    header("Location: Muse.php");
    exit;
}

$durumClassMap = [
    'Planlandı' => 'status-planlandi',
    'Tamamlandı' => 'status-tamamlandi',
    'İptal' => 'status-iptal'
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etkinlik Detay - MINOA</title>
    <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: #f5f7fa;
    color: #2c3e50;
    line-height: 1.6;
}

.main-content {
    width: 100%;
    background: whitesmoke;
    padding: 0;
    min-height: 100vh;
}

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
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible;
}

.card-header {
    padding: 24px;
    border-bottom: 1px solid #e5e7eb;
}

.card-header h3 {
    color: #1e40af;
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 20px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.info-label {
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    font-size: 16px;
    color: #1f2937;
    font-weight: 500;
}

.card-body {
    padding: 24px;
}

.card-body h4 {
    color: #1e40af;
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 20px;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    display: inline-block;
}

.status-planlandi {
    background: #dbeafe;
    color: #1e40af;
}

.status-tamamlandi {
    background: #d1fae5;
    color: #065f46;
}

.status-iptal {
    background: #fee2e2;
    color: #991b1b;
}

.btn {
    padding: 0.625rem 1.25rem;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #f0f9ff;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

thead {
    background: #f8fafc;
}

thead th {
    padding: 16px 20px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    white-space: nowrap;
}

tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.15s ease;
}

tbody tr:hover {
    background: #f9fafb;
}

tbody td {
    padding: 16px 20px;
    color: #4b5563;
}

.kaynak-badge {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 500;
    display: inline-block;
}

.kaynak-kitapevi {
    background: #dbeafe;
    color: #1e40af;
}

.kaynak-kafe {
    background: #fef3c7;
    color: #92400e;
}

.kaynak-restoran {
    background: #d1fae5;
    color: #065f46;
}

.kaynak-dis-tedarik {
    background: #e9d5ff;
    color: #6b21a8;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #9ca3af;
    font-size: 14px;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header {
        padding: 16px 1rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        height: auto;
    }

    .content-wrapper {
        padding: 16px;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2>Etkinlik Detay</h2>
            <a href="Muse.php" class="btn btn-secondary">← Geri</a>
        </div>

        <div class="content-wrapper">
            <!-- Etkinlik Bilgileri -->
            <div class="card">
                <div class="card-header">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3><?= htmlspecialchars($etkinlik['ad']) ?></h3>
                        <span class="status-badge <?= $durumClassMap[$etkinlik['durum']] ?? '' ?>">
                            <?= htmlspecialchars($etkinlik['durum']) ?>
                        </span>
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Etkinlik Adı</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['ad']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Açıklama</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['aciklama'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Başlangıç Tarihi / Saati</span>
                            <span class="info-value">
                                <?= htmlspecialchars($etkinlik['baslangicTarihi']) ?> 
                                <?= htmlspecialchars($etkinlik['baslangicSaati']) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Bitiş Tarihi / Saati</span>
                            <span class="info-value">
                                <?= htmlspecialchars($etkinlik['bitisTarihi']) ?> 
                                <?= htmlspecialchars($etkinlik['bitisSaati']) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Kapasite</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['kapasite']) ?> kişi</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Etkinlik Yeri</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['yer']) ?></span>
                        </div>
                        <?php if ($etkinlik['yerTipi'] === 'harici'): ?>
                        <div class="info-item">
                            <span class="info-label">Lokasyon Adı</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['lokasyonAdi'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Adres</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['adres'] ?? '') ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <span class="info-label">Etkinlik Sorumlusu</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['sorumlu'] ?? '') ?></span>
                        </div>
                        <?php if (!empty($etkinlik['iletisimNotu'])): ?>
                        <div class="info-item">
                            <span class="info-label">İletişim Notu</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['iletisimNotu']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Etkinlik İçeriği -->
            <div class="card">
                <div class="card-body">
                    <h4>Etkinlik İçeriği</h4>
                
                    <?php if (empty($icerik)): ?>
                    <div class="empty-state">Bu etkinlik için henüz içerik eklenmemiş.</div>
                    <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Kaynak</th>
                                    <th>Ürün / İçerik Adı</th>
                                    <th>Birim</th>
                                    <th>Miktar</th>
                                    <th>Not</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $kaynakClassMap = [
                                    'Kitapevi' => 'kaynak-kitapevi',
                                    'Kafe' => 'kaynak-kafe',
                                    'Restoran' => 'kaynak-restoran',
                                    'Dış Tedarik' => 'kaynak-dis-tedarik'
                                ];
                                
                                foreach ($icerik as $item): 
                                    $kaynakClass = $kaynakClassMap[$item['kaynak']] ?? '';
                                ?>
                                <tr>
                                    <td>
                                        <span class="kaynak-badge <?= $kaynakClass ?>">
                                            <?= htmlspecialchars($item['kaynak']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($item['urun']) ?></td>
                                    <td><?= htmlspecialchars($item['birim']) ?></td>
                                    <td><?= htmlspecialchars($item['miktar']) ?></td>
                                    <td><?= htmlspecialchars($item['not'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sistem Bilgileri -->
            <div class="card">
                <div class="card-header">
                    <h3>Sistem Bilgileri</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Oluşturma Tarihi</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['olusturmaTarihi'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Oluşturan</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['olusturan'] ?? '') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>


if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}

// Sadece MS (Muse) kullanıcıları giriş yapabilir
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
if ($uAsOwnr !== 'MS') {
    header("Location: index.php");
    exit;
}

$etkinlikId = $_GET['id'] ?? '';

if (empty($etkinlikId)) {
    header("Location: Muse.php");
    exit;
}

// Statik etkinlik verileri (gerçekte veritabanından gelecek)
$etkinlikler = [
    1 => [
        'id' => 1,
        'ad' => 'Yılbaşı Kutlaması',
        'aciklama' => 'Yeni yıla özel müzik ve eğlence gecesi',
        'baslangicTarihi' => '2024-12-31',
        'baslangicSaati' => '20:00',
        'bitisTarihi' => '2025-01-01',
        'bitisSaati' => '02:00',
        'kapasite' => 150,
        'yer' => 'Taksim Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Ahmet Yılmaz',
        'iletisimNotu' => 'Sahne kurulumu için 18:00\'de hazır olunmalı',
        'durum' => 'Planlandı',
        'olusturmaTarihi' => '2024-11-15',
        'olusturan' => 'Ayşe Demir'
    ],
    2 => [
        'id' => 2,
        'ad' => 'Müzik Gecesi',
        'aciklama' => 'Yerli sanatçıların canlı performansı',
        'baslangicTarihi' => '2024-12-15',
        'baslangicSaati' => '19:30',
        'bitisTarihi' => '2024-12-15',
        'bitisSaati' => '23:00',
        'kapasite' => 80,
        'yer' => 'Kadıköy Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Mehmet Kaya',
        'iletisimNotu' => '',
        'durum' => 'Tamamlandı',
        'olusturmaTarihi' => '2024-11-10',
        'olusturan' => 'Zeynep Özkan'
    ],
    3 => [
        'id' => 3,
        'ad' => 'Workshop: Dijital Pazarlama',
        'aciklama' => 'Dijital pazarlama stratejileri ve sosyal medya yönetimi workshop\'u',
        'baslangicTarihi' => '2024-12-20',
        'baslangicSaati' => '14:00',
        'bitisTarihi' => '2024-12-20',
        'bitisSaati' => '17:00',
        'kapasite' => 50,
        'yer' => 'Harici Lokasyon',
        'yerTipi' => 'harici',
        'lokasyonAdi' => 'İstanbul Teknoloji Merkezi',
        'adres' => 'Maslak Mahallesi, Büyükdere Cad. No:123, Sarıyer/İstanbul',
        'sorumlu' => 'Ayşe Demir',
        'iletisimNotu' => 'Projeksiyon cihazı ve mikrofon gerekli',
        'durum' => 'Planlandı',
        'olusturmaTarihi' => '2024-11-20',
        'olusturan' => 'Ahmet Yılmaz'
    ],
    4 => [
        'id' => 4,
        'ad' => 'Sanat Sergisi',
        'aciklama' => 'Yerel sanatçıların eserlerinin sergilendiği etkinlik',
        'baslangicTarihi' => '2024-11-10',
        'baslangicSaati' => '10:00',
        'bitisTarihi' => '2024-11-20',
        'bitisSaati' => '18:00',
        'kapasite' => 200,
        'yer' => 'Taksim Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Zeynep Özkan',
        'iletisimNotu' => '',
        'durum' => 'İptal',
        'olusturmaTarihi' => '2024-10-15',
        'olusturan' => 'Mehmet Kaya'
    ],
    5 => [
        'id' => 5,
        'ad' => 'Konser: Yerli Sanatçılar',
        'aciklama' => 'Türk müziğinin önde gelen isimlerinin konseri',
        'baslangicTarihi' => '2024-12-25',
        'baslangicSaati' => '21:00',
        'bitisTarihi' => '2024-12-25',
        'bitisSaati' => '00:30',
        'kapasite' => 120,
        'yer' => 'Kadıköy Şube',
        'yerTipi' => 'sube',
        'sorumlu' => 'Ahmet Yılmaz',
        'iletisimNotu' => 'Ses sistemi ve ışık kurulumu gerekli',
        'durum' => 'Planlandı',
        'olusturmaTarihi' => '2024-11-25',
        'olusturan' => 'Ayşe Demir'
    ]
];

// Etkinlik içeriği (sepet verileri - gerçekte veritabanından gelecek)
$etkinlikIcerikleri = [
    1 => [
        ['kaynak' => 'Kitapevi', 'urun' => 'Sapiens', 'birim' => 'Adet', 'miktar' => 20, 'not' => 'İmza günü için'],
        ['kaynak' => 'Kafe', 'urun' => 'Espresso', 'birim' => 'Porsiyon', 'miktar' => 100, 'not' => ''],
        ['kaynak' => 'Kafe', 'urun' => 'Cheesecake', 'birim' => 'Porsiyon', 'miktar' => 50, 'not' => ''],
        ['kaynak' => 'Restoran', 'urun' => 'Izgara Tavuk', 'birim' => 'Porsiyon', 'miktar' => 80, 'not' => 'Vejetaryen seçenek de gerekli']
    ],
    2 => [
        ['kaynak' => 'Kafe', 'urun' => 'Cappuccino', 'birim' => 'Porsiyon', 'miktar' => 60, 'not' => ''],
        ['kaynak' => 'Kafe', 'urun' => 'Brownie', 'birim' => 'Porsiyon', 'miktar' => 40, 'not' => ''],
        ['kaynak' => 'Restoran', 'urun' => 'Mantı', 'birim' => 'Porsiyon', 'miktar' => 50, 'not' => '']
    ],
    3 => [
        ['kaynak' => 'Kitapevi', 'urun' => 'Dune', 'birim' => 'Adet', 'miktar' => 15, 'not' => 'Workshop katılımcılarına hediye'],
        ['kaynak' => 'Kafe', 'urun' => 'Latte', 'birim' => 'Porsiyon', 'miktar' => 50, 'not' => '']
    ],
    4 => [],
    5 => [
        ['kaynak' => 'Kafe', 'urun' => 'Soğuk Kahve', 'birim' => 'Porsiyon', 'miktar' => 80, 'not' => ''],
        ['kaynak' => 'Restoran', 'urun' => 'Çoban Salata', 'birim' => 'Porsiyon', 'miktar' => 100, 'not' => '']
    ]
];

$etkinlik = $etkinlikler[$etkinlikId] ?? null;
$icerik = $etkinlikIcerikleri[$etkinlikId] ?? [];

if (!$etkinlik) {
    header("Location: Muse.php");
    exit;
}

$durumClassMap = [
    'Planlandı' => 'status-planlandi',
    'Tamamlandı' => 'status-tamamlandi',
    'İptal' => 'status-iptal'
];
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Etkinlik Detay - MINOA</title>
    <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: #f5f7fa;
    color: #2c3e50;
    line-height: 1.6;
}

.main-content {
    width: 100%;
    background: whitesmoke;
    padding: 0;
    min-height: 100vh;
}

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
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}

.card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    margin-bottom: 24px;
    overflow: visible;
}

.card-header {
    padding: 24px;
    border-bottom: 1px solid #e5e7eb;
}

.card-header h3 {
    color: #1e40af;
    font-size: 1.5rem;
    font-weight: 600;
    margin-bottom: 20px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.info-label {
    font-size: 13px;
    color: #6b7280;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    font-size: 16px;
    color: #1f2937;
    font-weight: 500;
}

.card-body {
    padding: 24px;
}

.card-body h4 {
    color: #1e40af;
    font-size: 1.25rem;
    font-weight: 600;
    margin-bottom: 20px;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    display: inline-block;
}

.status-planlandi {
    background: #dbeafe;
    color: #1e40af;
}

.status-tamamlandi {
    background: #d1fae5;
    color: #065f46;
}

.status-iptal {
    background: #fee2e2;
    color: #991b1b;
}

.btn {
    padding: 0.625rem 1.25rem;
    border: none;
    border-radius: 8px;
    font-size: 0.95rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #f0f9ff;
}

.table-container {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

thead {
    background: #f8fafc;
}

thead th {
    padding: 16px 20px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
    white-space: nowrap;
}

tbody tr {
    border-bottom: 1px solid #e5e7eb;
    transition: background 0.15s ease;
}

tbody tr:hover {
    background: #f9fafb;
}

tbody td {
    padding: 16px 20px;
    color: #4b5563;
}

.kaynak-badge {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 500;
    display: inline-block;
}

.kaynak-kitapevi {
    background: #dbeafe;
    color: #1e40af;
}

.kaynak-kafe {
    background: #fef3c7;
    color: #92400e;
}

.kaynak-restoran {
    background: #d1fae5;
    color: #065f46;
}

.kaynak-dis-tedarik {
    background: #e9d5ff;
    color: #6b21a8;
}

.empty-state {
    text-align: center;
    padding: 40px 20px;
    color: #9ca3af;
    font-size: 14px;
}

/* Responsive */
@media (max-width: 768px) {
    .page-header {
        padding: 16px 1rem;
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
        height: auto;
    }

    .content-wrapper {
        padding: 16px;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }
}
</style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="main-content">
        <div class="page-header">
            <h2>Etkinlik Detay</h2>
            <a href="Muse.php" class="btn btn-secondary">← Geri</a>
        </div>

        <div class="content-wrapper">
            <!-- Etkinlik Bilgileri -->
            <div class="card">
                <div class="card-header">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h3><?= htmlspecialchars($etkinlik['ad']) ?></h3>
                        <span class="status-badge <?= $durumClassMap[$etkinlik['durum']] ?? '' ?>">
                            <?= htmlspecialchars($etkinlik['durum']) ?>
                        </span>
                    </div>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Etkinlik Adı</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['ad']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Açıklama</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['aciklama'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Başlangıç Tarihi / Saati</span>
                            <span class="info-value">
                                <?= htmlspecialchars($etkinlik['baslangicTarihi']) ?> 
                                <?= htmlspecialchars($etkinlik['baslangicSaati']) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Bitiş Tarihi / Saati</span>
                            <span class="info-value">
                                <?= htmlspecialchars($etkinlik['bitisTarihi']) ?> 
                                <?= htmlspecialchars($etkinlik['bitisSaati']) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Kapasite</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['kapasite']) ?> kişi</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Etkinlik Yeri</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['yer']) ?></span>
                        </div>
                        <?php if ($etkinlik['yerTipi'] === 'harici'): ?>
                        <div class="info-item">
                            <span class="info-label">Lokasyon Adı</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['lokasyonAdi'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Adres</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['adres'] ?? '') ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="info-item">
                            <span class="info-label">Etkinlik Sorumlusu</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['sorumlu'] ?? '') ?></span>
                        </div>
                        <?php if (!empty($etkinlik['iletisimNotu'])): ?>
                        <div class="info-item">
                            <span class="info-label">İletişim Notu</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['iletisimNotu']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Etkinlik İçeriği -->
            <div class="card">
                <div class="card-body">
                    <h4>Etkinlik İçeriği</h4>
                
                    <?php if (empty($icerik)): ?>
                    <div class="empty-state">Bu etkinlik için henüz içerik eklenmemiş.</div>
                    <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Kaynak</th>
                                    <th>Ürün / İçerik Adı</th>
                                    <th>Birim</th>
                                    <th>Miktar</th>
                                    <th>Not</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $kaynakClassMap = [
                                    'Kitapevi' => 'kaynak-kitapevi',
                                    'Kafe' => 'kaynak-kafe',
                                    'Restoran' => 'kaynak-restoran',
                                    'Dış Tedarik' => 'kaynak-dis-tedarik'
                                ];
                                
                                foreach ($icerik as $item): 
                                    $kaynakClass = $kaynakClassMap[$item['kaynak']] ?? '';
                                ?>
                                <tr>
                                    <td>
                                        <span class="kaynak-badge <?= $kaynakClass ?>">
                                            <?= htmlspecialchars($item['kaynak']) ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars($item['urun']) ?></td>
                                    <td><?= htmlspecialchars($item['birim']) ?></td>
                                    <td><?= htmlspecialchars($item['miktar']) ?></td>
                                    <td><?= htmlspecialchars($item['not'] ?? '') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sistem Bilgileri -->
            <div class="card">
                <div class="card-header">
                    <h3>Sistem Bilgileri</h3>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Oluşturma Tarihi</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['olusturmaTarihi'] ?? '') ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Oluşturan</span>
                            <span class="info-value"><?= htmlspecialchars($etkinlik['olusturan'] ?? '') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>

