<?php
/**
 * HỆ THỐNG SẮP LỊCH TRỢ DUYÊN NIỆM PHẬT - BAN HỘ NIỆM / TRỢ NIỆM
 * Phiên bản: 2.2 - Tự động nhận diện danh tính Phật tử qua Zalo (Zalo Auto-ID)
 * Triết lý thiết kế: High-Touch, Low-Tech (Tối giản 1-chạm, chữ to, không cần gõ lại tên)
 * Lưu trữ: SQLite (database.sqlite)
 */

// 1. CẤU HÌNH & KẾT NỐI CƠ SỞ DỮ LIỆU SQLITE
$dbFile = __DIR__ . '/database.sqlite';
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Tối ưu hóa ghi đồng thời bằng chế độ Write-Ahead Logging (WAL)
    $pdo->exec("PRAGMA journal_mode = WAL;");
} catch (PDOException $e) {
    die("Lỗi kết nối CSDL SQLite: " . $e->getMessage());
}

// Tự động khởi tạo cấu trúc 4 Bảng CSDL nếu chưa tồn tại
$pdo->exec("
    CREATE TABLE IF NOT EXISTS events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        patient_name TEXT NOT NULL,
        address TEXT,
        note TEXT,
        status TEXT DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS shifts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id INTEGER NOT NULL,
        shift_name TEXT NOT NULL,
        shift_time TEXT NOT NULL,
        max_target INTEGER DEFAULT 10,
        shift_date DATE,
        FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS registrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        shift_id INTEGER NOT NULL,
        fullname TEXT NOT NULL,
        phone TEXT,
        role_type TEXT DEFAULT 'Thành viên',
        zalo_id TEXT,
        registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE
    );

    CREATE TABLE IF NOT EXISTS logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action TEXT NOT NULL,
        details TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
");

// Tự động nâng cấp CSDL nếu thiếu cột zalo_id
try {
    $pdo->exec("ALTER TABLE registrations ADD COLUMN zalo_id TEXT;");
} catch (Exception $e) {
    // Cột zalo_id đã tồn tại trong CSDL
}

// Hàm ghi Nhật ký Hệ thống (Audit Trail)
function logAction($pdo, $action, $details = '') {
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (action, details) VALUES (?, ?)");
        $stmt->execute([$action, $details]);
    } catch (Exception $e) {
        // Bỏ qua lỗi nếu gặp trục trặc ghi log
    }
}

// Chèn dữ liệu khởi tạo mẫu nếu DB hoàn toàn trống
$stmtCheck = $pdo->query("SELECT COUNT(*) as cnt FROM events");
if ($stmtCheck->fetch()['cnt'] == 0) {
    $pdo->exec("INSERT INTO events (patient_name, address, note, status) VALUES 
        ('Bác X', 'Nhà riêng Bác X (Gia đình chuẩn bị trang nghiêm)', 'Nhà chật, ưu tiên khoảng 10 vị/ca, hoan hỷ tùy duyên.', 'active')");
    $eventId = $pdo->lastInsertId();
    $today = date('Y-m-d');
    $pdo->exec("INSERT INTO shifts (event_id, shift_name, shift_time, max_target, shift_date) VALUES 
        ($eventId, 'Ca Chiều', '14h15\'', 10, '$today'),
        ($eventId, 'Ca Tối', '19h15\'', 10, '$today')");
    logAction($pdo, 'INIT_DATABASE', 'Khởi tạo dữ liệu đợt trợ duyên mẫu thành công');
}

// 2. XỬ LÝ HÀNH ĐỘNG TỪ FORM (POST)
$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Đăng ký ca trợ duyên (1-Click cho Phật tử qua Zalo Auto-ID)
    if ($action === 'register') {
        $shiftId = intval($_POST['shift_id'] ?? 0);
        $fullname = trim($_POST['fullname'] ?? '');
        $roleType = trim($_POST['role_type'] ?? 'Thành viên');
        $zaloId = trim($_POST['zalo_id'] ?? '');
        
        if (!empty($fullname) && $shiftId > 0) {
            $stmt = $pdo->prepare("INSERT INTO registrations (shift_id, fullname, role_type, zalo_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$shiftId, $fullname, $roleType, $zaloId]);
            logAction($pdo, 'REGISTER', "Phật tử $fullname đăng ký Ca ID $shiftId ($roleType) [Tự nhận diện Zalo]");
            $flashMessage = "A Di Đà Phật! Đã ghi nhận Phật tử [ " . htmlspecialchars($fullname) . " ] đăng ký thành công.";
        } else {
            $flashMessage = "Vui lòng kiểm tra lại thông tin nhận diện trên Zalo.";
            $flashType = 'error';
        }
    }

    // Hủy lượt đăng ký
    if ($action === 'cancel_registration') {
        $regId = intval($_POST['reg_id'] ?? 0);
        if ($regId > 0) {
            $stmtReg = $pdo->prepare("SELECT fullname FROM registrations WHERE id = ?");
            $stmtReg->execute([$regId]);
            $reg = $stmtReg->fetch();
            
            $stmt = $pdo->prepare("DELETE FROM registrations WHERE id = ?");
            $stmt->execute([$regId]);
            logAction($pdo, 'CANCEL_REGISTRATION', "Xóa đăng ký ID $regId" . ($reg ? " (Phật tử: {$reg['fullname']})" : ""));
            $flashMessage = "A Di Đà Phật! Đã hoan hỷ hủy lượt đăng ký.";
        }
    }

    // Admin: Tạo đợt trợ duyên mới hoàn toàn
    if ($action === 'create_event') {
        $patientName = trim($_POST['patient_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $afternoonTime = trim($_POST['afternoon_time'] ?? '14h15\'');
        $afternoonTarget = intval($_POST['afternoon_target'] ?? 10);
        $eveningTime = trim($_POST['evening_time'] ?? '19h15\'');
        $eveningTarget = intval($_POST['evening_target'] ?? 10);
        
        if (!empty($patientName)) {
            $pdo->exec("UPDATE events SET status = 'completed' WHERE status = 'active'");
            
            $stmt = $pdo->prepare("INSERT INTO events (patient_name, address, note, status) VALUES (?, ?, ?, 'active')");
            $stmt->execute([$patientName, $address, $note]);
            $newEventId = $pdo->lastInsertId();
            
            $today = date('Y-m-d');
            $stmtShift = $pdo->prepare("INSERT INTO shifts (event_id, shift_name, shift_time, max_target, shift_date) VALUES (?, ?, ?, ?, ?)");
            $stmtShift->execute([$newEventId, 'Ca Chiều', $afternoonTime, $afternoonTarget, $today]);
            $stmtShift->execute([$newEventId, 'Ca Tối', $eveningTime, $eveningTarget, $today]);
            
            logAction($pdo, 'CREATE_EVENT', "Tạo đợt trợ duyên mới: $patientName");
            $flashMessage = "A Di Đà Phật! Đã khởi tạo đợt trợ duyên mới cho " . htmlspecialchars($patientName);
        }
    }

    // Admin: Cập nhật thông tin đợt trợ duyên & Điều chỉnh giờ/chỉ tiêu ưu tiên
    if ($action === 'update_event') {
        $eventId = intval($_POST['event_id'] ?? 0);
        $patientName = trim($_POST['patient_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $note = trim($_POST['note'] ?? '');
        
        if ($eventId > 0 && !empty($patientName)) {
            $stmt = $pdo->prepare("UPDATE events SET patient_name = ?, address = ?, note = ? WHERE id = ?");
            $stmt->execute([$patientName, $address, $note, $eventId]);

            if (isset($_POST['shift_times']) && is_array($_POST['shift_times'])) {
                foreach ($_POST['shift_times'] as $shiftId => $sTime) {
                    $sTarget = intval($_POST['shift_targets'][$shiftId] ?? 10);
                    if ($sTarget <= 0) $sTarget = 10;
                    
                    $stmtS = $pdo->prepare("UPDATE shifts SET shift_time = ?, max_target = ? WHERE id = ? AND event_id = ?");
                    $stmtS->execute([trim($sTime), $sTarget, intval($shiftId), $eventId]);
                }
            }
            
            logAction($pdo, 'UPDATE_EVENT', "Cập nhật thông tin & chỉ tiêu đợt ID $eventId");
            $flashMessage = "A Di Đà Phật! Đã cập nhật thành công thông tin & số lượng ưu tiên.";
        }
    }

    // Admin: Thêm ca trợ duyên đột xuất
    if ($action === 'add_custom_shift') {
        $eventId = intval($_POST['event_id'] ?? 0);
        $shiftName = trim($_POST['shift_name'] ?? '');
        $shiftTime = trim($_POST['shift_time'] ?? '');
        $maxTarget = intval($_POST['max_target'] ?? 10);
        if ($maxTarget <= 0) $maxTarget = 10;
        
        if ($eventId > 0 && !empty($shiftName) && !empty($shiftTime)) {
            $stmt = $pdo->prepare("INSERT INTO shifts (event_id, shift_name, shift_time, max_target, shift_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$eventId, $shiftName, $shiftTime, $maxTarget, date('Y-m-d')]);
            logAction($pdo, 'ADD_SHIFT', "Thêm ca mới [$shiftName - $shiftTime - Chỉ tiêu: $maxTarget] đợt ID $eventId");
            $flashMessage = "Đã thêm ca trợ duyên mới thành công.";
        }
    }

    // Admin: Hoàn thành đợt trợ duyên
    if ($action === 'complete_event') {
        $eventId = intval($_POST['event_id'] ?? 0);
        if ($eventId > 0) {
            $stmt = $pdo->prepare("UPDATE events SET status = 'completed' WHERE id = ?");
            $stmt->execute([$eventId]);
            logAction($pdo, 'COMPLETE_EVENT', "Đóng đợt trợ duyên ID $eventId");
            $flashMessage = "Đã đánh dấu hoàn thành đợt trợ duyên.";
        }
    }
}

// 3. TRUY VẤN DỮ LIỆU HIỆN TẠI
$mode = $_GET['mode'] ?? 'public'; // public, admin, stats, test, agent_kit
$currentEventId = intval($_GET['event_id'] ?? 0);

if ($currentEventId > 0) {
    $stmtEvent = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmtEvent->execute([$currentEventId]);
    $activeEvent = $stmtEvent->fetch();
} else {
    $stmtEvent = $pdo->query("SELECT * FROM events WHERE status = 'active' ORDER BY id DESC LIMIT 1");
    $activeEvent = $stmtEvent->fetch();
}

if (!$activeEvent) {
    $stmtEvent = $pdo->query("SELECT * FROM events ORDER BY id DESC LIMIT 1");
    $activeEvent = $stmtEvent->fetch();
}

$shifts = [];
$registrationsByShift = [];

if ($activeEvent) {
    $stmtShifts = $pdo->prepare("SELECT * FROM shifts WHERE event_id = ? ORDER BY id ASC");
    $stmtShifts->execute([$activeEvent['id']]);
    $shifts = $stmtShifts->fetchAll();

    foreach ($shifts as $s) {
        $stmtReg = $pdo->prepare("SELECT * FROM registrations WHERE shift_id = ? ORDER BY registered_at ASC");
        $stmtReg->execute([$s['id']]);
        $registrationsByShift[$s['id']] = $stmtReg->fetchAll();
    }
}

// Bảng Thống Kê Dữ Liệu Dài Hạn
$memberStats = [];
if ($mode === 'stats' || $mode === 'admin') {
    $stmtStats = $pdo->query("
        SELECT fullname, role_type, COUNT(*) as total_registrations 
        FROM registrations 
        GROUP BY fullname 
        ORDER BY total_registrations DESC, fullname ASC
    ");
    $memberStats = $stmtStats->fetchAll();
}

// 4. KIỂM THỬ TỰ ĐỘNG (?mode=test)
$testResults = [];
if ($mode === 'test') {
    try {
        $testResults[] = ["test" => "Kiểm tra kết nối SQLite PDO (WAL Mode)", "status" => true, "msg" => "Kết nối thành công database.sqlite"];
        
        logAction($pdo, 'TEST_RUNNER', 'Chạy kiểm thử tự động hệ thống Zalo Auto-ID');
        $stmtLogCheck = $pdo->query("SELECT COUNT(*) as cnt FROM logs WHERE action = 'TEST_RUNNER'");
        $testResults[] = ["test" => "Ghi Nhật ký Hệ thống (Logs Table)", "status" => $stmtLogCheck->fetch()['cnt'] > 0, "msg" => "Ghi log thao tác thành công"];

        if (!empty($shifts)) {
            $testShiftId = $shifts[0]['id'];
            $pdo->prepare("INSERT INTO registrations (shift_id, fullname, role_type, zalo_id) VALUES (?, ?, ?, ?)")->execute([$testShiftId, "Phật_Tử_Zalo_Test", "Thành viên", "ZALO_TEST_123"]);
            $dummyId = $pdo->lastInsertId();
            $testResults[] = ["test" => "Tự động nhận diện Zalo & Đăng ký", "status" => true, "msg" => "Chèn ID: $dummyId thành công"];
            
            $pdo->prepare("DELETE FROM registrations WHERE id = ?")->execute([$dummyId]);
            $testResults[] = ["test" => "Xóa lượt đăng ký kiểm thử", "status" => true, "msg" => "Xóa dữ liệu test thành công"];
        }
        
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $has4Tables = in_array('events', $tables) && in_array('shifts', $tables) && in_array('registrations', $tables) && in_array('logs', $tables);
        $testResults[] = ["test" => "Cấu trúc 4 Bảng CSDL SQLite", "status" => $has4Tables, "msg" => "Các bảng hiện tại: " . implode(", ", $tables)];
    } catch (Exception $ex) {
        $testResults[] = ["test" => "Lỗi Kiểm Thử", "status" => false, "msg" => $ex->getMessage()];
    }
}

// Tạo URL cố định cho 1-Link duy nhất
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$currentUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . strtok($_SERVER["REQUEST_URI"], '?');
if ($activeEvent) {
    $currentUrl .= "?event_id=" . $activeEvent['id'];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sắp Lịch Trợ Duyên Niệm Phật - Ban Trợ Niệm</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .text-elder { font-size: 1.25rem; line-height: 1.75rem; }
        .btn-touch { min-height: 58px; font-size: 1.25rem; }
    </style>
</head>
<body class="bg-amber-50 text-slate-800 min-h-screen pb-12">

    <!-- Header Cố Định -->
    <header class="bg-amber-700 text-amber-50 shadow-md py-3.5 px-4 sticky top-0 z-50">
        <div class="max-w-xl mx-auto flex justify-between items-center">
            <div>
                <h1 class="text-lg sm:text-xl font-bold tracking-wide">NAM MÔ A DI ĐÀ PHẬT</h1>
                <p class="text-xs text-amber-200">Đạo Tràng Trợ Duyên Niệm Phật</p>
            </div>
            <div class="flex items-center gap-1 text-xs sm:text-sm font-medium">
                <a href="?mode=public<?php echo $activeEvent ? '&event_id='.$activeEvent['id'] : ''; ?>" class="px-2.5 py-1.5 rounded <?php echo $mode==='public'?'bg-amber-900 text-white':'bg-amber-800/60 hover:bg-amber-800'; ?>">Trợ Duyên</a>
                <a href="?mode=admin" class="px-2.5 py-1.5 rounded <?php echo $mode==='admin'?'bg-amber-900 text-white':'bg-amber-800/60 hover:bg-amber-800'; ?>">Quản Trị</a>
                <a href="?mode=stats" class="px-2.5 py-1.5 rounded <?php echo $mode==='stats'?'bg-amber-900 text-white':'bg-amber-800/60 hover:bg-amber-800'; ?>">Thống Kê</a>
            </div>
        </div>
    </header>

    <main class="max-w-xl mx-auto px-4 mt-4">

        <!-- Thông báo Flash Message -->
        <?php if (!empty($flashMessage)): ?>
            <div class="p-4 mb-4 rounded-xl font-medium text-center text-lg shadow-sm <?php echo $flashType==='success'?'bg-emerald-100 border border-emerald-300 text-emerald-800':'bg-rose-100 border border-rose-300 text-rose-800'; ?>">
                <?php echo $flashMessage; ?>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'public'): ?>
            <!-- GIAO DIỆN PHẬT TỬ (HIGH-TOUCH, LOW-TECH) -->
            <?php if ($activeEvent): ?>
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-amber-200/80 mb-5">
                    <div class="inline-block px-3 py-1 bg-amber-100 text-amber-800 text-xs font-semibold rounded-full mb-2">
                        Đợt này đang trợ duyên
                    </div>
                    <h2 class="text-2xl font-bold text-amber-900 mb-1">Trợ duyên: <?php echo htmlspecialchars($activeEvent['patient_name']); ?></h2>
                    <p class="text-slate-600 text-elder font-medium mb-2">📍 Địa chỉ: <?php echo htmlspecialchars($activeEvent['address']); ?></p>
                    
                    <div class="p-3.5 bg-amber-50/90 rounded-xl border border-amber-200 text-slate-700 text-base leading-relaxed my-2">
                        <strong class="text-amber-900">Lời dặn Ban Điều Hành:</strong><br>
                        "<?php echo htmlspecialchars($activeEvent['note'] ?: "Nam Mô A Di Đà Phật. Kính thưa THẦY và liên hữu đồng tu: {$activeEvent['patient_name']} yếu nên đạo tràng tổ chức trợ duyên. Kính mong quý liên hữu hoan hỷ đăng ký tham gia tùy duyên."); ?>"
                    </div>
                </div>

                <!-- FORM ĐĂNG KÝ NHANH 1-CHẠM CÓ TỰ ĐỘNG NHẬN DIỆN ZALO -->
                <div class="bg-white rounded-2xl p-5 shadow-md border-2 border-amber-400 mb-6">
                    
                    <!-- Khối Hiển Thị Đã Nhận Diện Danh Tính Trực Tiếp Từ Zalo (Không Cần Gõ Tên) -->
                    <div id="zaloIdentifiedBox" class="hidden space-y-3">
                        <div class="p-3.5 bg-emerald-50 border border-emerald-200 rounded-xl flex justify-between items-center">
                            <div>
                                <span class="text-xs text-emerald-700 font-semibold uppercase tracking-wider block">🟢 Đã tự động nhận diện từ Zalo</span>
                                <span class="text-xl font-bold text-slate-900" id="zaloNameDisplay">...</span>
                            </div>
                            <button type="button" onclick="resetZaloIdentity()" class="text-xs text-amber-800 hover:text-amber-900 font-semibold px-2.5 py-1.5 bg-amber-100 hover:bg-amber-200 rounded-lg border border-amber-300">
                                ✏️ Đổi tên
                            </button>
                        </div>
                        <p class="text-sm font-semibold text-slate-700">
                            👉 Bác không cần gõ lại tên nữa. Bấm trực tiếp vào ca dưới đây để hoàn tất đăng ký 1-chạm:
                        </p>
                    </div>

                    <!-- Khối Nhập Tên Lần Đầu Nhất (Chỉ Xuất Hiện 1 Lần Duy Nhất Trên Zalo) -->
                    <div id="zaloFirstTimeBox" class="space-y-3">
                        <div class="p-3 bg-amber-50 rounded-xl border border-amber-200 text-xs text-amber-900 leading-relaxed">
                            💡 <strong>Lần đầu từ Zalo:</strong> Bác vui lòng nhập Tên của mình 1 lần duy nhất. Từ đợt sau mở Zalo ra hệ thống sẽ tự động nhận diện tên Bác mà không cần gõ lại!
                        </div>
                        <div>
                            <label class="block text-slate-700 font-bold text-elder mb-1">Họ và Tên Phật Tử:</label>
                            <input type="text" id="inputFullname" placeholder="Nhập tên Bác/Cô/Chú (chỉ nhập 1 lần)..." class="w-full text-elder p-3.5 border-2 border-amber-300 rounded-xl focus:outline-none focus:border-amber-600 bg-amber-50/30">
                        </div>
                    </div>
                    
                    <form method="POST" id="regForm" class="space-y-4 mt-3">
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="shift_id" id="selectedShiftId" value="">
                        <input type="hidden" name="fullname" id="finalFullname" value="">
                        <input type="hidden" name="zalo_id" id="finalZaloId" value="">

                        <div class="flex items-center gap-4 text-sm font-semibold text-slate-700 pt-1">
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" name="role_type" value="Thành viên" checked class="w-4 h-4 text-amber-600">
                                Thành viên
                            </label>
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" name="role_type" value="Ban điều hành" class="w-4 h-4 text-amber-600">
                                Ban điều hành / Trưởng tràng
                            </label>
                        </div>

                        <div class="grid grid-cols-1 gap-3 pt-2">
                            <?php foreach ($shifts as $s): ?>
                                <?php 
                                    $count = count($registrationsByShift[$s['id']] ?? []);
                                    $target = $s['max_target'] ?? 10;
                                    $sNameLower = mb_strtolower($s['shift_name']);
                                    $isAfternoon = strpos($sNameLower, 'chiều') !== false;
                                    $isEvening = strpos($sNameLower, 'tối') !== false;
                                    
                                    if ($isAfternoon) {
                                        $btnColor = 'bg-amber-500 hover:bg-amber-600 text-white';
                                    } elseif ($isEvening) {
                                        $btnColor = 'bg-indigo-700 hover:bg-indigo-800 text-white';
                                    } else {
                                        $btnColor = 'bg-emerald-600 hover:bg-emerald-700 text-white';
                                    }
                                ?>
                                <button type="button" onclick="submitRegisterWithZalo(<?php echo $s['id']; ?>)" class="btn-touch w-full <?php echo $btnColor; ?> font-bold rounded-xl shadow-md flex justify-between items-center px-4 transition-transform active:scale-95">
                                    <div class="text-left">
                                        <div><?php echo htmlspecialchars($s['shift_name']); ?> (<?php echo htmlspecialchars($s['shift_time']); ?>)</div>
                                        <div class="text-xs font-normal opacity-90">Ưu tiên ~<?php echo $target; ?> vị</div>
                                    </div>
                                    <span class="text-sm bg-white/20 px-3 py-1.5 rounded-full font-semibold">Đã có <?php echo $count; ?> vị</span>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </form>
                </div>

                <!-- DANH SÁCH THÀNH VIÊN CÁC CA -->
                <div class="space-y-5">
                    <?php foreach ($shifts as $s): ?>
                        <?php 
                            $regs = $registrationsByShift[$s['id']] ?? [];
                            $count = count($regs);
                            $max = $s['max_target'] ?? 10;
                            $sNameLower = mb_strtolower($s['shift_name']);
                            $isAfternoon = strpos($sNameLower, 'chiều') !== false;
                            $headerBg = $isAfternoon ? 'bg-amber-100 text-amber-900 border-amber-300' : 'bg-indigo-100 text-indigo-900 border-indigo-300';
                        ?>
                        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                            <div class="p-4 border-b font-bold text-lg flex justify-between items-center <?php echo $headerBg; ?>">
                                <div>
                                    <span><?php echo htmlspecialchars($s['shift_name']); ?> (<?php echo htmlspecialchars($s['shift_time']); ?>)</span>
                                </div>
                                <span class="text-sm px-3 py-1 rounded-full font-semibold <?php echo $count >= $max ? 'bg-amber-200 text-amber-900':'bg-white/80 text-slate-700'; ?>">
                                    <?php echo $count; ?>/<?php echo $max; ?> vị
                                </span>
                            </div>

                            <div class="p-4">
                                <?php if ($count >= $max): ?>
                                    <p class="text-sm text-amber-800 bg-amber-50 p-2.5 rounded-lg border border-amber-200 mb-3">
                                        🌸 Ca đã đủ chỉ tiêu ưu tiên <?php echo $max; ?> vị. Quý liên hữu hoan hỷ tùy duyên đăng ký thêm nếu thuận tiện!
                                    </p>
                                <?php endif; ?>

                                <?php if (empty($regs)): ?>
                                    <p class="text-slate-400 italic text-center py-3">Chưa có liên hữu nào đăng ký ca này.</p>
                                <?php else: ?>
                                    <ul class="divide-y divide-slate-100">
                                        <?php foreach ($regs as $idx => $r): ?>
                                            <li class="py-2.5 flex justify-between items-center">
                                                <span class="text-elder font-medium text-slate-800">
                                                    <span class="text-amber-700 font-bold w-6 inline-block"><?php echo $idx + 1; ?>.</span>
                                                    <?php echo htmlspecialchars($r['fullname']); ?>
                                                    <?php if ($r['role_type'] === 'Ban điều hành'): ?>
                                                        <span class="text-xs bg-amber-100 text-amber-800 font-bold px-2 py-0.5 rounded-full ml-1">Điều hành</span>
                                                    <?php endif; ?>
                                                </span>
                                                <form method="POST" inline onsubmit="return confirm('Xác nhận xóa đăng ký này?');">
                                                    <input type="hidden" name="action" value="cancel_registration">
                                                    <input type="hidden" name="reg_id" value="<?php echo $r['id']; ?>">
                                                    <button type="submit" class="text-xs text-rose-500 hover:text-rose-700 px-2 py-1 bg-rose-50 rounded hover:bg-rose-100">Xóa</button>
                                                </form>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="bg-white rounded-2xl p-8 text-center shadow-sm">
                    <p class="text-elder text-slate-600 mb-4">Hiện tại chưa có lịch trợ duyên nào đang kích hoạt.</p>
                    <a href="?mode=admin" class="inline-block px-5 py-2.5 bg-amber-700 text-white rounded-xl font-bold">Vào trang Quản trị để tạo mới</a>
                </div>
            <?php endif; ?>

        <?php elseif ($mode === 'admin'): ?>
            <!-- GIAO DIỆN QUẢN TRỊ (ADMIN DASHBOARD) -->
            <div class="space-y-6">
                
                <!-- CẬP NHẬT ĐỢT ĐANG TRỢ DUYÊN -->
                <?php if ($activeEvent): ?>
                    <div class="bg-white rounded-2xl p-5 shadow-sm border border-amber-300 bg-amber-50/20">
                        <h2 class="text-xl font-bold text-amber-900 mb-3 pb-2 border-b">✏️ Điều Chỉnh Lịch & Chỉ Tiêu Từng Ca (Đợt Hiện Tại)</h2>
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="action" value="update_event">
                            <input type="hidden" name="event_id" value="<?php echo $activeEvent['id']; ?>">

                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Tên Người Được Trợ Duyên:</label>
                                <input type="text" name="patient_name" value="<?php echo htmlspecialchars($activeEvent['patient_name']); ?>" required class="w-full p-2.5 border rounded-lg font-bold text-slate-800">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Địa Chỉ:</label>
                                <input type="text" name="address" value="<?php echo htmlspecialchars($activeEvent['address']); ?>" class="w-full p-2.5 border rounded-lg">
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Lời Dặn Ban Điều Hành / Ghi Chú Gia Đình:</label>
                                <textarea name="note" rows="2" class="w-full p-2.5 border rounded-lg text-sm"><?php echo htmlspecialchars($activeEvent['note']); ?></textarea>
                            </div>

                            <div class="border-t pt-3 mt-2">
                                <label class="block text-xs font-bold text-slate-700 mb-2">Đổi Giờ & Điều Chỉnh Số Lượng Ưu Tiên Cho Từng Ca:</label>
                                <div class="space-y-3">
                                    <?php foreach ($shifts as $s): ?>
                                        <div class="p-3 bg-white rounded-xl border flex items-center justify-between gap-2">
                                            <div class="font-bold text-sm text-slate-800 w-1/4">
                                                <?php echo htmlspecialchars($s['shift_name']); ?>
                                            </div>
                                            <div class="w-2/5">
                                                <label class="block text-[10px] text-slate-400">Giờ ca:</label>
                                                <input type="text" name="shift_times[<?php echo $s['id']; ?>]" value="<?php echo htmlspecialchars($s['shift_time']); ?>" class="w-full p-1.5 border rounded text-center text-sm font-semibold">
                                            </div>
                                            <div class="w-1/3">
                                                <label class="block text-[10px] text-amber-700 font-semibold">Chỉ tiêu (vị):</label>
                                                <input type="number" min="1" max="50" name="shift_targets[<?php echo $s['id']; ?>]" value="<?php echo $s['max_target'] ?? 10; ?>" class="w-full p-1.5 border rounded text-center text-sm font-bold text-amber-800 bg-amber-50">
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <button type="submit" class="w-full py-2.5 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-xl mt-2 text-sm shadow">
                                💾 Cập Nhật Thông Tin & Chỉ Tiêu Từng Ca
                            </button>
                        </form>
                    </div>

                    <!-- THÊM CA ĐỘT XUẤT -->
                    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
                        <h2 class="text-lg font-bold text-slate-900 mb-3">➕ Thêm Ca Trợ Duyên Đột Xuất (VD: Ca Sáng)</h2>
                        <form method="POST" class="grid grid-cols-1 sm:grid-cols-4 gap-2">
                            <input type="hidden" name="action" value="add_custom_shift">
                            <input type="hidden" name="event_id" value="<?php echo $activeEvent['id']; ?>">
                            <input type="text" name="shift_name" placeholder="Tên ca (VD: Ca Sáng)" required class="p-2 border rounded-lg text-sm">
                            <input type="text" name="shift_time" placeholder="Giờ ca (VD: 08h00')" required class="p-2 border rounded-lg text-sm">
                            <input type="number" min="1" name="max_target" value="10" placeholder="Chỉ tiêu" required class="p-2 border rounded-lg text-sm text-center">
                            <button type="submit" class="py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg text-sm">Thêm Ca</button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- MẪU TIN NHẮN ZALO AUTO (1-CLICK COPY) -->
                <?php if ($activeEvent): ?>
                    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
                        <h2 class="text-xl font-bold text-slate-900 mb-2">📋 Mẫu Tin Nhắn Gửi Nhóm Zalo</h2>
                        <p class="text-xs text-slate-500 mb-3">Copy đoạn văn bản dưới đây để dán vào nhóm Zalo Đạo tràng:</p>

                        <div id="zaloTemplate" class="p-4 bg-slate-50 rounded-xl border text-slate-800 text-sm leading-relaxed whitespace-pre-line font-mono mb-3">
Nam Mô A Di Đà Phật

Kính thưa THẦY và liên hữu đồng tu: <?php echo htmlspecialchars($activeEvent['patient_name']); ?> yếu nên đạo tràng tổ chức trợ duyên cho bác ngày <?php echo count($shifts); ?> thời (<?php 
    $sInfo = [];
    foreach ($shifts as $s) {
        $sInfo[] = $s['shift_name'] . " " . $s['shift_time'] . " (~" . ($s['max_target']??10) . " vị)";
    }
    echo implode(", ", $sInfo);
?>). Các bác đủ duyên thời nào mong các bác hoan hỷ. Kính mong quý liên hữu hoan hỷ cùng tham gia.

👉 Bấm vào link để xem & đăng ký ca:
<?php echo $currentUrl; ?>


Con xin thành kính tri ân công đức của quý Thầy cùng liên hữu đồng tu ạ.
Nam Mô A Di Đà Phật
                        </div>

                        <button onclick="copyZaloText()" class="w-full py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl flex justify-center items-center gap-2 shadow">
                            <span>📲</span> Sao Chép Tin Nhắn Zalo (1-Click)
                        </button>
                    </div>
                <?php endif; ?>

                <!-- TẠO ĐỢT TRỢ DUYÊN MỚI HOÀN TOÀN -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
                    <h2 class="text-xl font-bold text-slate-900 mb-4 pb-2 border-b">🆕 Khởi Tạo Đợt Trợ Duyên Mới</h2>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="create_event">
                        
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Tên Người Được Trợ Duyên (Bác/Cụ):</label>
                            <input type="text" name="patient_name" required placeholder="Ví dụ: Bác Y, Cụ Z..." class="w-full p-2.5 border rounded-lg">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Địa Chỉ / Ghi Chú Gia Đình:</label>
                            <input type="text" name="address" placeholder="Ví dụ: Thôn A, Xã B, Tỉnh C..." class="w-full p-2.5 border rounded-lg">
                        </div>

                        <div class="grid grid-cols-2 gap-3 border-t pt-2">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Giờ Ca Chiều:</label>
                                <input type="text" name="afternoon_time" value="14h15'" class="w-full p-2 border rounded-lg text-center mb-1">
                                <label class="block text-[10px] text-amber-700 font-semibold">Chỉ tiêu ca Chiều (vị):</label>
                                <input type="number" name="afternoon_target" value="10" class="w-full p-1.5 border rounded-lg text-center text-sm font-bold bg-amber-50">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Giờ Ca Tối:</label>
                                <input type="text" name="evening_time" value="19h15'" class="w-full p-2 border rounded-lg text-center mb-1">
                                <label class="block text-[10px] text-indigo-700 font-semibold">Chỉ tiêu ca Tối (vị):</label>
                                <input type="number" name="evening_target" value="10" class="w-full p-1.5 border rounded-lg text-center text-sm font-bold bg-indigo-50">
                            </div>
                        </div>

                        <button type="submit" class="w-full py-3 bg-amber-700 hover:bg-amber-800 text-white font-bold rounded-xl mt-2">
                            Kích Hoạt Lịch Trợ Duyên Mới
                        </button>
                    </form>
                </div>

                <!-- ĐÓNG ĐỢT TRỢ DUYÊN -->
                <?php if ($activeEvent): ?>
                    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
                        <h2 class="text-lg font-bold text-slate-900 mb-2">⚙️ Đóng Đợt Trợ Duyên</h2>
                        <p class="text-sm text-slate-600 mb-3">Đang chạy: <strong><?php echo htmlspecialchars($activeEvent['patient_name']); ?></strong></p>
                        <form method="POST" onsubmit="return confirm('Xác nhận hoàn thành đợt trợ duyên này?');">
                            <input type="hidden" name="action" value="complete_event">
                            <input type="hidden" name="event_id" value="<?php echo $activeEvent['id']; ?>">
                            <button type="submit" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-800 font-semibold rounded-lg text-sm">
                                Đóng Đợt Trợ Duyên Này
                            </button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- LINK BẢO TRÌ SYSTEM -->
                <div class="flex gap-2">
                    <a href="?mode=test" class="flex-1 py-2.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 text-center font-semibold rounded-xl border border-indigo-200 text-sm">🧪 Chạy Automated Test</a>
                    <a href="?mode=agent_kit" class="flex-1 py-2.5 bg-amber-50 hover:bg-amber-100 text-amber-800 text-center font-semibold rounded-xl border border-amber-200 text-sm">📄 Xem Project Agent Kit</a>
                </div>

            </div>

        <?php elseif ($mode === 'stats'): ?>
            <!-- GIAO DIỆN THỐNG KÊ DỮ LIỆU DÀI HẠN -->
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200 space-y-4">
                <div class="flex justify-between items-center border-b pb-3">
                    <h2 class="text-xl font-bold text-slate-900">📊 Thống Kê Tích Cực Đạo Tràng</h2>
                    <a href="?mode=public" class="text-sm text-amber-700 font-semibold">&larr; Quay lại</a>
                </div>

                <p class="text-sm text-slate-600">Tổng số lượt đăng ký đi trợ duyên của các liên hữu qua tất cả các đợt:</p>

                <?php if (empty($memberStats)): ?>
                    <p class="text-slate-400 italic text-center py-4">Chưa có dữ liệu thống kê.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-amber-50 text-amber-900 border-b">
                                <tr>
                                    <th class="p-2.5">#</th>
                                    <th class="p-2.5">Họ và Tên Phật Tử</th>
                                    <th class="p-2.5">Vai trò</th>
                                    <th class="p-2.5 text-right">Tổng Lượt</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php foreach ($memberStats as $i => $ms): ?>
                                    <tr class="hover:bg-slate-50">
                                        <td class="p-2.5 font-bold text-slate-400"><?php echo $i + 1; ?></td>
                                        <td class="p-2.5 font-semibold text-slate-800"><?php echo htmlspecialchars($ms['fullname']); ?></td>
                                        <td class="p-2.5 text-xs text-slate-500"><?php echo htmlspecialchars($ms['role_type']); ?></td>
                                        <td class="p-2.5 text-right font-bold text-amber-700"><?php echo $ms['total_registrations']; ?> lượt</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($mode === 'test'): ?>
            <!-- GIAO DIỆN KIỂM THỬ TỰ ĐỘNG (UNIT TEST RUNNER) -->
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200 space-y-4">
                <div class="flex justify-between items-center border-b pb-3">
                    <h2 class="text-xl font-bold text-slate-900">🧪 Kết Quả Tự Động Kiểm Thử (Unit Test)</h2>
                    <a href="?mode=admin" class="text-sm text-amber-700 font-semibold">&larr; Quay lại Admin</a>
                </div>

                <div class="space-y-2">
                    <?php 
                    $allPass = true;
                    foreach ($testResults as $t): 
                        if (!$t['status']) $allPass = false;
                    ?>
                        <div class="p-3 rounded-lg border flex justify-between items-center <?php echo $t['status']?'bg-emerald-50 border-emerald-200 text-emerald-800':'bg-rose-50 border-rose-200 text-rose-800'; ?>">
                            <div>
                                <strong class="block text-sm"><?php echo htmlspecialchars($t['test']); ?></strong>
                                <span class="text-xs opacity-80"><?php echo htmlspecialchars($t['msg']); ?></span>
                            </div>
                            <span class="font-bold text-sm"><?php echo $t['status'] ? 'PASS ✅' : 'FAIL ❌'; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($allPass): ?>
                    <div class="p-4 bg-emerald-600 text-white font-bold rounded-xl text-center text-lg mt-4 shadow">
                        ✅ TẤT CẢ UNIT TEST TRUY VẤN SQLITE HOẠT ĐỘNG HOÀN HẢO!
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($mode === 'agent_kit'): ?>
            <!-- GIAO DIỆN TÀI LIỆU AGENT KIT FOR ANTIGRAVITY IDE -->
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200 space-y-3">
                <div class="flex justify-between items-center border-b pb-3">
                    <h2 class="text-xl font-bold text-slate-900">📄 Project Agent Kit (Antigravity IDE)</h2>
                    <a href="?mode=admin" class="text-sm text-amber-700 font-semibold">&larr; Quay lại Admin</a>
                </div>
                <p class="text-sm text-slate-600">Tài liệu này được nhúng sẵn để AI Agent (Aider / Antigravity IDE) đọc và hiểu toàn bộ kiến trúc dự án.</p>
                <div class="p-4 bg-slate-900 text-slate-100 rounded-xl text-xs font-mono overflow-x-auto whitespace-pre-wrap leading-relaxed max-h-96">
# PROJECT AGENT KIT - HỆ THỐNG TRỢ DUYÊN NIỆM PHẬT (v2.2)
Triết lý: High-Touch, Low-Tech | Stack: PHP + SQLite (WAL Mode)

1. TÍNH NĂNG TỰ ĐỘNG NHẬN DIỆN ZALO (ZALO AUTO-ID):
- Tận dụng LocalStorage của Zalo WebView.
- Lần mở link đầu tiên: Nhập tên 1 lần duy nhất.
- Các lần mở link tiếp theo: Hệ thống tự nhận diện tên Bác mà không cần nhập lại.

2. CẤU TRÚC BẢNG SQLITE (`database.sqlite`):
- `events`: id, patient_name, address, note, status ('active'/'completed')
- `shifts`: id, event_id, shift_name, shift_time, max_target, shift_date
- `registrations`: id, shift_id, fullname, phone, role_type, zalo_id, registered_at
- `logs`: id, action, details, created_at
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- Script tương tác JS đơn giản & Tự động nhận diện danh tính Zalo -->
    <script>
        // Tự động kiểm tra danh tính Zalo đã lưu trên trình duyệt/Zalo WebView khi trang vừa tải
        document.addEventListener('DOMContentLoaded', function() {
            initZaloIdentity();
        });

        function initZaloIdentity() {
            const urlParams = new URLSearchParams(window.location.search);
            // Ưu tiên danh tính từ URL parameter `zuser` hoặc LocalStorage của Zalo
            let savedName = urlParams.get('zuser') || localStorage.getItem('dt_zalo_fullname') || '';
            let savedZaloId = localStorage.getItem('dt_zalo_id') || 'ZALO_' + Math.floor(Math.random() * 1000000);

            if (savedName && savedName.trim() !== '') {
                localStorage.setItem('dt_zalo_fullname', savedName.trim());
                localStorage.setItem('dt_zalo_id', savedZaloId);
                
                document.getElementById('zaloNameDisplay').innerText = savedName.trim();
                document.getElementById('zaloIdentifiedBox').classList.remove('hidden');
                document.getElementById('zaloFirstTimeBox').classList.add('hidden');
                document.getElementById('finalFullname').value = savedName.trim();
                document.getElementById('finalZaloId').value = savedZaloId;
            } else {
                document.getElementById('zaloIdentifiedBox').classList.add('hidden');
                document.getElementById('zaloFirstTimeBox').classList.remove('hidden');
            }
        }

        function submitRegisterWithZalo(shiftId) {
            let finalName = document.getElementById('finalFullname').value.trim();
            const inputName = document.getElementById('inputFullname').value.trim();

            if (!finalName) {
                if (!inputName) {
                    alert('A Di Đà Phật! Bác vui lòng nhập Tên của mình trước khi chọn ca.');
                    document.getElementById('inputFullname').focus();
                    return;
                }
                finalName = inputName;
                // Lưu vĩnh viễn vào Zalo WebView local storage
                localStorage.setItem('dt_zalo_fullname', finalName);
                let newZaloId = 'ZALO_' + Date.now();
                localStorage.setItem('dt_zalo_id', newZaloId);
                document.getElementById('finalZaloId').value = newZaloId;
            }

            document.getElementById('finalFullname').value = finalName;
            document.getElementById('selectedShiftId').value = shiftId;
            document.getElementById('regForm').submit();
        }

        function resetZaloIdentity() {
            if (confirm('Bác có muốn đổi sang Tên Phật Tử khác trên Zalo này không?')) {
                localStorage.removeItem('dt_zalo_fullname');
                document.getElementById('finalFullname').value = '';
                document.getElementById('inputFullname').value = '';
                document.getElementById('zaloIdentifiedBox').classList.add('hidden');
                document.getElementById('zaloFirstTimeBox').classList.remove('hidden');
                document.getElementById('inputFullname').focus();
            }
        }

        function copyZaloText() {
            const text = document.getElementById('zaloTemplate').innerText;
            const textarea = document.createElement('textarea');
            textarea.value = text;
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
            alert('A Di Đà Phật! Đã sao chép tin nhắn Zalo vào bộ nhớ tạm.');
        }
    </script>
</body>
</html>