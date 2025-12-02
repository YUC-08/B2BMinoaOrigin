# Insomnia Requests - Dis Tedarik Modülü

## 1. Login (Session almak için)
**Method:** POST  
**URL:** `https://192.168.54.185:50000/b1s/v2/Login`  
**Headers:**
```
Content-Type: application/json
```
**Body:**
```json
{
  "CompanyDB": "SBODEMOUS",
  "UserName": "manager",
  "Password": "1234"
}
```
**Response'dan:** `SessionId` değerini al, Cookie olarak kullan.

---

## 2. Dis Tedarik Liste (PurchaseRequestList)
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/view.svc/ASB2B_PurchaseRequestList_B1SLQuery`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Query Parameters:**
```
$filter=U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '100'
$orderby=RequestNo desc
$top=100
```

**Tam URL (encoded):**
```
https://192.168.54.185:50000/b1s/v2/view.svc/ASB2B_PurchaseRequestList_B1SLQuery?$filter=U_AS_OWNR%20eq%20%27KT%27%20and%20U_ASB2B_BRAN%20eq%20%27100%27&$orderby=RequestNo%20desc&$top=100
```

---

## 3. Dis Tedarik Kalem Listesi (MainWhsItem - DÜZELTİLDİ ✅)
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/view.svc/ASB2B_MainWhsItem_B1SLQuery`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```

**✅ DOĞRU (FromWhsName kullan - DÜZELTİLDİ):**
```
$filter=FromWhsName eq 'Kitapevi ana depo'
$orderby=ItemCode
$top=25
$skip=0
```

**Tam URL (encoded):**
```
https://192.168.54.185:50000/b1s/v2/view.svc/ASB2B_MainWhsItem_B1SLQuery?$filter=FromWhsName%20eq%20%27Kitapevi%20ana%20depo%27&$orderby=ItemCode&$top=25&$skip=0
```

**Not:** 
- FromWhsName değerini önce Warehouses'tan çek (U_ASB2B_FATH eq 'Y' ile)
- ASB2B_MainWhsItem_B1SLQuery view'inde `U_AS_OWNR` property'si YOK!
- Sadece `FromWhsName` ile filtreleme yapılabilir

---

## 4. Warehouses - Talep Eden Depo (U_ASB2B_MAIN eq '2')
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/Warehouses`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Query Parameters:**
```
$select=WarehouseCode,WarehouseName
$filter=U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '100' and U_ASB2B_MAIN eq '2'
```

**Tam URL:**
```
https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode,WarehouseName&$filter=U_AS_OWNR%20eq%20%27KT%27%20and%20U_ASB2B_BRAN%20eq%20%27100%27%20and%20U_ASB2B_MAIN%20eq%20%272%27
```

---

## 5. Warehouses - Ana Depo (FromWhsName için)
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/Warehouses`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Query Parameters:**
```
$select=WarehouseCode,WarehouseName
$filter=U_ASB2B_FATH eq 'Y' and U_AS_OWNR eq 'KT'
```

**Tam URL:**
```
https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode,WarehouseName&$filter=U_ASB2B_FATH%20eq%20%27Y%27%20and%20U_AS_OWNR%20eq%20%27KT%27
```

---

## 6. PurchaseOrders - Detay
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/PurchaseOrders(7671)`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Query Parameters:**
```
$expand=DocumentLines
```

**Tam URL:**
```
https://192.168.54.185:50000/b1s/v2/PurchaseOrders(7671)?$expand=DocumentLines
```

---

## 7. PurchaseRequests - Detay
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/PurchaseRequests(53)`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Query Parameters:**
```
$expand=PurchaseRequestLines
```

**Tam URL:**
```
https://192.168.54.185:50000/b1s/v2/PurchaseRequests(53)?$expand=PurchaseRequestLines
```

---

## 8. PurchaseDeliveryNotes - POST (Teslim Al)
**Method:** POST  
**URL:** `https://192.168.54.185:50000/b1s/v2/PurchaseDeliveryNotes`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Body:**
```json
{
  "CardCode": "C001",
  "U_ASB2B_NumAtCard": "123456",
  "DocumentLines": [
    {
      "BaseType": 22,
      "BaseEntry": 7671,
      "BaseLine": 0,
      "Quantity": 100
    }
  ]
}
```

---

## 9. PurchaseRequests - POST (Yeni Talep)
**Method:** POST  
**URL:** `https://192.168.54.185:50000/b1s/v2/PurchaseRequests`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Body:**
```json
{
  "DocDate": "2025-01-30",
  "DocDueDate": "2025-10-30",
  "RequriedDate": "2025-10-30",
  "Comments": "Satınalma talebi",
  "U_ASB2B_BRAN": "100",
  "U_AS_OWNR": "KT",
  "U_ASB2B_STATUS": "1",
  "U_ASB2B_User": "turgay",
  "DocCurrency": "USD",
  "DocRate": 1.0,
  "DocumentLines": [
    {
      "ItemCode": "90228",
      "Quantity": 60,
      "UoMCode": "AD",
      "WarehouseCode": "100-KT-1",
      "VendorNum": "P100001"
    }
  ]
}
```

**ÖNEMLİ:** 
- ✅ Doğru field name: `DocumentLines` (PurchaseRequestLines değil!)
- `DocDate`: Doküman tarihi
- `DocDueDate`: Vade tarihi

---

## ÖNEMLİ NOTLAR:

1. **ASB2B_MainWhsItem_B1SLQuery** view'inde `U_AS_OWNR` property'si YOK!
   - ✅ Doğru: `FromWhsName eq 'Kitapevi ana depo'`
   - ❌ Yanlış: `U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '100'`

2. **URL Encoding:** 
   - Boşluk: `%20`
   - Tek tırnak: `%27`
   - `$` işareti: `$` (encode edilmez)

3. **Session Cookie:** Her request'te `Cookie: B1SESSION=<SESSION_ID>` header'ı gerekli.

4. **SSL:** `192.168.54.185:50000` için SSL verification'ı kapat (self-signed certificate olabilir).

https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode,WarehouseName,U_ASB2B_BRAN,U_ASB2B_MAIN&$filter=U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '200'

https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode,WarehouseName,U_ASB2B_BRAN,U_ASB2B_MAIN&$filter=U_AS_OWNR eq 'KT' and U_ASB2B_BRAN eq '100'

https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode,WarehouseName,U_ASB2B_BRAN&$filter=U_AS_OWNR eq 'KT' and U_ASB2B_MAIN eq '1' 

https://192.168.54.185:50000/b1s/v2/BusinessPartners?$select=CardCode,CardName&$filter=CardType eq 'cSupplier' Dış Tedarik Supplier Tedarikçiler

---

## 13. Kullanıcılar - EmployeesInfo (Tüm Kullanıcılar - Basit)
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/EmployeesInfo`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Query Parameters:**
```
$select=EmployeeID,FirstName,LastName,U_AS_OWNR,U_ASB2B_USER
```

**Tam URL:**
```
https://192.168.54.185:50000/b1s/v2/EmployeesInfo?$select=EmployeeID,FirstName,LastName,U_AS_OWNR,U_ASB2B_USER
```

---

## 14. Kullanıcılar - EmployeesInfo (KT veya MS sektöründeki Muse kullanıcıları)
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/EmployeesInfo`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Query Parameters:**
```
$select=EmployeeID,FirstName,LastName,U_AS_OWNR,U_ASB2B_USER
$filter=(U_AS_OWNR eq 'KT' or U_AS_OWNR eq 'MS') and contains(U_ASB2B_USER, 'muse')
$expand=Branch2($select=Name,Description,Code)
$top=100
```

**Tam URL (encoded - expand düzeltildi):**
```
https://192.168.54.185:50000/b1s/v2/EmployeesInfo?$select=EmployeeID,FirstName,LastName,U_AS_OWNR,U_ASB2B_USER&$filter=(U_AS_OWNR%20eq%20%27KT%27%20or%20U_AS_OWNR%20eq%20%27MS%27)%20and%20contains(U_ASB2B_USER,%20%27muse%27)&$expand=Branch2($select=Name,Description,Code)&$top=100
```

**Response Örneği:**
```json
{
  "value": [
    {
      "EmployeeID": 1,
      "FirstName": "Muse",
      "LastName": "Kullanıcı",
      "U_AS_OWNR": "KT",
      "U_ASB2B_USER": "muse",
      "Branch2": {
        "Name": "100",
        "Description": "Taksim Pera",
        "Code": "100"
      }
    }
  ]
}
```

**Not:** 
- `U_AS_OWNR`: Sektör kodu (KT veya MS)
- `EmployeeID, FirstName, LastName`: Kullanıcının kimliği
- `U_ASB2B_USER`: B2B kullanıcı adı
- `Branch2`: Şube bilgileri (expand ile gelir)
- `$top=100` (tırnak işareti olmadan)

---

## 10. Transferler - Warehouses (ToWarehouse - Sevkiyat Deposu)
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/Warehouses`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Query Parameters:**
```
$select=WarehouseCode,WarehouseName,U_ASB2B_BRAN
$filter=U_AS_OWNR eq 'KT' and U_ASB2B_MAIN eq '2' and U_ASB2B_BRAN eq '150'
```

**Tam URL (encoded):**
```
https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode,WarehouseName,U_ASB2B_BRAN&$filter=U_AS_OWNR%20eq%20%27KT%27%20and%20U_ASB2B_MAIN%20eq%20%272%27%20and%20U_ASB2B_BRAN%20eq%20%27150%27
```

**Örnek Şube Kodları:**
- Taksim Pera: `U_ASB2B_BRAN eq '100'`
- Kadıköy: `U_ASB2B_BRAN eq '200'`
- Beşiktaş: `U_ASB2B_BRAN eq '150'`

---

## 11. Transferler - Warehouses (FromWarehouse - Ana Depo)
**Method:** GET  
**URL:** `https://192.168.54.185:50000/b1s/v2/Warehouses`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Query Parameters:**
```
$select=WarehouseCode,WarehouseName,U_ASB2B_BRAN
$filter=U_AS_OWNR eq 'KT' and U_ASB2B_MAIN eq '1' and U_ASB2B_BRAN eq '150'
```

**Tam URL (encoded):**
```
https://192.168.54.185:50000/b1s/v2/Warehouses?$select=WarehouseCode,WarehouseName,U_ASB2B_BRAN&$filter=U_AS_OWNR%20eq%20%27KT%27%20and%20U_ASB2B_MAIN%20eq%20%271%27%20and%20U_ASB2B_BRAN%20eq%20%27150%27
```

---

## 12. Transferler - InventoryTransferRequests - POST (Yeni Transfer Talebi)
**Method:** POST  
**URL:** `https://192.168.54.185:50000/b1s/v2/InventoryTransferRequests`  
**Headers:**
```
Cookie: B1SESSION=<SESSION_ID_BURAYA>
Content-Type: application/json
```
**Body:**
```json
{
  "DocDate": "2025-01-30",
  "FromWarehouse": "150-KT-0",
  "ToWarehouse": "150-KT-1",
  "Comments": "Transfer nakil talebi",
  "U_ASB2B_BRAN": "150",
  "U_AS_OWNR": "KT",
  "U_ASB2B_STATUS": "1",
  "U_ASB2B_TYPE": "TRANSFER",
  "U_ASB2B_User": "ekin",
  "StockTransferLines": [
    {
      "ItemCode": "90228",
      "Quantity": 10,
      "FromWarehouseCode": "150-KT-0",
      "WarehouseCode": "150-KT-1"
    }
  ]
}
```

**ÖNEMLİ:** 
- `FromWarehouse`: Gönderen şube ana deposu (U_ASB2B_MAIN='1', örn: "150-KT-0")
- `ToWarehouse`: Alıcı şube sevkiyat deposu (U_ASB2B_MAIN='2', örn: "150-KT-1")
- `U_ASB2B_BRAN`: Şube kodu (sadece sayı, örn: "100", "150", "200")
- `StockTransferLines`: Her satır için `FromWarehouseCode` ve `WarehouseCode` belirtilmeli 