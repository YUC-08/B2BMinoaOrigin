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
$branch = $_SESSION["WhsCode"] ?? $_SESSION["Branch2"]["Name"] ?? '';

if (empty($uAsOwnr) || empty($branch)) {
    die("Session bilgileri eksik. Lütfen tekrar giriş yapın.");
}

// 1. Minoa talep ettiği transfer tedarik (diğer şubeden gelen) - ToWarehouse
$toWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_MAIN eq '2' and U_ASB2B_BRAN eq '{$branch}'";
$toWarehouseQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($toWarehouseFilter);
$toWarehouseData = $sap->get($toWarehouseQuery);
$toWarehouses = $toWarehouseData['response']['value'] ?? [];
$toWarehouse = !empty($toWarehouses) ? $toWarehouses[0]['WarehouseCode'] : null;

// 2. Minoa talep edilen transfer tedarik (diğer şubeye giden) - FromWarehouse
$fromWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_MAIN eq '1' and U_ASB2B_BRAN eq '{$branch}'";
$fromWarehouseQuery = "Warehouses?\$select=WarehouseCode&\$filter=" . urlencode($fromWarehouseFilter);
$fromWarehouseData = $sap->get($fromWarehouseQuery);
$fromWarehouses = $fromWarehouseData['response']['value'] ?? [];
$fromWarehouse = !empty($fromWarehouses) ? $fromWarehouses[0]['WarehouseCode'] : null;

// Görünüm tipi (incoming veya outgoing)
$viewType = $_GET['view'] ?? 'incoming';

// Filtreler
$filterStatus = $_GET['status'] ?? '';
$filterStartDate = $_GET['start_date'] ?? '';
$filterEndDate = $_GET['end_date'] ?? '';

// Status mapping
function getStatusText($status) {
    $statusMap = [
        '0' => 'Onay Bekliyor',
        '1' => 'Onay Bekliyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk Edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal Edildi'
    ];
    return $statusMap[$status] ?? 'Bilinmeyen';
}

function getStatusClass($status) {
    $statusMap = [
        '0' => 'status-pending',
        '1' => 'status-pending',
        '2' => 'status-processing',
        '3' => 'status-shipped',
        '4' => 'status-completed',
        '5' => 'status-cancelled'
    ];
    return $statusMap[$status] ?? 'status-unknown';
}

function isReceivableStatus($status) {
    $s = trim((string)$status);
    return in_array($s, ['2', '3'], true);
}

function isApprovalStatus($status) {
    $s = trim((string)$status);
    return in_array($s, ['0', '1'], true);
}

// 1. Gelen transferler (ToWarehouse = '100-KT-1')
$incomingTransfers = [];
if ($toWarehouse) {
    $incomingFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_TYPE eq 'TRANSFER' and ToWarehouse eq '{$toWarehouse}'";
    
    if (!empty($filterStatus)) {
        $incomingFilter .= " and U_ASB2B_STATUS eq '{$filterStatus}'";
    }
    if (!empty($filterStartDate)) {
        $startDateFormatted = date('Y-m-d', strtotime($filterStartDate));
        $incomingFilter .= " and DocDate ge '{$startDateFormatted}'";
    }
    if (!empty($filterEndDate)) {
        $endDateFormatted = date('Y-m-d', strtotime($filterEndDate));
        $incomingFilter .= " and DocDate le '{$endDateFormatted}'";
    }
    
    $selectValue = "DocEntry,DocDate,DueDate,U_ASB2B_NumAtCard,U_ASB2B_STATUS";
    $filterEncoded = urlencode($incomingFilter);
    $orderByEncoded = urlencode("DocEntry desc");
    $incomingQuery = "InventoryTransferRequests?\$select=" . urlencode($selectValue) . "&\$filter=" . $filterEncoded . "&\$orderby=" . $orderByEncoded . "&\$top=25";
    
    $incomingData = $sap->get($incomingQuery);
    $incomingTransfers = $incomingData['response']['value'] ?? [];
}

// 2. Giden transferler (FromWarehouse = '100-KT-0')
$outgoingTransfers = [];
if ($fromWarehouse) {
    $outgoingFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_TYPE eq 'TRANSFER' and FromWarehouse eq '{$fromWarehouse}'";
    
    if (!empty($filterStatus)) {
        $outgoingFilter .= " and U_ASB2B_STATUS eq '{$filterStatus}'";
    }
    if (!empty($filterStartDate)) {
        $startDateFormatted = date('Y-m-d', strtotime($filterStartDate));
        $outgoingFilter .= " and DocDate ge '{$startDateFormatted}'";
    }
    if (!empty($filterEndDate)) {
        $endDateFormatted = date('Y-m-d', strtotime($filterEndDate));
        $outgoingFilter .= " and DocDate le '{$endDateFormatted}'";
    }
    
    $selectValue = "DocEntry,DocDate,DueDate,U_ASB2B_NumAtCard,U_ASB2B_STATUS";
    $filterEncoded = urlencode($outgoingFilter);
    $orderByEncoded = urlencode("DocEntry desc");
    $outgoingQuery = "InventoryTransferRequests?\$select=" . urlencode($selectValue) . "&\$filter=" . $filterEncoded . "&\$orderby=" . $orderByEncoded . "&\$top=25";
    
    $outgoingData = $sap->get($outgoingQuery);
    $outgoingTransfers = $outgoingData['response']['value'] ?? [];
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
    <title>Transferler - CREMMAVERSE</title>
    <link rel="stylesheet" href="navbar.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        :root {
            --primary: #1e3a8a;
            --primary-light: #eef2ff;
            --accent: #2563eb;
            --muted: #6b7280;
            --border: #e5e7eb;
            --bg: #f5f7fa;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg);
            color: #1f2937;
        }

        .app-container {
            display: flex;
            min-height: 100vh;
            background: var(--bg);
        }

        .main-content {
            width: 100%;
            padding: 0 32px 48px 32px;
            margin-left: 70px;
        }

        .sidebar.expanded ~ .main-content {
            margin-left: 240px;
        }

        .content-wrapper {
            max-width: 1400px;
            margin: 0 auto;
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

        .btn {
            border: none;
            border-radius: 10px;
            padding: 12px 18px;
            font-weight: 600;
            cursor: pointer;
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 12px 20px rgba(37, 99, 235, 0.15);
        }

        .btn-primary {
            background: var(--accent);
            color: #fff;
        }

        .btn-view {
            background: #3b82f6;
            color: white;
            padding: 6px 12px;
            font-size: 0.875rem;
        }

        .btn-receive {
            background: #10b981;
            color: white;
            padding: 6px 12px;
            font-size: 0.875rem;
        }

        .btn-approve {
            background: #f59e0b;
            color: white;
            padding: 6px 12px;
            font-size: 0.875rem;
        }

        .card {
            margin-top: 24px;
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 25px 50px rgba(15, 23, 42, 0.08);
        }

        .filter-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            padding: 24px 32px;
            border-bottom: 1px solid var(--border);
        }

        .filter-group label {
            font-size: .8rem;
            font-weight: 700;
            color: var(--muted);
            text-transform: uppercase;
            margin-bottom: 6px;
            letter-spacing: .05em;
            display: block;
        }

        .filter-input,
        .filter-select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--primary-light);
            font-size: 0.95rem;
            transition: border-color .2s ease, box-shadow .2s ease;
        }

        .filter-input:focus,
        .filter-select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
            color: white;
        }

        .data-table th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .data-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }

        .data-table tbody tr:hover {
            background: #f8fafc;
        }

        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
            text-transform: uppercase;
            letter-spacing: 0.3px;
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
            background: #bfdbfe;
            color: #1e3a8a;
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

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1e40af;
            margin-bottom: 1rem;
            padding: 0 32px;
            padding-top: 24px;
        }

        .table-actions {
            display: flex;
            gap: 8px;
            align-items: center;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <div class="content-wrapper">
            <header class="page-header">
                <h2>Transferler</h2>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <?php if ($viewType === 'incoming'): ?>
                        <button class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=outgoing<?= !empty($filterStatus) ? '&status=' . urlencode($filterStatus) : '' ?><?= !empty($filterStartDate) ? '&start_date=' . urlencode($filterStartDate) : '' ?><?= !empty($filterEndDate) ? '&end_date=' . urlencode($filterEndDate) : '' ?>'">
                            📤 Giden Transferler
                        </button>
                    <?php else: ?>
                        <button class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=incoming<?= !empty($filterStatus) ? '&status=' . urlencode($filterStatus) : '' ?><?= !empty($filterStartDate) ? '&start_date=' . urlencode($filterStartDate) : '' ?><?= !empty($filterEndDate) ? '&end_date=' . urlencode($filterEndDate) : '' ?>'">
                            📥 Gelen Transferler
                        </button>
                    <?php endif; ?>
                    <button class="btn btn-primary" onclick="window.location.href='TransferlerSO.php'">+ Yeni Transfer Oluştur</button>
                </div>
            </header>

            <section class="card">
                <div class="filter-section">
                    <div class="filter-group">
                        <label>Sipariş Durumu</label>
                        <select class="filter-select" id="filterStatus" onchange="applyFilters()">
                            <option value="">Tümü</option>
                            <option value="0" <?= $filterStatus === '0' ? 'selected' : '' ?>>Onay Bekliyor</option>
                            <option value="2" <?= $filterStatus === '2' ? 'selected' : '' ?>>Hazırlanıyor</option>
                            <option value="3" <?= $filterStatus === '3' ? 'selected' : '' ?>>Sevk Edildi</option>
                            <option value="4" <?= $filterStatus === '4' ? 'selected' : '' ?>>Tamamlandı</option>
                            <option value="5" <?= $filterStatus === '5' ? 'selected' : '' ?>>İptal Edildi</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Başlangıç Tarihi</label>
                        <input type="date" class="filter-input" id="start-date" value="<?= htmlspecialchars($filterStartDate) ?>" onchange="applyFilters()">
                    </div>
                    
                    <div class="filter-group">
                        <label>Bitiş Tarihi</label>
                        <input type="date" class="filter-input" id="end-date" value="<?= htmlspecialchars($filterEndDate) ?>" onchange="applyFilters()">
                    </div>
                </div>

                <?php if ($viewType === 'incoming'): ?>
                <!-- Gelen Transferler (Diğer şubeden gelen) -->
                <div class="section-title">📥 Gelen Transferler (Diğer Şubeden)</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Transfer No</th>
                            <th>Talep Tarihi</th>
                            <th>Vade Tarihi</th>
                            <th>Teslimat Belge No</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($incomingTransfers)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    Gelen transfer bulunamadı.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($incomingTransfers as $transfer): 
                                $status = $transfer['U_ASB2B_STATUS'] ?? '0';
                                $statusText = getStatusText($status);
                                $statusClass = getStatusClass($status);
                                $canReceive = isReceivableStatus($status);
                            ?>
                                <tr>
                                    <td style="font-weight: 600; color: #1e40af;"><?= htmlspecialchars($transfer['DocEntry'] ?? '-') ?></td>
                                    <td><?= formatDate($transfer['DocDate'] ?? '') ?></td>
                                    <td><?= formatDate($transfer['DueDate'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($transfer['U_ASB2B_NumAtCard'] ?? '-') ?></td>
                                    <td>
                                        <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="Transferler-Detay.php?docEntry=<?= urlencode($transfer['DocEntry']) ?>&type=incoming">
                                                <button class="btn btn-view">👁️ Detay</button>
                                            </a>
                                            <?php if ($canReceive): ?>
                                                <a href="Transferler-TeslimAl.php?docEntry=<?= urlencode($transfer['DocEntry']) ?>">
                                                    <button class="btn btn-receive">✓ Teslim Al</button>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <!-- Giden Transferler (Diğer şubeye giden) -->
                <div class="section-title">📤 Giden Transferler (Diğer Şubeye)</div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Transfer No</th>
                            <th>Talep Tarihi</th>
                            <th>Vade Tarihi</th>
                            <th>Teslimat Belge No</th>
                            <th>Durum</th>
                            <th>İşlemler</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($outgoingTransfers)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    Giden transfer bulunamadı.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($outgoingTransfers as $transfer): 
                                $status = $transfer['U_ASB2B_STATUS'] ?? '0';
                                $statusText = getStatusText($status);
                                $statusClass = getStatusClass($status);
                                $canReceive = isReceivableStatus($status);
                                $canApprove = isApprovalStatus($status);
                            ?>
                                <tr>
                                    <td style="font-weight: 600; color: #1e40af;"><?= htmlspecialchars($transfer['DocEntry'] ?? '-') ?></td>
                                    <td><?= formatDate($transfer['DocDate'] ?? '') ?></td>
                                    <td><?= formatDate($transfer['DueDate'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($transfer['U_ASB2B_NumAtCard'] ?? '-') ?></td>
                                    <td>
                                        <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="Transferler-Detay.php?docEntry=<?= urlencode($transfer['DocEntry']) ?>&type=outgoing">
                                                <button class="btn btn-view">👁️ Detay</button>
                                            </a>
                                            <?php if ($canReceive): ?>
                                                <a href="Transferler-TeslimAl.php?docEntry=<?= urlencode($transfer['DocEntry']) ?>">
                                                    <button class="btn btn-receive">✓ Teslim Al</button>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($canApprove): ?>
                                                <a href="Transferler-Onayla.php?docEntry=<?= urlencode($transfer['DocEntry']) ?>">
                                                    <button class="btn btn-approve">✓ Onayla</button>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
        function applyFilters() {
            const status = document.getElementById('filterStatus').value;
            const startDate = document.getElementById('start-date').value;
            const endDate = document.getElementById('end-date').value;
            const viewType = '<?= $viewType ?>';
            
            const params = new URLSearchParams();
            params.append('view', viewType);
            if (status) params.append('status', status);
            if (startDate) params.append('start_date', startDate);
            if (endDate) params.append('end_date', endDate);
            
            window.location.href = 'Transferler.php?' + params.toString();
        }
    </script>
</body>
</html>
