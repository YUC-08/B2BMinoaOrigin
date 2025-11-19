   Durumu kapalı olanları tekrar teslim aldırtmıyor 
   
   $lineData = [
                'ItemCode' => $itemCode,
                'Quantity' => $deliveredQty,
                'FromWarehouseCode' => $toWarehouse,
                'WarehouseCode' => $targetWarehouse,
                'BaseType' => 1250000001, // InventoryTransferRequest
                'BaseEntry' => intval($doc), // InventoryTransferRequest DocEntry
                'BaseLine' => $line['LineNum'] ?? 0
            ];  

            
    // 2. Kullanıcının teslim aldığı belge (FromWarehouse = ToWarehouse, ToWarehouse = Şube ana deposu, U_ASB2B_TYPE = 'MAIN', U_ASB2B_STATUS = '4')
    // Önce InventoryTransferRequest'ten ToWarehouse'u al
    $toWarehouse = $requestData['ToWarehouse'] ?? '';
    $uAsOwnr = $_SESSION["U_AS_OWNR"] ?? '';
    $branch = $_SESSION["WhsCode"] ?? $_SESSION["Branch2"]["Name"] ?? '';
    $deliveryTransfers = [];
    
    if (!empty($toWarehouse) && !empty($uAsOwnr) && !empty($branch)) {
        // Şubenin ana deposunu bul (U_ASB2B_MAIN=1)
        $targetWarehouseFilter = "U_AS_OWNR eq '{$uAsOwnr}' and U_ASB2B_BRAN eq '{$branch}' and U_ASB2B_MAIN eq '1'";
        $targetWarehouseQuery = "Warehouses?\$filter=" . urlencode($targetWarehouseFilter);
        $targetWarehouseData = $sap->get($targetWarehouseQuery);
        $targetWarehouses = $targetWarehouseData['response']['value'] ?? [];
        $targetWarehouse = !empty($targetWarehouses) ? $targetWarehouses[0]['WarehouseCode'] : null;
        
        if (!empty($targetWarehouse)) {
            // Kullanıcının teslim aldığı StockTransfer belgesini bul
            // Not: anadepo_teslim_al.php'de oluşturulan belgede FromWarehouse = InventoryTransferRequest'in ToWarehouse'u
            // ve ToWarehouse = Şubenin ana deposu (targetWarehouse)
            $deliveryTransferFilter = "FromWarehouse eq '{$toWarehouse}' and ToWarehouse eq '{$targetWarehouse}' and U_ASB2B_TYPE eq 'MAIN'";
            $deliveryTransferQuery = "StockTransfers?\$filter=" . urlencode($deliveryTransferFilter) . "&\$expand=StockTransferLines&\$orderby=DocEntry desc&\$top=1";
            $deliveryTransferData = $sap->get($deliveryTransferQuery);
            $deliveryTransfers = $deliveryTransferData['response']['value'] ?? [];
            
            if (!empty($deliveryTransfers)) {
                $deliveryTransferInfo = $deliveryTransfers[0];
                
                // Teslim alınan miktarları map'e ekle
                $dtLines = $deliveryTransferInfo['StockTransferLines'] ?? [];
                foreach ($dtLines as $dtLine) {
                    $itemCode = $dtLine['ItemCode'] ?? '';
                    $qty = (float)($dtLine['Quantity'] ?? 0);
                    $deliveryTransferLinesMap[$itemCode] = $qty; 
                }
            }
        }
    }
    
    // DEBUG Bilgisi
    if (empty($stockTransfers)) {
        error_log("DEBUG: DocEntry {$docEntry} için sevk StockTransfer bulunamadı. Filtre: {$stockTransferFilter}");
    } else {
        error_log("DEBUG: DocEntry {$docEntry} için sevk StockTransfer DocEntry: {$stockTransferInfo['DocEntry']}");
    }
    
    if (empty($deliveryTransfers)) {
        error_log("DEBUG: DocEntry {$docEntry} için teslimat StockTransfer bulunamadı. ToWarehouse: {$toWarehouse}, TargetWarehouse: " . ($targetWarehouse ?? 'NULL') . ", All transfers count: " . count($allDeliveryTransfers ?? []));
    } else {
        error_log("DEBUG: DocEntry {$docEntry} için teslimat StockTransfer DocEntry: {$deliveryTransferInfo['DocEntry']}, Lines count: " . count($deliveryTransferInfo['StockTransferLines'] ?? []));
        error_log("DEBUG: Teslimat miktarları: " . json_encode($deliveryTransferLinesMap));
    }
} 

Hata:
StockTransfer oluşturulamadı! HTTP 400 - {"code":"-10","details":[{"code":"","message":""}],"message":"One of the base documents has already been closed "} 

-------------------------------------------------------------------------------------------------------------------------------------------------------------- 
--------------------------------------------------------------------------------------------------------------------------------------------------------------
--------------------------------------------------------------------------------------------------------------------------------------------------------------
--------------------------------------------------------------------------------------------------------------------------------------------------------------