<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// Session'dan bilgileri al
$uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
$branch = $_SESSION["Branch2"]["Name"] ?? $_SESSION["WhsCode"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// Filtreler (GET parametrelerinden)
$filterStatus = $_GET['status'] ?? '';
$filterStartDate = $_GET['start_date'] ?? '';
$filterEndDate = $_GET['end_date'] ?? '';

// PurchaseRequestList sorgusu
// Spec'e göre: Filtreleme direkt view'de yapılmalı
// GET /b1s/v2/view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '100'
$filter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}'";

// Status filtresi
if (!empty($filterStatus)) {
    $filter .= " and U_ASB2B_STATUS eq '{$filterStatus}'";
}

// Tarih filtreleri (RequriedDate veya DocDate üzerinden)
// Not: View'de tarih alanı olmayabilir, bu yüzden client-side filtreleme de yapılabilir
// Şimdilik view'de filtreleme yapmıyoruz, client-side yapacağız

$query = 'view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=' . urlencode($filter) . '&$orderby=' . urlencode('RequestNo desc') . '&$top=1000';

$data = $sap->get($query);
$allRows = $data['response']['value'] ?? [];

// ✅ PERFORMANS İYİLEŞTİRMESİ: Her satır için ayrı API çağrısı yapmak yerine
// View'den gelen DocDate'i kullanıyoruz. RequriedDate için ayrı API çağrısı yapmıyoruz.
// Eğer RequriedDate gerekirse, view'e eklenmeli veya lazy loading yapılmalı.
$requestDates = []; // Boş bırakıyoruz, view'den gelen DocDate kullanılacak

// Debug bilgileri
$debugInfo = [];
$debugInfo['session_uAsOwnr'] = $uAsOwnr;
$debugInfo['session_branch'] = $branch;
$debugInfo['note'] = 'Filtreleme direkt view\'de yapılıyor (U_AS_OWNR ve U_ASB2B_BRAN)';
$debugInfo['filter'] = $filter;
$debugInfo['query'] = $query;
$debugInfo['http_status'] = $data['status'] ?? 'NO STATUS';
$debugInfo['response_keys'] = isset($data['response']) ? array_keys($data['response']) : [];
$debugInfo['has_value'] = isset($data['response']['value']);
$debugInfo['row_count'] = count($allRows);
$debugInfo['error'] = $data['error'] ?? null;
$debugInfo['response_error'] = $data['response']['error'] ?? null;

// Status mapping
function getStatusText($status) {
    $statusMap = [
        '1' => 'Onay bekleniyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal edildi'
    ];
    return $statusMap[$status] ?? 'Bilinmiyor';
}

function getStatusClass($status) {
    $classMap = [
        '1' => 'status-pending',
        '2' => 'status-processing',
        '3' => 'status-shipped',
        '4' => 'status-completed',
        '5' => 'status-cancelled'
    ];
    return $classMap[$status] ?? 'status-unknown';
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dis Tedarik Siparişleri - MINOA</title>
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
    margin: 0;
}

.content-wrapper {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}

.card {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 1.5rem;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.875rem;
}

.data-table th {
    background: #f9fafb;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #374151;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.status-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 600;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-processing {
    background: #dbeafe;
    color: #1e40af;
}

.status-shipped {
    background: #e0e7ff;
    color: #4338ca;
}

.status-completed {
    background: #d1fae5;
    color: #065f46;
}

.status-cancelled {
    background: #fee2e2;
    color: #991b1b;
}

.status-unknown {
    background: #f3f4f6;
    color: #6b7280;
}

.btn {
    padding: 0.5rem 1rem;
    border: none;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    margin-right: 0.5rem;
}

.btn-primary {
    background: #3b82f6;
    color: white;
}

.btn-primary:hover {
    background: #2563eb;
}

.btn-view {
    background: #e0e7ff;
    color: #4338ca;
}

.btn-view:hover {
    background: #c7d2fe;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Filter Section */
.filter-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    padding: 24px;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
    margin: 0;
}

/* Filter section içindeki card için padding'i kaldır */
.card .filter-section {
    padding: 24px;
    margin: 0;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-group label {
    font-weight: 600;
    color: #1e3a8a;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

/* Single Select Dropdown */
.single-select-container {
    position: relative;
    width: 100%;
}

.single-select-input {
    display: flex;
    align-items: center;
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    background: white;
    cursor: pointer;
    min-height: 42px;
    transition: all 0.2s ease;
}

.single-select-input:hover {
    border-color: #3b82f6;
}

.single-select-input.active {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.single-select-input input {
    border: none;
    outline: none;
    flex: 1;
    background: transparent;
    cursor: pointer;
    font-size: 14px;
    color: #2c3e50;
}

.dropdown-arrow {
    transition: transform 0.2s;
    color: #6b7280;
    font-size: 12px;
}

.single-select-input.active .dropdown-arrow {
    transform: rotate(180deg);
}

/* Increased z-index to 9999 to ensure dropdown appears above all elements */
.single-select-dropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #3b82f6;
    border-top: none;
    border-radius: 0 0 8px 8px;
    max-height: 240px;
    overflow-y: auto;
    z-index: 9999;
    display: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    margin-top: -2px;
}

.single-select-dropdown.show {
    display: block;
}

.single-select-option {
    padding: 10px 14px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    transition: background 0.15s ease;
}

.single-select-option:hover {
    background: #f8fafc;
}

.single-select-option.selected {
    background: #3b82f6;
    color: white;
    font-weight: 500;
}

.single-select-option:last-child {
    border-bottom: none;
}

/* Date Input */
.filter-group input[type="date"] {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    transition: all 0.2s ease;
    background: white;
}

.filter-group input[type="date"]:hover {
    border-color: #3b82f6;
}

.filter-group input[type="date"]:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Dis Tedarik Siparişleri</h2>
            <button class="btn btn-primary" onclick="window.location.href='DisTedarikSO.php'">+ Yeni Talep Oluştur</button>
        </header>

        <div class="content-wrapper">
            <?php
            // Başarı/Hata mesajlarını göster
            $successMsg = '';
            $errorMsg = '';
            
            if (isset($_GET['msg'])) {
                if ($_GET['msg'] === 'teslim_alindi') {
                    $successMsg = '✅ Teslim alma işlemi başarıyla tamamlandı!';
                    if (isset($_GET['status_warning']) && $_GET['status_warning'] == '1') {
                        $errorMsg = '⚠️ Teslim alma başarılı ama durum güncellenemedi, lütfen manuel kontrol edin.';
                        if (isset($_GET['error']) && !empty($_GET['error'])) {
                            $errorMsg .= '<br><small style="color: #6b7280;">' . htmlspecialchars(urldecode($_GET['error'])) . '</small>';
                        }
                    }
                } elseif ($_GET['msg'] === 'ok') {
                    $successMsg = '✅ İşlem başarıyla tamamlandı!';
                }
            }
            ?>
            
        <?php if (!empty($successMsg)): ?>
            <div class="card" style="background: #dcfce7; border: 2px solid #16a34a; margin-bottom: 1.5rem;">
                <p style="color: #166534; font-weight: 600; margin: 0;"><?= htmlspecialchars($successMsg) ?></p>
                <?php if (!empty($pdnInfo)): ?>
                    <?= $pdnInfo ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
            
         
            
            
            
            <!-- ✅ Filtre Bölümü -->
            <section class="card">
                <div class="filter-section">
                    <div class="filter-group">
                        <label>SİPARİŞ DURUMU</label>
                        <div class="single-select-container">
                            <div class="single-select-input" onclick="toggleDropdown('status')">
                                <input type="text" id="filterStatus" value="<?= $filterStatus ? getStatusText($filterStatus) : 'Tümü' ?>" placeholder="Seçiniz..." readonly>
                                <span class="dropdown-arrow">▼</span>
                            </div>
                            <div class="single-select-dropdown" id="statusDropdown">
                                <div class="single-select-option <?= empty($filterStatus) ? 'selected' : '' ?>" data-value="" onclick="selectStatus('')">Tümü</div>
                                <div class="single-select-option <?= $filterStatus === '1' ? 'selected' : '' ?>" data-value="1" onclick="selectStatus('1')">Onay bekleniyor</div>
                                <div class="single-select-option <?= $filterStatus === '2' ? 'selected' : '' ?>" data-value="2" onclick="selectStatus('2')">Hazırlanıyor</div>
                                <div class="single-select-option <?= $filterStatus === '3' ? 'selected' : '' ?>" data-value="3" onclick="selectStatus('3')">Sevk edildi</div>
                                <div class="single-select-option <?= $filterStatus === '4' ? 'selected' : '' ?>" data-value="4" onclick="selectStatus('4')">Tamamlandı</div>
                                <div class="single-select-option <?= $filterStatus === '5' ? 'selected' : '' ?>" data-value="5" onclick="selectStatus('5')">İptal edildi</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="filter-group">
                        <label>BAŞLANGIÇ TARİHİ</label>
                        <input type="date" id="start-date" value="<?= htmlspecialchars($filterStartDate) ?>" onblur="applyFilters()">
                    </div>
                    
                    <div class="filter-group">
                        <label>BİTİŞ TARİHİ</label>
                        <input type="date" id="end-date" value="<?= htmlspecialchars($filterEndDate) ?>" onblur="applyFilters()">
                    </div>
                </div>
            </section>
            
            <section class="card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Talep No</th>
                            <th>Sipariş No</th>
                            <th>Talep Tarihi</th>
                            <th>Sipariş Tarihi</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($allRows)): ?>
                            <?php foreach ($allRows as $row):
                                $status = $row['U_ASB2B_STATUS'] ?? null;
                                // Status null ise "Bilinmiyor" göster
                                if ($status === null) {
                                    $statusText = 'Bilinmiyor';
                                    $statusClass = 'status-unknown';
                                } else {
                                    $statusText = getStatusText($status);
                                    $statusClass = getStatusClass($status);
                                }
                                $requestNo = $row['RequestNo'] ?? '';
                                $orderNo = $row['U_ASB2B_ORNO'] ?? null;
                                
                                // ✅ Talep Tarihi: View'den gelen DocDate kullanılıyor (performans için)
                                // RequriedDate için ayrı API çağrısı yapılmıyor
                                $requestDate = $row['DocDate'] ?? $row['RequriedDate'] ?? $row['RequiredDate'] ?? $row['RequestDate'] ?? '';
                                $docDate = !empty($requestDate) ? formatDate($requestDate) : '-';
                                
                                // ✅ Sipariş Tarihi: PurchaseOrder.DocDate (U_ASB2B_ORDT) - boş olabilir
                                $orderDateValue = $row['U_ASB2B_ORDT'] ?? null;
                                $orderDate = (!empty($orderDateValue) && $orderDateValue !== null && $orderDateValue !== '') ? formatDate($orderDateValue) : '-';
                                
                                // Spec'e göre: Teslim Al aktif olması için:
                                // - Status = 2 (Hazırlanıyor) veya 3 (Sevk edildi) OLMALI
                                // - VE OrderNo dolu OLMALI
                                $canReceive = false;
                                if (!empty($orderNo) && $orderNo !== null && $orderNo !== '' && $orderNo !== '-') {
                                    if ($status === '2' || $status === '3' || $status === 2 || $status === 3) {
                                        $canReceive = true;
                                    }
                                }
                                
                                // Tarih filtresi için (client-side filtreleme gerekirse)
                                $requestDateForFilter = '';
                                if (!empty($requestDate)) {
                                    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $requestDate)) {
                                        $requestDateForFilter = substr($requestDate, 0, 10);
                                    } else {
                                        $requestDateForFilter = date('Y-m-d', strtotime($requestDate));
                                    }
                                }
                                
                                // Tarih filtresi uygula (eğer view'de filtreleme yapılmadıysa)
                                $showRow = true;
                                if (!empty($filterStartDate) || !empty($filterEndDate)) {
                                    if (!empty($requestDateForFilter)) {
                                        $requestDateObj = new DateTime($requestDateForFilter);
                                        if (!empty($filterStartDate)) {
                                            $startDateObj = new DateTime($filterStartDate);
                                            if ($requestDateObj < $startDateObj) {
                                                $showRow = false;
                                            }
                                        }
                                        if ($showRow && !empty($filterEndDate)) {
                                            $endDateObj = new DateTime($filterEndDate);
                                            $endDateObj->setTime(23, 59, 59);
                                            if ($requestDateObj > $endDateObj) {
                                                $showRow = false;
                                            }
                                        }
                                    } else {
                                        // Tarih yoksa ve filtre varsa gizle
                                        $showRow = false;
                                    }
                                }
                            ?>
                                <?php if ($showRow): ?>
                                <tr>
                                    <td><?= htmlspecialchars($requestNo) ?></td>
                                    <td><?= $orderNo ? htmlspecialchars($orderNo) : '-' ?></td>
                                    <td><?= $docDate ?></td>
                                    <td><?= $orderDate !== '-' ? $orderDate : '-' ?></td>
                                    <td><span class="status-badge <?= $statusClass ?>"><?= $statusText ?></span></td>
                                    <td>
                                        <a href="DisTedarik-Detay.php?requestNo=<?= urlencode($requestNo) ?><?= $orderNo ? '&orderNo=' . urlencode($orderNo) : '' ?><?= $status !== null ? '&status=' . urlencode($status) : '' ?>">
                                            <button class="btn btn-view">👁️ Detay</button>
                                        </a>
                                        <?php if ($canReceive): ?>
                                            <a href="DisTedarik-TeslimAl.php?requestNo=<?= urlencode($requestNo) ?><?= $orderNo ? '&orderNo=' . urlencode($orderNo) : '' ?>">
                                                <button class="btn btn-primary">✓ Teslim Al</button>
                                            </a>
                                        <?php else: ?>
                                            <button class="btn btn-primary" disabled>✓ Teslim Al</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center;padding:2rem;color:#9ca3af;">Kayıt bulunamadı.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </section>
        </div>
    </main>
    
    <script>
    let selectedStatus = '<?= htmlspecialchars($filterStatus) ?>';

    function toggleDropdown(type) {
        const dropdown = document.getElementById(type + 'Dropdown');
        const input = document.querySelector(`#filter${type.charAt(0).toUpperCase() + type.slice(1)}`).parentElement;
        const isOpen = dropdown.classList.contains('show');
        
        // Close all dropdowns
        document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
        document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
        
        if (!isOpen) {
            dropdown.classList.add('show');
            input.classList.add('active');
        }
    }

    function selectStatus(value) {
        selectedStatus = value;
        const statusText = document.querySelector(`#statusDropdown .single-select-option[data-value="${value}"]`).textContent;
        document.getElementById('filterStatus').value = statusText;
        document.querySelectorAll('#statusDropdown .single-select-option').forEach(opt => opt.classList.remove('selected'));
        document.querySelector(`#statusDropdown .single-select-option[data-value="${value}"]`).classList.add('selected');
        applyFilters();
    }

    function applyFilters() {
        // Tarih input'larından önce değerleri al (input focus'ta olabilir)
        const status = selectedStatus;
        const startDateInput = document.getElementById('start-date');
        const endDateInput = document.getElementById('end-date');
        const startDate = startDateInput ? startDateInput.value : '';
        const endDate = endDateInput ? endDateInput.value : '';
        
        const params = new URLSearchParams();
        if (status) params.append('status', status);
        if (startDate) params.append('start_date', startDate);
        if (endDate) params.append('end_date', endDate);
        
        // Mevcut URL parametrelerini koru (msg gibi)
        const currentParams = new URLSearchParams(window.location.search);
        if (currentParams.has('msg')) {
            params.append('msg', currentParams.get('msg'));
        }
        if (currentParams.has('status_warning')) {
            params.append('status_warning', currentParams.get('status_warning'));
        }
        if (currentParams.has('error')) {
            params.append('error', currentParams.get('error'));
        }
        if (currentParams.has('pdn_docentry')) {
            params.append('pdn_docentry', currentParams.get('pdn_docentry'));
        }
        if (currentParams.has('pdn_docnum')) {
            params.append('pdn_docnum', currentParams.get('pdn_docnum'));
        }
        
        window.location.href = 'DisTedarik.php' + (params.toString() ? '?' + params.toString() : '');
    }

    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.single-select-container')) {
            document.querySelectorAll('.single-select-dropdown').forEach(d => d.classList.remove('show'));
            document.querySelectorAll('.single-select-input').forEach(d => d.classList.remove('active'));
        }
    });
    </script>
</body>
</html>