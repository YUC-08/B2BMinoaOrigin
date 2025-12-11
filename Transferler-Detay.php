<?php
session_start();
if (!isset($_SESSION["UserName"]) || !isset($_SESSION["sapSession"])) {
    header("Location: config/login.php");
    exit;
}
include 'sap_connect.php';
$sap = new SAPConnect();

// URL'den parametreler
$docEntry = $_GET['docEntry'] ?? '';
$type = $_GET['type'] ?? 'incoming'; // incoming veya outgoing

if (empty($docEntry)) {
    header("Location: Transferler.php");
    exit;
}

// Debug dizisi
$debug = [];

// ====================================================================================
// 1. ADIM: BELGE HEADER BİLGİLERİ
// ====================================================================================
// DÜZELTME: Filter parametresi urlencode edildi.
$filterStr = "DocEntry eq {$docEntry}";
$headerQuery = "view.svc/ASB2B_TransferRequestList_B1SLQuery?\$filter=" . urlencode($filterStr) . "&\$top=1";

$headerData = $sap->get($headerQuery);
$headerRows = $headerData['response']['value'] ?? [];
$headerInfo = !empty($headerRows) ? $headerRows[0] : null;

// Debug bilgisi ekle
$debug['HeaderQuery'] = $headerQuery;
$debug['HeaderStatus'] = $headerData['status'] ?? 'N/A';
$debug['HeaderCount'] = count($headerRows);
$debug['HeaderRaw'] = $headerRows;

if (!$headerInfo) {
    // Veri gelmezse varsayılan boş değerler
    $headerInfo = [
        'DocEntry'        => $docEntry,
        'DocDate'         => null,
        'DocDueDate'      => null,
        'U_ASB2B_STATUS'  => '0',
        'U_ASB2B_NumAtCard' => null,
        'Comments'        => null,
        'FromWhsCode'     => '',
        'WhsCode'         => '',
    ];
}

// ====================================================================================
// 2. ADIM: SATIR LİSTESİ
// ====================================================================================
// DÜZELTME: Filter parametresi urlencode edildi.
$linesQuery = "view.svc/ASB2B_TransferRequestList_B1SLQuery?\$filter=" . urlencode($filterStr);
$linesData = $sap->get($linesQuery);
$lines = $linesData['response']['value'] ?? []; 

$debug['LinesQuery'] = $linesQuery;
$debug['LinesStatus'] = $linesData['status'] ?? 'N/A';
$debug['LinesCount'] = count($lines);

// ====================================================================================
// YARDIMCI FONKSİYONLAR
// ====================================================================================
function formatQuantity($qty) {
    $num = floatval($qty);
    if ($num == 0) return '0';
    if ($num == floor($num)) {
        return (string)intval($num);
    }
    return str_replace('.', ',', rtrim(rtrim(sprintf('%.2f', $num), '0'), ','));
}

function getStatusText($status) {
    $statusMap = [
        '0' => 'Onay Bekliyor',
        '1' => 'Onay Bekliyor',
        '2' => 'Hazırlanıyor',
        '3' => 'Sevk Edildi',
        '4' => 'Tamamlandı',
        '5' => 'İptal Edildi'
    ];
    return $statusMap[$status] ?? 'Bilinmiyor';
}

function getStatusClass($status) {
    $classMap = [
        '0' => 'status-pending',
        '1' => 'status-pending',
        '2' => 'status-processing',
        '3' => 'status-shipped',
        '4' => 'status-completed',
        '5' => 'status-cancelled'
    ];
    return $classMap[$status] ?? 'status-unknown';
}

function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

function isReceivableStatus($status) {
    $s = trim((string)$status);
    return in_array($s, ['2', '3'], true);
}

function isApprovalStatus($status) {
    $s = trim((string)$status);
    return in_array($s, ['0', '1'], true);
}

// ====================================================================================
// HEADER BİLGİLERİNİ İŞLE
// ====================================================================================
$docDate = formatDate($headerInfo['DocDate'] ?? null);
$dueDate = formatDate($headerInfo['DocDueDate'] ?? null);
$status = (string)($headerInfo['U_ASB2B_STATUS'] ?? '0');
if ($status === '' || $status === 'null') { $status = '0'; }

$statusText = getStatusText($status);
$statusClass = getStatusClass($status);

$numAtCard = $headerInfo['U_ASB2B_NumAtCard'] ?? '-';
$comments = $headerInfo['Comments'] ?? '-';

// Depo Bilgileri
$fromWarehouse = $headerInfo['FromWhsCode'] ?? '';
$toWarehouse = $headerInfo['WhsCode'] ?? '';
$fromWarehouseName = $headerInfo['FromWhsName'] ?? ''; // JSON'da yoksa boş gelir
$toWarehouseName = $headerInfo['ToWhsName'] ?? '';     // JSON'da yoksa boş gelir

// Kaynak Depo Gösterimi
$gonderSubeDisplay = $fromWarehouse;
if (!empty($fromWarehouseName)) {
    $gonderSubeDisplay .= ' / ' . $fromWarehouseName;
}
if (empty($gonderSubeDisplay)) $gonderSubeDisplay = '-';

// Hedef Depo Gösterimi
$aliciSubeDisplay = $toWarehouse;
if (!empty($toWarehouseName)) {
    $aliciSubeDisplay .= ' / ' . $toWarehouseName;
}
if (empty($aliciSubeDisplay)) $aliciSubeDisplay = '-';


$canReceive = isReceivableStatus($status);
$canApprove = isApprovalStatus($status);
$showNewRequestButton = ($type === 'incoming');
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transfer Detay - MINOA</title>
    <style>
/* CSS AYNEN KORUNDU */
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background: #f5f7fa; color: #2c3e50; line-height: 1.6; }
.main-content { width: 100%; background: whitesmoke; padding: 0; min-height: 100vh; }
.page-header { background: white; padding: 20px 2rem; border-radius: 0 0 0 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; margin: 0; position: sticky; top: 0; z-index: 100; height: 80px; box-sizing: border-box; }
.page-header h2 { color: #1e40af; font-size: 1.75rem; font-weight: 600; margin: 0; }
.content-wrapper { padding: 24px 32px; max-width: 1400px; margin: 0 auto; }
.card { background: white; border-radius: 12px; padding: 1.5rem; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 24px; }
.detail-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 2px solid #e5e7eb; }
.detail-title h3 { font-size: 1.5rem; color: #2c3e50; font-weight: 400; }
.detail-title h3 strong { font-weight: 600; color: #3b82f6; }
.detail-card { background: white; border-radius: 12px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); padding: 24px; margin-bottom: 24px; }
.detail-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 2rem; }
.detail-column { display: flex; flex-direction: column; gap: 1.5rem; }
.detail-item { display: flex; flex-direction: column; gap: 0.5rem; }
.detail-item label { font-size: 13px; color: #1e3a8a; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.detail-value { font-size: 15px; color: #2c3e50; font-weight: 500; }
.section-title { font-size: 18px; font-weight: 600; color: #1e3a8a; margin-bottom: 1rem; padding-bottom: 0.5rem; border-bottom: 2px solid #e5e7eb; }
.data-table { width: 100%; border-collapse: collapse; font-size: 14px; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08); table-layout: fixed; }
.data-table thead { background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%); }
.data-table th { padding: 16px 20px; text-align: left; font-weight: 600; font-size: 13px; color: #1e3a8a; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #e5e7eb; width: 25%; }
.data-table th:nth-child(3), .data-table th:nth-child(4), .data-table th:nth-child(5) { text-align: center; }
.data-table td { padding: 16px 20px; border-bottom: 1px solid #f3f4f6; font-size: 14px; color: #374151; width: 20%; }
.data-table td:nth-child(3), .data-table td:nth-child(4), .data-table td:nth-child(5) { text-align: center; }
.data-table tbody tr { transition: background 0.15s ease; }
.data-table tbody tr:hover { background: #f8fafc; }
.status-badge { padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-block; text-transform: uppercase; letter-spacing: 0.3px; }
.status-pending { background: #fef3c7; color: #92400e; }
.status-processing { background: #dbeafe; color: #1e40af; }
.status-shipped { background: #e0e7ff; color: #4338ca; }
.status-completed { background: #d1fae5; color: #065f46; }
.status-cancelled { background: #fee2e2; color: #991b1b; }
.status-unknown { background: #f3f4f6; color: #6b7280; }
.btn { padding: 10px 20px; border: none; border-radius: 8px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
.btn-primary { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3); }
.btn-primary:hover { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4); transform: translateY(-1px); }
.btn-secondary { background: white; color: #3b82f6; border: 2px solid #3b82f6; }
.btn-secondary:hover { background: #f0f9ff; }
.btn-receive { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3); }
.btn-receive:hover { background: linear-gradient(135deg, #059669 0%, #047857 100%); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4); transform: translateY(-1px); }
.btn-approve { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3); }
.btn-approve:hover { background: linear-gradient(135deg, #d97706 0%, #b45309 100%); box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4); transform: translateY(-1px); }
.header-actions { display: flex; gap: 0.5rem; align-items: center; }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>

    <main class="main-content">
        <header class="page-header">
            <h2>Transfer Detay</h2>
            <div class="header-actions">
                <?php if ($showNewRequestButton): ?>
                    <a href="TransferlerSO.php">
                        <button class="btn btn-primary">+ Yeni Talep Oluştur</button>
                    </a>
                <?php endif; ?>
                <?php if ($canReceive && $type === 'incoming'): ?>
                    <a href="Transferler-TeslimAl.php?docEntry=<?= urlencode($docEntry) ?>">
                        <button class="btn btn-primary">✓ Teslim Al</button>
                    </a>
                <?php endif; ?>
                <?php if ($canApprove && $type === 'outgoing'): ?>
                    <a href="Transferler-Onayla.php?docEntry=<?= urlencode($docEntry) ?>&action=approve">
                        <button class="btn btn-approve">✓ Onayla</button>
                    </a>
                    <a href="Transferler-Onayla.php?docEntry=<?= urlencode($docEntry) ?>&action=reject">
                        <button class="btn" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">✗ İptal</button>
                    </a>
                <?php endif; ?>
                <button class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=<?= $type ?>'">← Geri Dön</button>
            </div>
        </header>

        <div class="content-wrapper">
            
            <?php if (true): // Geliştirme aşamasında true, sonra false yapabilirsiniz ?>
            <details style="margin-bottom: 20px; border: 1px solid #f59e0b; background: #fffbeb; padding: 10px; border-radius: 8px;">
                <summary style="cursor: pointer; color: #b45309; font-weight: bold;">🛠 Debug Bilgisi (Tıkla)</summary>
                <pre style="white-space: pre-wrap; font-size: 12px; margin-top: 10px; color: #333;"><?php print_r($debug); ?></pre>
            </details>
            <?php endif; ?>

            <div class="detail-header">
                <div class="detail-title">
                    <h3>Transfer Talebi: <strong><?= htmlspecialchars($docEntry) ?></strong></h3>
                </div>
            </div>

            <div class="detail-card">
                <div class="detail-grid">
                    <div class="detail-column">
                        <div class="detail-item">
                            <label>Transfer No:</label>
                            <div class="detail-value"><?= htmlspecialchars($docEntry) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Talep Tarihi:</label>
                            <div class="detail-value"><?= htmlspecialchars($docDate) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Vade Tarihi:</label>
                            <div class="detail-value"><?= htmlspecialchars($dueDate) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Teslimat Belge No:</label>
                            <div class="detail-value"><?= htmlspecialchars($numAtCard) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Not:</label>
                            <div class="detail-value"><?= !empty($comments) && $comments !== '-' ? htmlspecialchars($comments) : 'Transfer nakil talebi' ?></div>
                        </div>
                    </div>
                    
                    <div class="detail-column">
                        <div class="detail-item">
                            <label>Kaynak Depo:</label>
                            <div class="detail-value"><?= htmlspecialchars($gonderSubeDisplay) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Hedef Depo:</label>
                            <div class="detail-value"><?= htmlspecialchars($aliciSubeDisplay) ?></div>
                        </div>
                        <div class="detail-item">
                            <label>Durum:</label>
                            <div class="detail-value">
                                <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="section-title">Transfer Detayı</div>
            <div class="detail-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Kalem Numarası</th>
                            <th>Kalem Tanımı</th>
                            <th>Talep Miktarı</th>
                            <th>Sevk Miktarı</th>
                            <th>Teslimat Miktarı</th>
                            <th>Satır Durumu</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lines)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    Transfer satırı bulunamadı.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($lines as $line): 
                                $itemCode = htmlspecialchars($line['ItemCode'] ?? '-');
                                $itemName = htmlspecialchars($line['Dscription'] ?? '-');
                                $uomCode = htmlspecialchars($line['UoMCode'] ?? 'AD');
                                
                                $talepMiktar = (float)($line['Quantity'] ?? 0);
                                $sevkMiktar = (float)($line['ShippedQty'] ?? 0);
                                $teslimMiktar = (float)($line['DeliveredQty'] ?? 0);
                                
                                $lineStatus = (string)($line['U_ASB2B_STATUS'] ?? $status ?? '0');
                                if ($lineStatus === '' || $lineStatus === 'null') {
                                    $lineStatus = '0';
                                }
                                $lineStatusText = getStatusText($lineStatus);
                                $lineStatusClass = getStatusClass($lineStatus);
                            ?>
                                <tr>
                                    <td><?= $itemCode ?></td>
                                    <td><?= $itemName ?></td>
                                    <td style="text-align: center;"><?= formatQuantity($talepMiktar) ?> <?= $uomCode ?></td>
                                    <td style="text-align: center;"><?= formatQuantity($sevkMiktar) ?> <?= $uomCode ?></td>
                                    <td style="text-align: center;"><?= formatQuantity($teslimMiktar) ?> <?= $uomCode ?></td>
                                    <td>
                                        <span class="status-badge <?= $lineStatusClass ?>"><?= htmlspecialchars($lineStatusText) ?></span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>