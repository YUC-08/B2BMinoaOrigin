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

$userName = $_SESSION["UserName"] ?? '';

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

// View type belirleme (incoming veya outgoing)
$viewType = $_GET['view'] ?? 'incoming';

// Filtre parametreleri
$filterStatus = $_GET['status'] ?? '';
$filterStartDate = $_GET['start_date'] ?? '';
$filterEndDate = $_GET['end_date'] ?? '';

// Durum metinleri
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

// Durum CSS class'ları
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

function canReceive($s) {
    return in_array($s, ['2', '3'], true);
}

function canApprove($s) {
    return in_array($s, ['0', '1'], true);
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

// 1. Gelen transferler (ToWarehouse = '100-KT-1')
$incomingTransfers = [];
$incomingDebugInfo = [];
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
    
    $selectValue = "DocEntry,DocDate,DueDate,U_ASB2B_NumAtCard,U_ASB2B_STATUS,FromWarehouse";
    $filterEncoded = urlencode($incomingFilter);
    $orderByEncoded = urlencode("DocEntry desc");
    $incomingQuery = "InventoryTransferRequests?\$select=" . urlencode($selectValue) . "&\$filter=" . $filterEncoded . "&\$orderby=" . $orderByEncoded . "&\$top=25";
    
    $incomingData = $sap->get($incomingQuery);
    $incomingTransfersRaw = $incomingData['response']['value'] ?? [];
    
    // Ana depo warehouse'larını filtrele
    foreach ($incomingTransfersRaw as $transfer) {
        $fromWhsCode = $transfer['FromWarehouse'] ?? '';
        if (!empty($fromWhsCode)) {
            $whsCheckQuery = "Warehouses('{$fromWhsCode}')?\$select=U_ASB2B_FATH";
            $whsCheckData = $sap->get($whsCheckQuery);
            $isAnadepo = ($whsCheckData['response']['U_ASB2B_FATH'] ?? '') === 'Y';
            if (!$isAnadepo) {
                $incomingTransfers[] = $transfer;
            }
        } else {
            $incomingTransfers[] = $transfer;
        }
    }
    
    // Debug bilgisi
    $incomingDebugInfo['toWarehouse'] = $toWarehouse;
    $incomingDebugInfo['incomingQuery'] = $incomingQuery;
    $incomingDebugInfo['incomingFilter'] = $incomingFilter;
    $incomingDebugInfo['incomingHttpStatus'] = $incomingData['status'] ?? 0;
    $incomingDebugInfo['incomingRawCount'] = count($incomingTransfersRaw);
    $incomingDebugInfo['incomingFilteredCount'] = count($incomingTransfers);
}

// 2. Giden transferler (FromWarehouse = '100-KT-0')
$outgoingTransfers = [];
$debugInfo = [];
if ($fromWarehouse) {
        // Sadece TRANSFER tipi (ana depo transferleri MAIN tipinde olur)
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
        
        $selectValue = "DocEntry,DocDate,DueDate,U_ASB2B_NumAtCard,U_ASB2B_STATUS,ToWarehouse";
        // URL encoding: OData query parametrelerini doğru şekilde encode et
        // Expand parametresini kaldırdık - her transfer için ayrı ayrı lines çekeceğiz
        $outgoingQuery = "InventoryTransferRequests?\$select=" . urlencode($selectValue) . "&\$filter=" . urlencode($outgoingFilter) . "&\$orderby=" . urlencode("DocEntry desc") . "&\$top=25";
        
        // Debug: Query'yi logla
        error_log("[TRANSFERLER] Outgoing Query: " . $outgoingQuery);
        error_log("[TRANSFERLER] Outgoing Filter: " . $outgoingFilter);
        
        $outgoingData = $sap->get($outgoingQuery);
        $outgoingTransfersRaw = $outgoingData['response']['value'] ?? [];
        
        // Debug bilgisi
        $debugInfo['outgoingQuery'] = $outgoingQuery;
        $debugInfo['outgoingFilter'] = $outgoingFilter;
        $debugInfo['outgoingHttpStatus'] = $outgoingData['status'] ?? 0;
        $debugInfo['outgoingRawCount'] = count($outgoingTransfersRaw);
        $debugInfo['fromWarehouse'] = $fromWarehouse;
        $debugInfo['uAsOwnr'] = $uAsOwnr;
        $debugInfo['branch'] = $branch;
        
        if (isset($outgoingData['response']['error'])) {
            $debugInfo['error'] = $outgoingData['response']['error'];
        }
        
        // Ana depo warehouse'larını filtrele ve her transfer için detayları çek
        foreach ($outgoingTransfersRaw as $transfer) {
            $toWhsCode = $transfer['ToWarehouse'] ?? '';
            if (!empty($toWhsCode)) {
                $whsCheckQuery = "Warehouses('{$toWhsCode}')?\$select=U_ASB2B_FATH";
                $whsCheckData = $sap->get($whsCheckQuery);
                $isAnadepo = ($whsCheckData['response']['U_ASB2B_FATH'] ?? '') === 'Y';
                if (!$isAnadepo) {
                    $docEntry = $transfer['DocEntry'] ?? '';
                    if (!empty($docEntry)) {
                        // InventoryTransferRequests için lines'ı çek - her iki navigation property'yi de dene
                        $lines = [];
                        $linesDebug = []; // Debug bilgisi için
                        
                        // 1) InventoryTransferRequestLines ile dene ($expand)
                        $docQuery = "InventoryTransferRequests({$docEntry})?\$expand=InventoryTransferRequestLines";
                        $docData = $sap->get($docQuery);
                        $linesDebug['method1'] = [
                            'query' => $docQuery,
                            'status' => $docData['status'] ?? 0,
                            'found' => false
                        ];
                        
                        if (($docData['status'] ?? 0) == 200) {
                            $requestData = $docData['response'] ?? null;
                            if ($requestData && isset($requestData['InventoryTransferRequestLines']) && is_array($requestData['InventoryTransferRequestLines'])) {
                                $lines = $requestData['InventoryTransferRequestLines'];
                                $linesDebug['method1']['found'] = true;
                                $linesDebug['method1']['count'] = count($lines);
                            }
                        } else {
                            $linesDebug['method1']['error'] = $docData['response']['error'] ?? 'Unknown error';
                        }
                        
                        // 2) Hâlâ boşsa, StockTransferLines ile tekrar dene ($expand)
                        if (empty($lines)) {
                            $docQuery2 = "InventoryTransferRequests({$docEntry})?\$expand=StockTransferLines";
                            $docData2 = $sap->get($docQuery2);
                            $linesDebug['method2'] = [
                                'query' => $docQuery2,
                                'status' => $docData2['status'] ?? 0,
                                'found' => false
                            ];
                            
                            if (($docData2['status'] ?? 0) == 200) {
                                $requestData2 = $docData2['response'] ?? null;
                                if ($requestData2 && isset($requestData2['StockTransferLines']) && is_array($requestData2['StockTransferLines'])) {
                                    $lines = $requestData2['StockTransferLines'];
                                    $linesDebug['method2']['found'] = true;
                                    $linesDebug['method2']['count'] = count($lines);
                                }
                            } else {
                                $linesDebug['method2']['error'] = $docData2['response']['error'] ?? 'Unknown error';
                            }
                        }
                        
                        // 3) Hâlâ boşsa, navigation endpointleri tek tek dene
                        // a) InventoryTransferRequestLines endpoint
                        if (empty($lines)) {
                            $linesQuery = "InventoryTransferRequests({$docEntry})/InventoryTransferRequestLines";
                            $linesData = $sap->get($linesQuery);
                            $linesDebug['method3'] = [
                                'query' => $linesQuery,
                                'status' => $linesData['status'] ?? 0,
                                'found' => false
                            ];
                            
                            if (($linesData['status'] ?? 0) == 200) {
                                $linesResponse = $linesData['response'] ?? null;
                                if ($linesResponse) {
                                    if (isset($linesResponse['value']) && is_array($linesResponse['value'])) {
                                        $lines = $linesResponse['value'];
                                        $linesDebug['method3']['found'] = true;
                                        $linesDebug['method3']['count'] = count($lines);
                                    } elseif (is_array($linesResponse) && !isset($linesResponse['value'])) {
                                        $lines = $linesResponse;
                                        $linesDebug['method3']['found'] = true;
                                        $linesDebug['method3']['count'] = count($lines);
                                    }
                                }
                            } else {
                                $linesDebug['method3']['error'] = $linesData['response']['error'] ?? 'Unknown error';
                            }
                        }
                        
                        // b) Hâlâ boşsa, StockTransferLines endpoint
                        if (empty($lines)) {
                            $linesQuery2 = "InventoryTransferRequests({$docEntry})/StockTransferLines";
                            $linesData2 = $sap->get($linesQuery2);
                            $linesDebug['method4'] = [
                                'query' => $linesQuery2,
                                'status' => $linesData2['status'] ?? 0,
                                'found' => false
                            ];
                            
                            if (($linesData2['status'] ?? 0) == 200) {
                                $linesResponse2 = $linesData2['response'] ?? null;
                                if ($linesResponse2) {
                                    // Önce 'value' array'ini kontrol et
                                    if (isset($linesResponse2['value']) && is_array($linesResponse2['value'])) {
                                        $lines = $linesResponse2['value'];
                                        $linesDebug['method4']['found'] = true;
                                        $linesDebug['method4']['count'] = count($lines);
                                    } 
                                    // Eğer direkt array ise
                                    elseif (is_array($linesResponse2) && !isset($linesResponse2['value']) && !isset($linesResponse2['@odata.context'])) {
                                        $lines = $linesResponse2;
                                        $linesDebug['method4']['found'] = true;
                                        $linesDebug['method4']['count'] = count($lines);
                                    }
                                    // Eğer StockTransferLines object olarak geliyorsa (navigation property)
                                    elseif (isset($linesResponse2['StockTransferLines'])) {
                                        $stockTransferLines = $linesResponse2['StockTransferLines'];
                                        // Eğer object ise, değerlerini array'e çevir
                                        if (is_array($stockTransferLines) && !isset($stockTransferLines[0]) && !empty($stockTransferLines)) {
                                            // Object formatında geliyorsa (key-value pairs), array'e çevir
                                            $lines = array_values($stockTransferLines);
                                        } elseif (is_array($stockTransferLines)) {
                                            $lines = $stockTransferLines;
                                        }
                                        $linesDebug['method4']['found'] = true;
                                        $linesDebug['method4']['count'] = count($lines);
                                    }
                                }
                            } else {
                                $linesDebug['method4']['error'] = $linesData2['response']['error'] ?? 'Unknown error';
                            }
                        }
                        
                        // Debug bilgisini transfer'e ekle
                        $transfer['_LinesDebug'] = $linesDebug;
                        $transfer['_LinesCount'] = count($lines);
                        
                        // Her line için stok miktarını çek ve normalize et
                        if (!empty($lines) && is_array($lines)) {
                            foreach ($lines as &$line) {
                                // Line'ın array olduğundan emin ol
                                if (!is_array($line)) {
                                    continue;
                                }
                                
                                $itemCode = $line['ItemCode'] ?? '';
                                $quantity = floatval($line['Quantity'] ?? 0);
                                $stockQty = 0;
                                $baseQty = 1.0; // Varsayılan BaseQty
                                $uomCode = $line['UoMCode'] ?? 'AD';
                                
                                if (!empty($itemCode)) {
                                    // Item bilgilerini çek (BaseQty ve stok için)
                                    $itemQuery = "Items('{$itemCode}')?\$select=BaseQty,UoMCode&\$expand=ItemWarehouseInfoCollection";
                                    $itemData = $sap->get($itemQuery);
                                    
                                    if (($itemData['status'] ?? 0) == 200) {
                                        $itemInfo = $itemData['response'] ?? null;
                                        if ($itemInfo) {
                                            // BaseQty'yi al
                                            $baseQty = floatval($itemInfo['BaseQty'] ?? 1.0);
                                            $uomCode = $itemInfo['UoMCode'] ?? $line['UoMCode'] ?? 'AD';
                                            
                                            // Stok miktarını çek - ItemWarehouseInfoCollection içinden
                                            if (!empty($fromWarehouse)) {
                                                $itemWarehouseInfo = $itemInfo['ItemWarehouseInfoCollection'] ?? [];
                                                if (is_array($itemWarehouseInfo) && !empty($itemWarehouseInfo)) {
                                                    // WarehouseCode'a göre filtrele
                                                    foreach ($itemWarehouseInfo as $whInfo) {
                                                        if (is_array($whInfo) && ($whInfo['WarehouseCode'] ?? '') === $fromWarehouse) {
                                                            $stockQty = floatval($whInfo['InStock'] ?? $whInfo['Available'] ?? 0);
                                                            break;
                                                        }
                                                    }
                                                }
                                                
                                                // Eğer expand ile bulunamazsa, direkt navigation property ile dene
                                                if ($stockQty == 0) {
                                                    $whInfoQuery2 = "Items('{$itemCode}')/ItemWarehouseInfoCollection?\$filter=WarehouseCode eq '{$fromWarehouse}'";
                                                    $whInfoData2 = $sap->get($whInfoQuery2);
                                                    if (($whInfoData2['status'] ?? 0) == 200 && isset($whInfoData2['response']['value'][0])) {
                                                        $whInfo = $whInfoData2['response']['value'][0];
                                                        if (is_array($whInfo)) {
                                                            $stockQty = floatval($whInfo['InStock'] ?? $whInfo['Available'] ?? 0);
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                                
                                // Normalize et - AnaDepo mantığına uygun
                                // Quantity SAP'de BaseQty × kullanıcı miktarı olarak saklanıyor
                                // Kullanıcıya gösterirken: Quantity / BaseQty = kullanıcı miktarı
                                $userRequestedQty = $baseQty > 0 ? ($quantity / $baseQty) : $quantity;
                                
                                $line['_StockQty'] = $stockQty;
                                $line['_RequestedQty'] = $userRequestedQty; // Kullanıcı miktarı
                                $line['_BaseQty'] = $baseQty;
                                $line['UoMCode'] = $uomCode;
                                // Varsayılan: talep kadar gönder (kullanıcı sepette değiştirebilir)
                                $line['_SentQty'] = $userRequestedQty;
                            }
                            unset($line);
                        }
                        
                        // Normalize edilmiş lines'ı transfer'e ata
                        $transfer['InventoryTransferRequestLines'] = $lines;
                    } else {
                        $transfer['InventoryTransferRequestLines'] = [];
                    }
                    
                    $outgoingTransfers[] = $transfer;
                }
            } else {
                // ToWarehouse boşsa da ekle (güvenlik için)
                $outgoingTransfers[] = $transfer;
            }
        }
}

// Tarih formatlama
function formatDate($date) {
    if (empty($date)) return '-';
    if (strpos($date, 'T') !== false) {
        return date('d.m.Y', strtotime(substr($date, 0, 10)));
    }
    return date('d.m.Y', strtotime($date));
}

function buildSearchData(...$parts) {
    $textParts = [];
    foreach ($parts as $part) {
        if (!empty($part) && $part !== '-') {
            $textParts[] = $part;
        }
    }
    $text = implode(' ', $textParts);
    return mb_strtolower($text ?? '', 'UTF-8');
}

// Warehouse isimlerini çek (ana depo warehouse'larını hariç tut)
$warehouseNamesMap = [];
$allWarehouseCodes = [];
foreach ($incomingTransfers as $transfer) {
    if (!empty($transfer['FromWarehouse'])) {
        $allWarehouseCodes[] = $transfer['FromWarehouse'];
    }
}
foreach ($outgoingTransfers as $transfer) {
    if (!empty($transfer['ToWarehouse'])) {
        $allWarehouseCodes[] = $transfer['ToWarehouse'];
    }
}
$allWarehouseCodes = array_unique($allWarehouseCodes);
if (!empty($allWarehouseCodes)) {
    foreach ($allWarehouseCodes as $whsCode) {
        // Warehouse bilgisini çek ve ana depo olup olmadığını kontrol et
        $whsQuery = "Warehouses('{$whsCode}')?\$select=WarehouseCode,WarehouseName,U_ASB2B_FATH";
        $whsData = $sap->get($whsQuery);
        if (($whsData['status'] ?? 0) == 200 && isset($whsData['response']['WarehouseName'])) {
            // Ana depo değilse ekle (U_ASB2B_FATH ne 'Y')
            $isAnadepo = ($whsData['response']['U_ASB2B_FATH'] ?? '') === 'Y';
            if (!$isAnadepo) {
                $warehouseNamesMap[$whsCode] = $whsData['response']['WarehouseName'];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transferler - MINOA</title>
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

/* Main content - adjusted for sidebar */
.main-content {
    width: 100%;
    background: whitesmoke;
    padding: 0;
    min-height: 100vh;
}

.sidebar.expanded ~ .main-content {
    margin-left: 260px;
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
    padding: 32px;
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

        .filter-section {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
    padding: 24px;
    background: #f8fafc;
    border-bottom: 1px solid #e5e7eb;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
    gap: 8px;
        }

        .filter-group label {
    color: #1e3a8a;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.filter-group input[type="date"],
.filter-group select {
    padding: 10px 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
            font-size: 14px;
    transition: all 0.2s ease;
    background: white;
}

.filter-group input[type="date"]:hover,
.filter-group select:hover {
    border-color: #3b82f6;
}

.filter-group input[type="date"]:focus,
.filter-group select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Table Controls */
.table-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 24px;
    border-bottom: 1px solid #e5e7eb;
}

.show-entries {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 14px;
    color: #6b7280;
}

.entries-select {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
            border-radius: 6px;
    font-size: 14px;
            cursor: pointer;
    transition: all 0.2s ease;
}

.entries-select:hover {
    border-color: #3b82f6;
}

.entries-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.search-box {
    display: flex;
    gap: 8px;
    align-items: center;
}

.search-input {
    padding: 8px 12px;
    border: 2px solid #e5e7eb;
    border-radius: 6px;
    font-size: 14px;
    min-width: 220px;
    transition: border-color 0.2s;
}

.search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: linear-gradient(180deg, #f8fafc 0%, #f1f5f9 100%);
}

.data-table th {
    padding: 16px 20px;
    text-align: left;
            font-weight: 600;
    font-size: 13px;
    color: #1e3a8a;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e5e7eb;
}

.data-table td {
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    font-size: 14px;
    color: #374151;
}

.data-table tbody tr {
    transition: background 0.15s ease;
}

.data-table tbody tr:hover {
    background: #f8fafc;
}

.status-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 12px;
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

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
}

.btn-primary:hover {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
    transform: translateY(-1px);
}

.btn-secondary {
    background: white;
    color: #3b82f6;
    border: 2px solid #3b82f6;
}

.btn-secondary:hover {
    background: #f0f9ff;
}

.btn-secondary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-icon {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-view {
    background: #f0f9ff;
    color: #1e40af;
    border: 1px solid #bfdbfe;
}

.btn-view:hover {
    background: #dbeafe;
}

.btn-receive {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.3);
}

.btn-receive:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
    transform: translateY(-1px);
}

.btn-approve {
    background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
    color: white;
    box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
}

.btn-approve:hover {
    background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
    box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4);
    transform: translateY(-1px);
}

.table-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

/* Modern Checkbox Styling */
input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    border: 2px solid #cbd5e1;
    border-radius: 4px;
    background: white;
    position: relative;
    transition: all 0.2s ease;
}

input[type="checkbox"]:hover {
    border-color: #3b82f6;
    background: #f0f9ff;
}

input[type="checkbox"]:checked {
    background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
    border-color: #3b82f6;
}

input[type="checkbox"]:checked::after {
    content: '✓';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    color: white;
    font-size: 14px;
    font-weight: bold;
    line-height: 1;
}

input[type="checkbox"]:focus {
    outline: none;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
}

/* Sepet Panel */
.sepet-panel {
    position: fixed;
    right: 0;
    top: 80px;
    width: 420px;
    height: calc(100vh - 80px);
    background: white;
    box-shadow: -2px 0 12px rgba(0,0,0,0.15);
    z-index: 50;
    display: none;
    flex-direction: column;
}

.sepet-panel-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 2px solid #e5e7eb;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
}

.sepet-panel-content {
    flex: 1;
    overflow-y: auto;
    padding: 1.5rem;
}

.sepet-panel-footer {
    padding: 1.25rem 1.5rem;
    border-top: 2px solid #e5e7eb;
    background: white;
}
    </style>
</head>
<body>
        <?php include 'navbar.php'; ?>
    <main class="main-content">
        <header class="page-header">
                <h2>Transferler</h2>
            <div style="display: flex; gap: 12px; align-items: center;">
                <?php if ($viewType === 'incoming'): ?>
                    <button class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=outgoing<?= !empty($filterStatus) ? '&status=' . urlencode($filterStatus) : '' ?><?= !empty($filterStartDate) ? '&start_date=' . urlencode($filterStartDate) : '' ?><?= !empty($filterEndDate) ? '&end_date=' . urlencode($filterEndDate) : '' ?>'">
                        📤 Giden Transferler
                    </button>
                    <button class="btn btn-primary" onclick="window.location.href='TransferlerSO.php'">+ Yeni Transfer Oluştur</button>
                <?php else: ?>
                    <button class="btn btn-secondary" onclick="window.location.href='Transferler.php?view=incoming<?= !empty($filterStatus) ? '&status=' . urlencode($filterStatus) : '' ?><?= !empty($filterStartDate) ? '&start_date=' . urlencode($filterStartDate) : '' ?><?= !empty($filterEndDate) ? '&end_date=' . urlencode($filterEndDate) : '' ?>'">
                        📥 Gelen Transferler
                    </button>
                    <button class="btn btn-primary" onclick="sepetToggle()" id="sepetBtn" style="display: inline-flex;">
                        🛒 Sepet (<span id="sepetCount">0</span>)
                    </button>
                    <button class="btn btn-primary" onclick="window.location.href='TransferlerSO.php'">+ Yeni Transfer Oluştur</button>
                <?php endif; ?>
                </div>
        </header>

        <div class="content-wrapper">
            <section class="card">
                    <div class="filter-section">
                        <div class="filter-group">
                            <label>Transfer Durumu</label>
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
                <div class="table-controls">
                    <div class="show-entries">
                        Sayfada 
                        <select class="entries-select" id="entriesPerPage" onchange="applyFilters()">
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="75">75</option>
                        </select>
                        kayıt göster
                        </div>
                    <div class="search-box">
                        <input type="text" class="search-input" id="tableSearch" placeholder="Ara..." onkeyup="if(event.key==='Enter') performSearch()">
                        <button class="btn btn-secondary" onclick="performSearch()">🔍</button>
                        </div>
                            </div>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Transfer No</th>
                            <th>Gönderen Şube</th>
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
                                <td colspan="7" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    Gelen transfer bulunamadı.
                                    <?php if (isset($incomingDebugInfo) && !empty($incomingDebugInfo)): ?>
                                        <div style="margin-top: 1rem; padding: 1rem; background: #fef3c7; border-radius: 6px; font-size: 0.875rem; text-align: left; max-width: 600px; margin-left: auto; margin-right: auto;">
                                            <strong>Debug Bilgisi (Gelen Transferler):</strong><br>
                                            Branch: <?= htmlspecialchars($incomingDebugInfo['branch'] ?? 'BULUNAMADI') ?><br>
                                            U_AS_OWNR: <?= htmlspecialchars($incomingDebugInfo['uAsOwnr'] ?? 'BULUNAMADI') ?><br>
                                            ToWarehouse Query: <?= htmlspecialchars($incomingDebugInfo['toWhsQuery'] ?? '') ?><br>
                                            ToWarehouse HTTP Status: <?= htmlspecialchars($incomingDebugInfo['toWhsHttpStatus'] ?? '0') ?><br>
                                            ToWarehouse: <?= htmlspecialchars($incomingDebugInfo['toWarehouse'] ?? 'BULUNAMADI') ?><br>
                                            <?php if (!empty($incomingDebugInfo['toWarehouse'])): ?>
                                                Incoming Query: <?= htmlspecialchars($incomingDebugInfo['incomingQuery'] ?? '') ?><br>
                                                Incoming Filter: <?= htmlspecialchars($incomingDebugInfo['incomingFilter'] ?? '') ?><br>
                                                Incoming HTTP Status: <?= htmlspecialchars($incomingDebugInfo['incomingHttpStatus'] ?? '0') ?><br>
                                                Incoming Raw Count: <?= htmlspecialchars($incomingDebugInfo['incomingRawCount'] ?? '0') ?><br>
                                            <?php endif; ?>
                                            <?php if (isset($incomingDebugInfo['error'])): ?>
                                                Error: <?= htmlspecialchars(json_encode($incomingDebugInfo['error'])) ?><br>
                                            <?php endif; ?>
                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($incomingTransfers as $transfer): 
                                $status = $transfer['U_ASB2B_STATUS'] ?? '0';
                                $statusText = getStatusText($status);
                                $statusClass = getStatusClass($status);
                                // Gelen transferlerde: Hazırlanıyor (2) veya Sevk Edildi (3) durumunda Teslim Al butonu göster
                                $canReceive = in_array($status, ['2', '3']); // Hazırlanıyor veya Sevk Edildi
                                $fromWhsCode = $transfer['FromWarehouse'] ?? '';
                                $fromWhsName = $warehouseNamesMap[$fromWhsCode] ?? '';
                                $fromWhsDisplay = $fromWhsCode;
                                if (!empty($fromWhsName)) {
                                    $fromWhsDisplay = $fromWhsCode . ' / ' . $fromWhsName;
                                }
                                $docDate = formatDate($transfer['DocDate'] ?? '');
                                $dueDate = formatDate($transfer['DueDate'] ?? '');
                                $numAtCard = htmlspecialchars($transfer['U_ASB2B_NumAtCard'] ?? '-');
                                $docEntry = htmlspecialchars($transfer['DocEntry'] ?? '-');
                                $searchData = buildSearchData($docEntry, $fromWhsDisplay, $docDate, $dueDate, $numAtCard, $statusText);
                            ?>
                                <tr data-row data-search="<?= htmlspecialchars($searchData, ENT_QUOTES, 'UTF-8') ?>">
                                    <td style="font-weight: 600; color: #1e40af;"><?= $docEntry ?></td>
                                    <td><?= htmlspecialchars($fromWhsDisplay) ?></td>
                                    <td><?= $docDate ?></td>
                                    <td><?= $dueDate ?></td>
                                    <td><?= $numAtCard ?></td>
                                    <td>
                                        <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="Transferler-Detay.php?docEntry=<?= urlencode($transfer['DocEntry']) ?>&type=incoming">
                                                <button class="btn-icon btn-view">👁️ Detay</button>
                                            </a>
                                            <?php if ($canReceive): ?>
                                                <a href="Transferler-TeslimAl.php?docEntry=<?= urlencode($transfer['DocEntry']) ?>">
                                                    <button class="btn-icon btn-receive">✓ Teslim Al</button>
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
                <!-- Giden Transferler (Bu şubeden giden) -->
                <div class="table-controls">
                    <div class="show-entries">
                        Sayfada 
                        <select class="entries-select" id="entriesPerPage" onchange="applyFilters()">
                            <option value="25" selected>25</option>
                            <option value="50">50</option>
                            <option value="75">75</option>
                        </select>
                        kayıt göster
                    </div>
                    <div class="search-box">
                        <input type="text" class="search-input" id="tableSearch" placeholder="Ara..." onkeyup="if(event.key==='Enter') performSearch()">
                        <button class="btn btn-secondary" onclick="performSearch()">🔍</button>
                    </div>
                        </div>
                        <table class="data-table">
                            <thead>
                                <tr>
                            <th style="width: 40px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()"></th>
                                    <th>Transfer No</th>
                                    <th>Alıcı Şube</th>
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
                                <td colspan="8" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    Giden transfer bulunamadı.
                                    <?php if (isset($debugInfo) && !empty($debugInfo)): ?>
                                        <div style="margin-top: 1rem; padding: 1rem; background: #fef3c7; border-radius: 6px; font-size: 0.875rem; text-align: left; max-width: 600px; margin-left: auto; margin-right: auto;">
                                            <strong>Debug Bilgisi (Giden Transferler):</strong><br>
                                            Branch: <?= htmlspecialchars($debugInfo['branch'] ?? 'BULUNAMADI') ?><br>
                                            U_AS_OWNR: <?= htmlspecialchars($debugInfo['uAsOwnr'] ?? 'BULUNAMADI') ?><br>
                                            FromWarehouse Query: <?= htmlspecialchars($debugInfo['fromWhsQuery'] ?? '') ?><br>
                                            FromWarehouse HTTP Status: <?= htmlspecialchars($debugInfo['fromWhsHttpStatus'] ?? '0') ?><br>
                                            FromWarehouse: <?= htmlspecialchars($debugInfo['fromWarehouse'] ?? 'BULUNAMADI') ?><br>
                                            <?php if (!empty($debugInfo['fromWarehouse'])): ?>
                                                Outgoing Query: <?= htmlspecialchars($debugInfo['outgoingQuery'] ?? '') ?><br>
                                                Outgoing Filter: <?= htmlspecialchars($debugInfo['outgoingFilter'] ?? '') ?><br>
                                                Outgoing HTTP Status: <?= htmlspecialchars($debugInfo['outgoingHttpStatus'] ?? '0') ?><br>
                                                Outgoing Raw Count: <?= htmlspecialchars($debugInfo['outgoingRawCount'] ?? '0') ?><br>
                                            <?php endif; ?>
                                            <?php if (isset($debugInfo['error'])): ?>
                                                Error: <?= htmlspecialchars(json_encode($debugInfo['error'])) ?><br>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                            </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($outgoingTransfers as $transfer): 
                                $status = $transfer['U_ASB2B_STATUS'] ?? '0';
                                $statusText = getStatusText($status);
                                $statusClass = getStatusClass($status);
                                // Giden transferlerde: Onay Bekliyor (0, 1) durumunda Onayla/İptal butonları
                                $canApprove = in_array($status, ['0', '1']); // Onay Bekliyor
                                $toWhsCode = $transfer['ToWarehouse'] ?? '';
                                $toWhsName = $warehouseNamesMap[$toWhsCode] ?? '';
                                $toWhsDisplay = $toWhsCode;
                                if (!empty($toWhsName)) {
                                    $toWhsDisplay = $toWhsCode . ' / ' . $toWhsName;
                                }
                                $docDate = formatDate($transfer['DocDate'] ?? '');
                                $dueDate = formatDate($transfer['DueDate'] ?? '');
                                $numAtCard = htmlspecialchars($transfer['U_ASB2B_NumAtCard'] ?? '-');
                                $docEntry = htmlspecialchars($transfer['DocEntry'] ?? '-');
                                $searchData = buildSearchData($docEntry, $toWhsDisplay, $docDate, $dueDate, $numAtCard, $statusText);
                                $lines = $transfer['InventoryTransferRequestLines'] ?? [];
                                $linesDebug = $transfer['_LinesDebug'] ?? [];
                                $linesCount = $transfer['_LinesCount'] ?? 0;
                                
                                // Lines boş olsa bile JSON olarak encode et
                                $transferLinesJson = !empty($lines) ? htmlspecialchars(json_encode($lines), ENT_QUOTES, 'UTF-8') : '[]';
                            ?>
                                <tr data-row data-search="<?= htmlspecialchars($searchData, ENT_QUOTES, 'UTF-8') ?>" data-docentry="<?= $docEntry ?>" data-lines="<?= $transferLinesJson ?>">
                                    <?php if (!empty($linesDebug)): ?>
                                    <tr style="background: #fef3c7;">
                                        <td colspan="8" style="padding: 0.75rem; font-size: 0.8rem;">
                                            <strong>🔍 DEBUG - Transfer No: <?= $docEntry ?></strong><br>
                                            <strong>Lines Count:</strong> <?= $linesCount ?><br>
                                            <?php foreach ($linesDebug as $method => $info): ?>
                                                <div style="margin-top: 0.5rem; padding: 0.5rem; background: white; border-radius: 4px; border-left: 3px solid <?= $info['found'] ?? false ? '#10b981' : '#ef4444' ?>;">
                                                    <strong><?= strtoupper($method) ?>:</strong><br>
                                                    Query: <code style="font-size: 0.75rem;"><?= htmlspecialchars($info['query'] ?? '') ?></code><br>
                                                    Status: <strong style="color: <?= ($info['status'] ?? 0) == 200 ? '#10b981' : '#ef4444' ?>"><?= $info['status'] ?? 0 ?></strong><br>
                                                    Found: <strong style="color: <?= $info['found'] ?? false ? '#10b981' : '#ef4444' ?>"><?= $info['found'] ? 'YES' : 'NO' ?></strong>
                                                    <?php if (isset($info['count'])): ?>
                                                        <br>Count: <strong><?= $info['count'] ?></strong>
                                                    <?php endif; ?>
                                                    <?php if (isset($info['error'])): ?>
                                                        <br>Error: <span style="color: #ef4444;"><?= htmlspecialchars(json_encode($info['error'])) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>
                                        </tr>
                                    <?php endif; ?>
                                    <td style="text-align: center;">
                                        <?php if ($canApprove): ?>
                                            <input type="checkbox" class="transfer-checkbox" value="<?= $docEntry ?>" data-docentry="<?= $docEntry ?>">
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-weight: 600; color: #1e40af;"><?= $docEntry ?></td>
                                    <td><?= htmlspecialchars($toWhsDisplay) ?></td>
                                    <td><?= $docDate ?></td>
                                    <td><?= $dueDate ?></td>
                                    <td><?= $numAtCard ?></td>
                                    <td>
                                        <span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($statusText) ?></span>
                                            </td>
                                    <td>
                                        <div class="table-actions">
                                            <a href="Transferler-Detay.php?docEntry=<?= urlencode($transfer['DocEntry']) ?>&type=outgoing">
                                                <button class="btn-icon btn-view">👁️ Detay</button>
                                            </a>
                                            <?php if ($canApprove): ?>
                                                <a href="Transferler-Onayla.php?docEntry=<?= urlencode($transfer['DocEntry']) ?>&action=approve">
                                                    <button class="btn-icon btn-approve">✓ Onayla</button>
                                                </a>
                                                <a href="Transferler-Onayla.php?docEntry=<?= urlencode($transfer['DocEntry']) ?>&action=reject">
                                                    <button class="btn-icon" style="background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;">✗ İptal</button>
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
            
            <?php if ($viewType === 'outgoing'): ?>
            <!-- Sepet Panel -->
            <div id="sepetPanel" class="sepet-panel">
                <div class="sepet-panel-header">
                    <h3 style="margin: 0; color: #1e40af; font-size: 1.25rem; font-weight: 600;">🛒 Sepet</h3>
                    <button class="btn btn-secondary" onclick="sepetToggle()" style="padding: 0.5rem 1rem; font-size: 0.875rem;">✕ Kapat</button>
                    </div>
                <div id="sepetContent" class="sepet-panel-content">
                    <div style="text-align: center; padding: 2rem; color: #9ca3af;">
                        Sepet boş. Lütfen onaylamak istediğiniz transferleri seçin.
                </div>
            </div>
                <div class="sepet-panel-footer">
                    <button class="btn btn-primary" onclick="sepetOnayla()" style="width: 100%; padding: 12px; font-size: 1rem; font-weight: 600;">
                        ✓ Toplu Onayla
                    </button>
        </div>
            </div>
            <?php endif; ?>
        </div>
    </main>

        <script>
        // ============================================
        // SEPET SİSTEMİ - YENİDEN YAZILDI
        // ============================================
        
        let sepet = {}; // { docEntry: { toWarehouse, lines: [...] } }
        
        // Sepet panelini aç/kapat
        function sepetToggle() {
            const panel = document.getElementById('sepetPanel');
            if (panel) {
                const isVisible = panel.style.display === 'flex';
                panel.style.display = isVisible ? 'none' : 'flex';
            }
        }
        
        // Tümünü seç/seçimi kaldır
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('input[type="checkbox"][value]:not(#selectAll)');
            const isChecked = selectAll.checked;
            
            checkboxes.forEach(cb => {
                cb.checked = isChecked;
                if (isChecked) {
                    sepetEkle(cb.value);
                } else {
                    sepetCikar(cb.value);
                }
            });
            
            sepetGuncelle();
        }
        
        // Checkbox değiştiğinde
        function checkboxChanged(checkbox) {
            console.log('=== CHECKBOX CHANGED ===');
            console.log('Value:', checkbox.value);
            console.log('Checked:', checkbox.checked);
            console.log('Element:', checkbox);
            
            if (checkbox.checked) {
                console.log('CHECKBOX CHECKED - SEPETE EKLEMEYE ÇALIŞIYOR');
                sepetEkle(checkbox.value);
            } else {
                console.log('CHECKBOX UNCHECKED - SEPETTEN ÇIKARIYOR');
                sepetCikar(checkbox.value);
            }
            
            console.log('SEPET DURUMU:', sepet);
            console.log('SEPET COUNT:', Object.keys(sepet).length);
            sepetGuncelle();
            console.log('=== CHECKBOX CHANGED END ===');
        }
        
        // Sepete ekle
        function sepetEkle(docEntry) {
            console.log('=== SEPET EKLE BAŞLADI ===');
            console.log('DocEntry:', docEntry);
            console.log('Mevcut Sepet:', sepet);
            
            if (!docEntry) {
                console.error('SEPET EKLE - DOCENTRY BOŞ!');
                return;
            }
            
            if (sepet[docEntry]) {
                console.warn('SEPET EKLE - ZATEN SEPETTE VAR:', docEntry);
                return;
            }
            
            const row = document.querySelector(`tr[data-docentry="${docEntry}"]`);
            console.log('Row bulundu:', !!row);
            if (!row) {
                console.error('SEPET EKLE - ROW BULUNAMADI! Selector:', `tr[data-docentry="${docEntry}"]`);
                // Alternatif selector dene
                const altRow = document.querySelector(`tr[data-docentry='${docEntry}']`);
                console.log('Alternatif row:', !!altRow);
                return;
            }
            
            // Lines bilgisini al
            const linesJson = row.getAttribute('data-lines') || '[]';
            console.log('=== LINES JSON DEBUG ===');
            console.log('Lines JSON (raw):', linesJson);
            console.log('Lines JSON length:', linesJson.length);
            console.log('Lines JSON substring (first 200 chars):', linesJson.substring(0, 200));
            
            let lines = [];
            if (linesJson && linesJson !== '[]' && linesJson !== 'null' && linesJson.trim() !== '') {
                try {
                    const parsed = JSON.parse(linesJson);
                    console.log('Parsed result type:', typeof parsed);
                    console.log('Parsed result is array:', Array.isArray(parsed));
                    console.log('Parsed result length:', Array.isArray(parsed) ? parsed.length : 'N/A');
                    console.log('Parsed result (first item):', Array.isArray(parsed) && parsed.length > 0 ? parsed[0] : 'N/A');
                    
                    // Eğer bir error object ise, boş array kullan
                    if (parsed && typeof parsed === 'object' && parsed.error) {
                        console.error('SAP Error in lines:', parsed.error);
                        lines = [];
                    } else if (Array.isArray(parsed)) {
                        lines = parsed;
                        console.log('✅ Lines parsed başarılı, count:', lines.length);
                        if (lines.length > 0) {
                            console.log('First line sample:', lines[0]);
                        }
                    } else if (parsed && parsed.value && Array.isArray(parsed.value)) {
                        // Eğer {value: [...]} formatında ise
                        lines = parsed.value;
                        console.log('Lines parsed (value array), count:', lines.length);
                    } else if (parsed && parsed.StockTransferLines) {
                        // Eğer StockTransferLines object olarak geliyorsa (navigation property)
                        const stockTransferLines = parsed.StockTransferLines;
                        if (Array.isArray(stockTransferLines)) {
                            lines = stockTransferLines;
                            console.log('Lines parsed (StockTransferLines array), count:', lines.length);
                        } else if (typeof stockTransferLines === 'object' && stockTransferLines !== null) {
                            // Object formatında geliyorsa (key-value pairs), array'e çevir
                            lines = Object.values(stockTransferLines);
                            console.log('Lines parsed (StockTransferLines object converted to array), count:', lines.length);
                        } else {
                            console.warn('StockTransferLines beklenmeyen format:', stockTransferLines);
                            lines = [];
                        }
                    } else {
                        console.warn('Lines beklenmeyen format:', parsed);
                        lines = [];
                    }
                    console.log('Final lines count:', lines.length);
                } catch (e) {
                    console.error('Lines parse hatası:', e);
                    console.error('Hatalı JSON:', linesJson);
                    lines = [];
                }
            } else {
                console.warn('Lines JSON boş veya geçersiz:', linesJson);
            }
            console.log('=== LINES JSON DEBUG END ===');
            
            // Alıcı şube bilgisini al (3. sütun: checkbox(1), TransferNo(2), AlıcıŞube(3))
            const toWarehouseCell = row.querySelector('td:nth-child(3)');
            const toWarehouse = toWarehouseCell ? toWarehouseCell.textContent.trim() : '';
            console.log('ToWarehouse cell:', !!toWarehouseCell);
            console.log('ToWarehouse:', toWarehouse);
            
            // Sepete ekle - lines array kontrolü
            if (!Array.isArray(lines)) {
                console.error('Lines bir array değil!', lines);
                lines = [];
            }
            
            // Sepete ekle - normalize edilmiş format (BaseQty dahil)
            sepet[docEntry] = {
                toWarehouse: toWarehouse,
                lines: lines.map(l => {
                    const baseQty = parseFloat(l._BaseQty || 1.0);
                    const requestedQty = parseFloat(l._RequestedQty || 0);
                    const quantity = parseFloat(l.Quantity || 0);
                    
                    return {
                        ItemCode: l.ItemCode || '',
                        ItemName: l.ItemDescription || l.ItemName || '',
                        UoMCode: l.UoMCode || 'AD',
                        LineNum: l.LineNum || 0,
                        BaseQty: baseQty,
                        RequestedQty: requestedQty > 0 ? requestedQty : (baseQty > 0 ? (quantity / baseQty) : quantity),
                        StockQty: parseFloat(l._StockQty || 0),
                        SentQty: parseFloat(l._SentQty || l._RequestedQty || (baseQty > 0 ? (quantity / baseQty) : quantity))
                    };
                })
            };
            
            console.log('SEPET EKLE - BAŞARIYLA EKLENDI!');
            console.log('Eklenen transfer:', sepet[docEntry]);
            console.log('Güncel sepet:', sepet);
            console.log('=== SEPET EKLE BİTTİ ===');
        }
        
        // Sepetten çıkar
        function sepetCikar(docEntry) {
            if (sepet[docEntry]) {
                delete sepet[docEntry];
            }
        }
        
        // Sepeti güncelle (görsel)
        function sepetGuncelle() {
            console.log('=== SEPET GUNCELLE BAŞLADI ===');
            const count = Object.keys(sepet).length;
            console.log('Sepet count:', count);
            console.log('Sepet objesi:', sepet);
            console.log('Sepet keys:', Object.keys(sepet));
            
            const sepetCountEl = document.getElementById('sepetCount');
            const sepetBtn = document.getElementById('sepetBtn');
            const sepetContent = document.getElementById('sepetContent');
            const sepetPanel = document.getElementById('sepetPanel');
            
            console.log('Elementler bulundu:', {
                sepetCountEl: !!sepetCountEl,
                sepetBtn: !!sepetBtn,
                sepetContent: !!sepetContent,
                sepetPanel: !!sepetPanel
            });
            
            // Sepet sayısını güncelle
            if (sepetCountEl) {
                sepetCountEl.textContent = count;
                console.log('Sepet count güncellendi:', count);
            } else {
                console.error('sepetCountEl bulunamadı!');
            }
            
            // Sepet boşsa butonu ve paneli gizle
            if (count === 0) {
                console.log('Sepet boş - buton ve panel gizleniyor');
                if (sepetBtn) {
                    sepetBtn.style.display = 'none';
                    console.log('Sepet butonu gizlendi');
                }
                if (sepetContent) {
                    sepetContent.innerHTML = '<div style="text-align: center; padding: 2rem; color: #9ca3af;">Sepet boş. Lütfen onaylamak istediğiniz transferleri seçin.</div>';
                }
                if (sepetPanel) {
                    sepetPanel.style.display = 'none';
                    console.log('Sepet paneli gizlendi');
                }
                console.log('=== SEPET GUNCELLE BİTTİ (BOŞ) ===');
                return;
            }
            
            // Sepet doluysa butonu göster
            if (sepetBtn) {
                sepetBtn.style.display = 'inline-flex';
                console.log('Sepet butonu görünür yapıldı');
            } else {
                console.error('sepetBtn bulunamadı!');
            }
            
            // Sepet doluysa paneli göster
            console.log('Sepet dolu - içerik oluşturuluyor');
            if (sepetPanel) {
                sepetPanel.style.display = 'flex';
                console.log('Sepet paneli açıldı (display: flex)');
            }
            
            // Sepet içeriğini oluştur
            if (sepetContent) {
                let html = '';
                for (const [docEntry, t] of Object.entries(sepet)) {
                    html += `<div style="margin-bottom: 1.5rem; padding: 1rem; background: #f8fafc; border-radius: 8px; border: 1px solid #e5e7eb;">`;
                    html += `<div style="font-weight: 600; color: #1e40af; margin-bottom: 0.5rem; font-size: 1rem;">Transfer No: ${docEntry}</div>`;
                    html += `<div style="font-size: 0.875rem; color: #6b7280; margin-bottom: 0.75rem;">Alıcı: ${t.toWarehouse}</div>`;
                    
                    if (!t.lines || t.lines.length === 0) {
                        html += `<div style="padding: 0.75rem; background: #e0edff; border-radius: 6px; font-size: 0.85rem; color: #1d4ed8;">ℹ Kalem bilgisi yüklenemedi veya bu transfer için satır yok.</div>`;
                    } else {
                        t.lines.forEach((line, idx) => {
                            const baseQty = parseFloat(line.BaseQty || 1.0);
                            const req = parseFloat(line.RequestedQty || 0);
                            const stk = parseFloat(line.StockQty || 0);
                            const send = parseFloat(line.SentQty || req);
                            const uomCode = line.UoMCode || 'AD';
                            
                            // En fazla talep edilen kadar gönderilebilir (stok kontrolü yok)
                            const max = req;
                            
                            // BaseQty dönüşüm bilgisi
                            let conversionText = '';
                            if (baseQty !== 1 && baseQty > 0) {
                                const reqAd = req * baseQty;
                                conversionText = ` <span style="font-size: 0.75rem; color: #6b7280;">(${reqAd.toFixed(2)} AD)</span>`;
                            }
                            
                            html += `<div style="margin-bottom: 1rem; padding: 0.75rem; background: #fff; border-radius: 6px; border: 1px solid #e5e7eb;">`;
                            html += `<div style="font-weight: 600; color: #111827; margin-bottom: 0.5rem;">${line.ItemCode} - ${line.ItemName}</div>`;
                            html += `<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; font-size: 0.85rem; margin-bottom: 0.5rem;">`;
                            html += `<div><span style="color: #6b7280;">Talep edilen:</span> <strong>${req.toFixed(2)} ${uomCode}${conversionText}</strong></div>`;
                            html += `<div><span style="color: #6b7280;">Stok:</span> <strong style="color: ${stk > 0 ? '#10b981' : '#ef4444'}">${stk.toFixed(2)} ${uomCode}</strong></div>`;
                            html += `</div>`;
                            html += `<div style="display: flex; align-items: center; gap: 0.5rem;">`;
                            html += `<span style="font-size: 0.85rem; color: #6b7280;">Gönderilecek:</span>`;
                            html += `<button onclick="sepetMiktarDegistir('${docEntry}', ${idx}, -1)" style="width: 32px; height: 32px; border: 1px solid #d1d5db; background: #fff; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">-</button>`;
                            html += `<input type="number" id="sent_${docEntry}_${idx}" value="${send.toFixed(2)}" min="0" max="${max.toFixed(2)}" step="0.01" onchange="sepetMiktarInput('${docEntry}', ${idx}, ${max}, this.value)" style="width: 80px; padding: 0.25rem; text-align: center; border: 1px solid #d1d5db; border-radius: 6px; font-weight: 600; font-size: 14px;">`;
                            html += `<button onclick="sepetMiktarDegistir('${docEntry}', ${idx}, 1)" style="width: 32px; height: 32px; border: 1px solid #d1d5db; background: #fff; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px;">+</button>`;
                            html += `<span style="font-size: 0.85rem; color: #6b7280;">${uomCode}</span>`;
                            html += `</div>`;
                            html += `</div>`;
                        });
                    }
                    html += `</div>`;
                }
                sepetContent.innerHTML = html;
            }
        }
        
        // Miktar değiştir (+/- butonları için)
        function sepetMiktarDegistir(docEntry, idx, delta) {
            const t = sepet[docEntry];
            if (!t || !t.lines[idx]) return;
            
            const line = t.lines[idx];
            const req = parseFloat(line.RequestedQty || 0);
            // Stok olsa bile max = req (talep edilen kadar)
            const max = req;
            
            let yeni = parseFloat(line.SentQty || 0) + delta;
            if (yeni < 0) yeni = 0;
            if (yeni > max) yeni = max;
            
            line.SentQty = yeni;
            sepetGuncelle();
        }
        
        // Miktar güncelle (input için)
        function sepetMiktarInput(docEntry, idx, max, value) {
            const t = sepet[docEntry];
            if (!t || !t.lines[idx]) return;
            
            let v = parseFloat(value) || 0;
            if (v < 0) v = 0;
            if (v > max) v = max;
            
            t.lines[idx].SentQty = v;
            sepetGuncelle();
        }
        
        // Toplu onayla
        function sepetOnayla() {
            const count = Object.keys(sepet).length;
            if (count === 0) {
                alert('Sepet boş!');
                    return;
                }
            
            if (!confirm(`${count} transfer onaylanacak. Devam etmek istiyor musunuz?`)) {
                return;
            }
            
            let successCount = 0;
            let failedCount = 0;
            const promises = [];
            
            for (const [docEntry, transfer] of Object.entries(sepet)) {
                // Lines'ı BaseQty ile çarpılmış haliyle hazırla (SAP'ye gönderilecek format)
                const sapLines = transfer.lines.map(line => {
                    const baseQty = parseFloat(line.BaseQty || 1.0);
                    const sentQty = parseFloat(line.SentQty || 0);
                    // SAP'ye giden miktar = kullanıcı miktarı × BaseQty
                    const sapQuantity = sentQty * baseQty;
                    
                    return {
                        ...line,
                        Quantity: sapQuantity, // SAP'ye giden miktar
                        _SentQty: sentQty // Kullanıcı miktarı (orijinal)
                    };
                });
                
                const promise = fetch('Transferler-Onayla.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        docEntry: docEntry,
                        action: 'approve',
                        lines: JSON.stringify(sapLines)
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        successCount++;
                    } else {
                        failedCount++;
                        console.error('Onaylama hatası:', docEntry, data.message);
                    }
                })
                .catch(error => {
                    failedCount++;
                    console.error('Onaylama hatası:', docEntry, error);
                });
                
                promises.push(promise);
            }
            
            Promise.all(promises).then(() => {
                if (failedCount === 0) {
                    alert(`Tüm transferler onaylandı! (${successCount} adet)`);
                    window.location.reload();
                } else {
                    alert(`${successCount} transfer onaylandı, ${failedCount} transfer onaylanamadı.`);
                    if (successCount > 0) {
                        window.location.reload();
                    }
                }
            });
        }
        
        // Sayfa yüklendiğinde
        document.addEventListener('DOMContentLoaded', function() {
            console.log('=== DOM YUKLENDI - SEPET SISTEMI BASLATILIYOR ===');
            
            // İlk güncelleme
            sepetGuncelle();
            
            // Checkbox listener'ları ekle - class selector kullan
            const checkboxes = document.querySelectorAll('input.transfer-checkbox');
            console.log('Bulunan transfer checkbox sayısı:', checkboxes.length);
            
            if (checkboxes.length === 0) {
                console.warn('HİÇ CHECKBOX BULUNAMADI! Tab kontrolü yapılıyor...');
                const currentTab = document.querySelector('.tab-button.active');
                console.log('Aktif tab:', currentTab ? currentTab.textContent : 'YOK');
            }
            
            checkboxes.forEach((cb, idx) => {
                console.log(`Checkbox ${idx}:`, {
                    value: cb.value,
                    dataDocEntry: cb.getAttribute('data-docentry'),
                    checked: cb.checked,
                    element: cb
                });
                
                // Event listener ekle
                cb.addEventListener('change', function(e) {
                    e.stopPropagation();
                    console.log('=== CHECKBOX CHANGE EVENT FIRED ===');
                    console.log('Event:', e);
                    console.log('This:', this);
                    console.log('Value:', this.value);
                    console.log('Data-docentry:', this.getAttribute('data-docentry'));
                    console.log('Checked:', this.checked);
                    checkboxChanged(this);
                });
                
                console.log(`Checkbox ${idx} listener eklendi`);
            });
            
            // SelectAll listener
            const selectAll = document.getElementById('selectAll');
            if (selectAll) {
                console.log('SelectAll bulundu, listener ekleniyor');
                selectAll.addEventListener('change', function(e) {
                    e.stopPropagation();
                    console.log('=== SELECT ALL CHANGED ===');
                    console.log('Checked:', this.checked);
                    toggleSelectAll();
                });
            } else {
                console.warn('SelectAll bulunamadı!');
            }
            
            console.log('=== DOM YUKLENDI BİTTİ ===');
            });
        </script>

    <script>
        function applyFilters() {
            const status = document.getElementById('filterStatus').value;
            const startDate = document.getElementById('start-date').value;
            const endDate = document.getElementById('end-date').value;
            const viewType = '<?= $viewType ?>';
            const entriesPerPage = document.getElementById('entriesPerPage')?.value || '25';
            
            const params = new URLSearchParams();
            params.append('view', viewType);
            if (status) params.append('status', status);
            if (startDate) params.append('start_date', startDate);
            if (endDate) params.append('end_date', endDate);
            if (entriesPerPage) params.append('entries', entriesPerPage);
            
            window.location.href = 'Transferler.php?' + params.toString();
        }

        function performSearch() {
            const searchTerm = document.getElementById('tableSearch').value.toLowerCase();
            const rows = document.querySelectorAll('tr[data-row]');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const searchData = row.getAttribute('data-search') || '';
                if (searchData.includes(searchTerm)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // "Sonuç bulunamadı" mesajını göster/gizle
            let noResultsRow = document.getElementById('noResultsRow');
            if (visibleCount === 0 && rows.length > 0) {
                if (!noResultsRow) {
                    const tableBody = document.querySelector('table.data-table tbody');
                    if (tableBody) {
                        noResultsRow = document.createElement('tr');
                        noResultsRow.id = 'noResultsRow';
                        noResultsRow.innerHTML = '<td colspan="8" style="text-align: center; padding: 40px; color: #9ca3af;">Sonuç bulunamadı.</td>';
                        tableBody.appendChild(noResultsRow);
                    }
                }
                noResultsRow.style.display = '';
            } else {
                if (noResultsRow) {
                    noResultsRow.style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>
