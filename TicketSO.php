<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Oluştur - MINOA</title>
    <?php include 'navbar.php'; ?>
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

        .btn-back {
            background: white;
            color: #1e40af;
            border: 2px solid #1e40af;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-back:hover {
            background: #f0f9ff;
        }

        .content-wrapper {
            padding: 32px;
            max-width: 1000px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            padding: 32px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e3a8a;
            font-size: 14px;
        }

        .form-group input[type="text"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-group input[type="text"]:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        /* Priority Segmented Buttons */
        .priority-group {
            display: flex;
            gap: 12px;
        }

        .priority-btn {
            flex: 1;
            padding: 12px 20px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
        }

        .priority-btn:hover {
            border-color: #3b82f6;
            background: #f0f9ff;
        }

        .priority-btn.active {
            border-color: transparent;
            color: white;
        }

        .priority-btn.priority-low.active {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        }

        .priority-btn.priority-medium.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .priority-btn.priority-high.active {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed #e5e7eb;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            transition: all 0.2s ease;
        }

        .file-upload:hover {
            border-color: #3b82f6;
            background: #f0f9ff;
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-upload-label {
            display: inline-block;
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .file-upload-label:hover {
            background: #2563eb;
        }

        .file-name {
            margin-top: 12px;
            font-size: 14px;
            color: #6b7280;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-cancel {
            background: white;
            color: #6b7280;
            border: 2px solid #e5e7eb;
        }

        .btn-cancel:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        .btn-submit {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <main class="main-content">
        <div class="page-header">
            <h2>Ticket Oluştur</h2>
            <a href="Ticket.php" class="btn-back">
                <span>←</span>
                <span>Geri</span>
            </a>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <form id="ticket-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="sube">Şube</label>
                            <select id="sube">
                                <option value="">Şube Seçiniz</option>
                                <option value="suadiye">Suadiye</option>
                                <option value="taksim">Taksim</option>
                                <option value="kadikoy">Kadıköy</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="birim">Birim</label>
                            <select id="birim">
                                <option value="">Birim Seçiniz</option>
                                <option value="it">IT</option>
                                <option value="genel">Genel</option>
                                <option value="satinalma">Satınalma</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Ticket Öncelik</label>
                        <div class="priority-group">
                            <button type="button" class="priority-btn priority-low" data-priority="low" onclick="selectPriority('low')">
                                Düşük
                            </button>
                            <button type="button" class="priority-btn priority-medium active" data-priority="medium" onclick="selectPriority('medium')">
                                Orta
                            </button>
                            <button type="button" class="priority-btn priority-high" data-priority="high" onclick="selectPriority('high')">
                                Yüksek
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="aciklama">Açıklama</label>
                        <textarea id="aciklama" placeholder="Ticket açıklamasını girin..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Görsel</label>
                        <div class="file-upload">
                            <label for="gorsel" class="file-upload-label">Dosya Seç</label>
                            <input type="file" id="gorsel" accept="image/*" onchange="handleFileSelect(this)">
                            <div class="file-name" id="file-name">Dosya seçilmedi</div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-cancel" onclick="window.location.href='Ticket.php'">İptal</button>
                        <button type="submit" class="btn btn-submit">Gönder</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        let selectedPriority = 'medium';

        function selectPriority(priority) {
            selectedPriority = priority;
            document.querySelectorAll('.priority-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`[data-priority="${priority}"]`).classList.add('active');
        }

        function handleFileSelect(input) {
            const fileName = document.getElementById('file-name');
            if (input.files && input.files[0]) {
                fileName.textContent = input.files[0].name;
            } else {
                fileName.textContent = 'Dosya seçilmedi';
            }
        }

        document.getElementById('ticket-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = {
                sube: document.getElementById('sube').value,
                birim: document.getElementById('birim').value,
                oncelik: selectedPriority,
                aciklama: document.getElementById('aciklama').value,
                gorsel: document.getElementById('gorsel').files[0]
            };

            // Gerçek uygulamada API çağrısı yapılacak
            console.log('Ticket gönderiliyor:', formData);
            alert('Ticket başarıyla oluşturuldu! (Demo)');
            window.location.href = 'Ticket.php';
        });
    </script>
</body>
</html>









<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Oluştur - MINOA</title>
    <?php include 'navbar.php'; ?>
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

        .btn-back {
            background: white;
            color: #1e40af;
            border: 2px solid #1e40af;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-back:hover {
            background: #f0f9ff;
        }

        .content-wrapper {
            padding: 32px;
            max-width: 1000px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            padding: 32px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e3a8a;
            font-size: 14px;
        }

        .form-group input[type="text"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-group input[type="text"]:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        /* Priority Segmented Buttons */
        .priority-group {
            display: flex;
            gap: 12px;
        }

        .priority-btn {
            flex: 1;
            padding: 12px 20px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
        }

        .priority-btn:hover {
            border-color: #3b82f6;
            background: #f0f9ff;
        }

        .priority-btn.active {
            border-color: transparent;
            color: white;
        }

        .priority-btn.priority-low.active {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        }

        .priority-btn.priority-medium.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .priority-btn.priority-high.active {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed #e5e7eb;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            transition: all 0.2s ease;
        }

        .file-upload:hover {
            border-color: #3b82f6;
            background: #f0f9ff;
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-upload-label {
            display: inline-block;
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .file-upload-label:hover {
            background: #2563eb;
        }

        .file-name {
            margin-top: 12px;
            font-size: 14px;
            color: #6b7280;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-cancel {
            background: white;
            color: #6b7280;
            border: 2px solid #e5e7eb;
        }

        .btn-cancel:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        .btn-submit {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <main class="main-content">
        <div class="page-header">
            <h2>Ticket Oluştur</h2>
            <a href="Ticket.php" class="btn-back">
                <span>←</span>
                <span>Geri</span>
            </a>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <form id="ticket-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="sube">Şube</label>
                            <select id="sube">
                                <option value="">Şube Seçiniz</option>
                                <option value="suadiye">Suadiye</option>
                                <option value="taksim">Taksim</option>
                                <option value="kadikoy">Kadıköy</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="birim">Birim</label>
                            <select id="birim">
                                <option value="">Birim Seçiniz</option>
                                <option value="it">IT</option>
                                <option value="genel">Genel</option>
                                <option value="satinalma">Satınalma</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Ticket Öncelik</label>
                        <div class="priority-group">
                            <button type="button" class="priority-btn priority-low" data-priority="low" onclick="selectPriority('low')">
                                Düşük
                            </button>
                            <button type="button" class="priority-btn priority-medium active" data-priority="medium" onclick="selectPriority('medium')">
                                Orta
                            </button>
                            <button type="button" class="priority-btn priority-high" data-priority="high" onclick="selectPriority('high')">
                                Yüksek
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="aciklama">Açıklama</label>
                        <textarea id="aciklama" placeholder="Ticket açıklamasını girin..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Görsel</label>
                        <div class="file-upload">
                            <label for="gorsel" class="file-upload-label">Dosya Seç</label>
                            <input type="file" id="gorsel" accept="image/*" onchange="handleFileSelect(this)">
                            <div class="file-name" id="file-name">Dosya seçilmedi</div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-cancel" onclick="window.location.href='Ticket.php'">İptal</button>
                        <button type="submit" class="btn btn-submit">Gönder</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        let selectedPriority = 'medium';

        function selectPriority(priority) {
            selectedPriority = priority;
            document.querySelectorAll('.priority-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`[data-priority="${priority}"]`).classList.add('active');
        }

        function handleFileSelect(input) {
            const fileName = document.getElementById('file-name');
            if (input.files && input.files[0]) {
                fileName.textContent = input.files[0].name;
            } else {
                fileName.textContent = 'Dosya seçilmedi';
            }
        }

        document.getElementById('ticket-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = {
                sube: document.getElementById('sube').value,
                birim: document.getElementById('birim').value,
                oncelik: selectedPriority,
                aciklama: document.getElementById('aciklama').value,
                gorsel: document.getElementById('gorsel').files[0]
            };

            // Gerçek uygulamada API çağrısı yapılacak
            console.log('Ticket gönderiliyor:', formData);
            alert('Ticket başarıyla oluşturuldu! (Demo)');
            window.location.href = 'Ticket.php';
        });
    </script>
</body>
</html>









<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Oluştur - MINOA</title>
    <?php include 'navbar.php'; ?>
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

        .btn-back {
            background: white;
            color: #1e40af;
            border: 2px solid #1e40af;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn-back:hover {
            background: #f0f9ff;
        }

        .content-wrapper {
            padding: 32px;
            max-width: 1000px;
            margin: 0 auto;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            padding: 32px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #1e3a8a;
            font-size: 14px;
        }

        .form-group input[type="text"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
            font-family: inherit;
        }

        .form-group input[type="text"]:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .form-group textarea {
            min-height: 120px;
            resize: vertical;
        }

        /* Priority Segmented Buttons */
        .priority-group {
            display: flex;
            gap: 12px;
        }

        .priority-btn {
            flex: 1;
            padding: 12px 20px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
        }

        .priority-btn:hover {
            border-color: #3b82f6;
            background: #f0f9ff;
        }

        .priority-btn.active {
            border-color: transparent;
            color: white;
        }

        .priority-btn.priority-low.active {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
        }

        .priority-btn.priority-medium.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }

        .priority-btn.priority-high.active {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }

        /* File Upload */
        .file-upload {
            border: 2px dashed #e5e7eb;
            border-radius: 8px;
            padding: 24px;
            text-align: center;
            transition: all 0.2s ease;
        }

        .file-upload:hover {
            border-color: #3b82f6;
            background: #f0f9ff;
        }

        .file-upload input[type="file"] {
            display: none;
        }

        .file-upload-label {
            display: inline-block;
            padding: 10px 20px;
            background: #3b82f6;
            color: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .file-upload-label:hover {
            background: #2563eb;
        }

        .file-name {
            margin-top: 12px;
            font-size: 14px;
            color: #6b7280;
        }

        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #e5e7eb;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-cancel {
            background: white;
            color: #6b7280;
            border: 2px solid #e5e7eb;
        }

        .btn-cancel:hover {
            background: #f9fafb;
            border-color: #d1d5db;
        }

        .btn-submit {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
            box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3);
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
    <main class="main-content">
        <div class="page-header">
            <h2>Ticket Oluştur</h2>
            <a href="Ticket.php" class="btn-back">
                <span>←</span>
                <span>Geri</span>
            </a>
        </div>

        <div class="content-wrapper">
            <div class="card">
                <form id="ticket-form">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="sube">Şube</label>
                            <select id="sube">
                                <option value="">Şube Seçiniz</option>
                                <option value="suadiye">Suadiye</option>
                                <option value="taksim">Taksim</option>
                                <option value="kadikoy">Kadıköy</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="birim">Birim</label>
                            <select id="birim">
                                <option value="">Birim Seçiniz</option>
                                <option value="it">IT</option>
                                <option value="genel">Genel</option>
                                <option value="satinalma">Satınalma</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Ticket Öncelik</label>
                        <div class="priority-group">
                            <button type="button" class="priority-btn priority-low" data-priority="low" onclick="selectPriority('low')">
                                Düşük
                            </button>
                            <button type="button" class="priority-btn priority-medium active" data-priority="medium" onclick="selectPriority('medium')">
                                Orta
                            </button>
                            <button type="button" class="priority-btn priority-high" data-priority="high" onclick="selectPriority('high')">
                                Yüksek
                            </button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="aciklama">Açıklama</label>
                        <textarea id="aciklama" placeholder="Ticket açıklamasını girin..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Görsel</label>
                        <div class="file-upload">
                            <label for="gorsel" class="file-upload-label">Dosya Seç</label>
                            <input type="file" id="gorsel" accept="image/*" onchange="handleFileSelect(this)">
                            <div class="file-name" id="file-name">Dosya seçilmedi</div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="button" class="btn btn-cancel" onclick="window.location.href='Ticket.php'">İptal</button>
                        <button type="submit" class="btn btn-submit">Gönder</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        let selectedPriority = 'medium';

        function selectPriority(priority) {
            selectedPriority = priority;
            document.querySelectorAll('.priority-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`[data-priority="${priority}"]`).classList.add('active');
        }

        function handleFileSelect(input) {
            const fileName = document.getElementById('file-name');
            if (input.files && input.files[0]) {
                fileName.textContent = input.files[0].name;
            } else {
                fileName.textContent = 'Dosya seçilmedi';
            }
        }

        document.getElementById('ticket-form').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = {
                sube: document.getElementById('sube').value,
                birim: document.getElementById('birim').value,
                oncelik: selectedPriority,
                aciklama: document.getElementById('aciklama').value,
                gorsel: document.getElementById('gorsel').files[0]
            };

            // Gerçek uygulamada API çağrısı yapılacak
            console.log('Ticket gönderiliyor:', formData);
            alert('Ticket başarıyla oluşturuldu! (Demo)');
            window.location.href = 'Ticket.php';
        });
    </script>
</body>
</html>











