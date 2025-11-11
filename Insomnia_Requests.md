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

