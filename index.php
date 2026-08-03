<?php
/**
 * HỆ THỐNG SẮP LỊCH TRỢ DUYÊN NIỆM PHẬT - BAN HỘ NIỆM / TRỢ NIỆM
 * Phiên bản: 2.0 Complete (Đáp ứng 100% Yêu cầu Nghiên cứu & Bảo trì)
 * Triết lý thiết kế: High-Touch, Low-Tech (Tối giản 1-chạm, chữ to, không cần đăng nhập)
 * Lưu trữ: SQLite (database.sqlite)
 */

// 1. CẤU HÌNH & KẾT NỐI CƠ SỞ DỮ LIỆU SQLITE
$dbFile = __DIR__ . '/database.sqlite';
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Lỗi kết nối CSDL SQLite: " . $e->getMessage());
}

// Tự động khởi tạo cấu trúc Bảng CSDL nếu chưa có
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

// Hàm ghi Nhật ký Hệ thống (Logs)
function logAction($pdo, $action, $details = '') {
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (action, details) VALUES (?, ?)");
        $stmt->execute([$action, $details]);
    } catch (Exception $e) {
        // Bỏ qua lỗi ghi log nếu có
    }
}

// Chèn dữ liệu mẫu nếu DB hoàn toàn trống
$stmtCheck = $pdo->query("SELECT COUNT(*) as cnt FROM events");
if ($stmtCheck->fetch()['cnt'] == 0) {
    $pdo->exec("INSERT INTO events (patient_name, address, note, status) VALUES 
        ('Bác X', 'Nhà riêng Bác X (Gia đình chuẩn bị trang nghiêm)', 'Nhà chật, ưu tiên khoảng 10 vị/ca, hoan hỷ tùy duyên.', 'active')");
    $eventId = $pdo->lastInsertId();
    $today = date('Y-m-d');
    $pdo->exec("INSERT INTO shifts (event_id, shift_name, shift_time, max_target, shift_date) VALUES 
        ($eventId, 'Ca Chiều', '14h15\'', 10, '$today'),
        ($eventId, 'Ca Tối', '19h15\'', 10, '$today')");
    logAction($pdo, 'INIT_DATABASE', 'Khởi tạo dữ liệu mẫu thành công');
}

// 2. XỬ LÝ ACTION TỪ FORM (POST)
$flashMessage = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Đăng ký ca trợ duyên
    if ($action === 'register') {
        $shiftId = intval($_POST['shift_id'] ?? 0);
        $fullname = trim($_POST['fullname'] ?? '');
        $roleType = trim($_POST['role_type'] ?? 'Thành viên');
        
        if (!empty($fullname) && $shiftId > 0) {
            $stmt = $pdo->prepare("INSERT INTO registrations (shift_id, fullname, role_type) VALUES (?, ?, ?)");
            $stmt->execute([$shiftId, $fullname, $roleType]);
            logAction($pdo, 'REGISTER', "Phật tử $fullname đăng ký ca ID $shiftId ($roleType)");
            $flashMessage = "A Di Đà Phật! Đã ghi nhận Phật tử [ " . htmlspecialchars($fullname) . " ] đăng ký thành công.";
        } else {
            $flashMessage = "Vui lòng nhập Họ và Tên trước khi chọn ca.";
            $flashType = 'error';
        }
    }

    // Hủy đăng ký
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

    // Admin: Tạo sự kiện mới
    if ($action === 'create_event') {
        $patientName = trim($_POST['patient_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $afternoonTime = trim($_POST['afternoon_time'] ?? '14h15\'');
        $eveningTime = trim($_POST['evening_time'] ?? '19h15\'');
        
        if (!empty($patientName)) {
            $pdo->exec("UPDATE events SET status = 'completed' WHERE status = 'active'");
            
            $stmt = $pdo->prepare("INSERT INTO events (patient_name, address, note, status) VALUES (?, ?, ?, 'active')");
            $stmt->execute([$patientName, $address, $note]);
            $newEventId = $pdo->lastInsertId();
            
            $today = date('Y-m-d');
            $stmtShift = $pdo->prepare("INSERT INTO shifts (event_id, shift_name, shift_time, max_target, shift_date) VALUES (?, ?, ?, 10, ?)");
            $stmtShift->execute([$newEventId, 'Ca Chiều', $afternoonTime, $today]);
            $stmtShift->execute([$newEventId, 'Ca Tối', $eveningTime, $today]);
            
            logAction($pdo, 'CREATE_EVENT', "Tạo đợt trợ duyên mới: $patientName");
            $flashMessage = "A Di Đà Phật! Đã khởi tạo đợt trợ duyên mới cho " . htmlspecialchars($patientName);
        }
    }

    // Admin: Cập nhật thông tin sự kiện & Thời gian các ca (Thay đổi lịch linh hoạt)
    if ($action === 'update_event') {
        $eventId = intval($_POST['event_id'] ?? 0);
        $patientName = trim($_POST['patient_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $note = trim($_POST['note'] ?? '');
        
        if ($eventId > 0 && !empty($patientName)) {
            $stmt = $pdo->prepare("UPDATE events SET patient_name = ?, address = ?, note = ? WHERE id = ?");
            $stmt->execute([$patientName, $address, $note, $eventId]);

            // Cập nhật giờ ca nếu có
            if (isset($_POST['shift_times']) && is_array($_POST['shift_times'])) {
                foreach ($_POST['shift_times'] as $shiftId => $sTime) {
                    $stmtS = $pdo->prepare("UPDATE shifts SET shift_time = ? WHERE id = ? AND event_id = ?");
                    $stmtS->execute([trim($sTime), intval($shiftId), $eventId]);
                }
            }
            
            logAction($pdo, 'UPDATE_EVENT', "Cập nhật thông tin/lịch ca đợt ID $eventId");
            $flashMessage = "A Di Đà Phật! Đã cập nhật thành công thông tin & thời gian ca trợ duyên.";
        }
    }

    // Admin: Thêm ca trợ duyên đột xuất (Ví dụ: Ca Sáng, Ca Đột Xuất)
    if ($action === 'add_custom_shift') {
        $eventId = intval($_POST['event_id'] ?? 0);
        $shiftName = trim($_POST['shift_name'] ?? '');
        $shiftTime = trim($_POST['shift_time'] ?? '');
        $maxTarget = intval($_POST['max_target'] ?? 10);
        
        if ($eventId > 0 && !empty($shiftName) && !empty($shiftTime)) {
            $stmt = $pdo->prepare("INSERT INTO shifts (event_id, shift_name, shift_time, max_target, shift_date) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$eventId, $shiftName, $shiftTime, $maxTarget, date('Y-m-d')]);
            logAction($pdo, 'ADD_SHIFT', "Thêm ca mới [$shiftName - $shiftTime] vào đợt ID $eventId");
            $flashMessage = "Đã thêm ca trợ duyên mới thành công.";
        }
    }

    // Admin: Hoàn thành sự kiện
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

// Lấy danh sách thống kê tổng số lượt tham gia của từng Phật tử
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
        // Test 1: SQLite Connection
        $testResults[] = ["test" => "Kiểm tra kết nối SQLite PDO", "status" => true, "msg" => "Kết nối thành công database.sqlite"];
        
        // Test 2: Ghi nhận logs
        logAction($pdo, 'TEST_RUNNER', 'Chạy tự động Unit Test');
        $stmtLogCheck = $pdo->query("SELECT COUNT(*) as cnt FROM logs WHERE action = 'TEST_RUNNER'");
        $testResults[] = ["test" => "Ghi nhật ký hệ thống (Logs Table)", "status" => $stmtLogCheck->fetch()['cnt'] > 0, "msg" => "Ghi log thao tác thành công"];

        // Test 3: Insert dummy registration
        if (!empty($shifts)) {
            $testShiftId = $shifts[0]['id'];
            $pdo->prepare("INSERT INTO registrations (shift_id, fullname, role_type) VALUES (?, ?, ?)")->execute([$testShiftId, "Test_Phật_Tử_Kiểm_Thử", "Thành viên"]);
            $dummyId = $pdo->lastInsertId();
            $testResults[] = ["test" => "Thêm lượt đăng ký giả lập", "status" => true, "msg" => "Chèn ID: $dummyId thành công"];
            
            // Test 4: Delete dummy registration
            $pdo->prepare("DELETE FROM registrations WHERE id = ?")->execute([$dummyId]);
            $testResults[] = ["test" => "Xóa lượt đăng ký kiểm thử", "status" => true, "msg" => "Xóa dữ liệu test thành công"];
        }
        
        // Test 5: Query all 4 tables
        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        $has4Tables = in_array('events', $tables) && in_array('shifts', $tables) && in_array('registrations', $tables) && in_array('logs', $tables);
        $testResults[] = ["test" => "Cấu trúc 4 Bảng CSDL SQLite", "status" => $has4Tables, "msg" => "Tìm thấy các bảng: " . implode(", ", $tables)];
    } catch (Exception $ex) {
        $testResults[] = ["test" => "Lỗi Kiểm Thử", "status" => false, "msg" => $ex->getMessage()];
    }
}

// Đơn vị đường dẫn hiện tại
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
        .btn-touch { min-height: 56px; font-size: 1.25rem; }
    </style>
</head>
<body class="bg-amber-50 text-slate-800 min-h-screen pb-12">

    <!-- Header Trang -->
    <header class="bg-amber-700 text-amber-50 shadow-md py-3.5 px-4 sticky top-0 z-50">
        <div class="max-w-xl mx-auto flex justify-between items-center">
            <div>
                <h1 class="text-lg sm:text-xl font-bold tracking-wide">NAM MÔ A DI ĐÀ PHẬT</h1>
                <p class="text-xs text-amber-200">Ban Hộ Niệm / Trợ Niệm Phật Giáo</p>
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
                        Thời gian này đang trợ duyên
                    </div>
                    <h2 class="text-2xl font-bold text-amber-900 mb-1">Trợ duyên: <?php echo htmlspecialchars($activeEvent['patient_name']); ?></h2>
                    <p class="text-slate-600 text-elder font-medium mb-2">📍 Địa chỉ: <?php echo htmlspecialchars($activeEvent['address']); ?></p>
                    
                    <div class="p-3.5 bg-amber-50/90 rounded-xl border border-amber-200 text-slate-700 text-base leading-relaxed my-2">
                        <strong class="text-amber-900">Lời dặn Ban Điều Hành:</strong><br>
                        "<?php echo htmlspecialchars($activeEvent['note'] ?: "Nam Mô A Di Đà Phật. Kính thưa THẦY và liên hữu đồng tu: {$activeEvent['patient_name']} yếu nên đạo tràng tổ chức trợ duyên ngày 2 thời. Kính mong quý liên hữu hoan hỷ đăng ký tham gia tùy duyên."); ?>"
                    </div>
                </div>

                <!-- FORM ĐĂNG KÝ NHANH 1-CHẠM -->
                <div class="bg-white rounded-2xl p-5 shadow-md border border-amber-300 mb-6">
                    <h3 class="text-xl font-bold text-slate-900 mb-3 flex items-center gap-2">
                        <span>✍️</span> Đăng Ký Trực Tiếp (Không cần mật khẩu)
                    </h3>
                    
                    <form method="POST" id="regForm" class="space-y-4">
                        <input type="hidden" name="action" value="register">
                        <input type="hidden" name="shift_id" id="selectedShiftId" value="">

                        <div>
                            <label class="block text-slate-700 font-bold text-elder mb-1">Họ và Tên Phật Tử:</label>
                            <input type="text" name="fullname" required placeholder="Nhập tên của Bác/Cô/Chú..." class="w-full text-elder p-3.5 border-2 border-amber-300 rounded-xl focus:outline-none focus:border-amber-600 bg-amber-50/30">
                        </div>

                        <div class="flex items-center gap-4 text-sm font-semibold text-slate-700">
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" name="role_type" value="Thành viên" checked class="w-4 h-4 text-amber-600">
                                Thành viên
                            </label>
                            <label class="flex items-center gap-1.5 cursor-pointer">
                                <input type="radio" name="role_type" value="Ban điều hành" class="w-4 h-4 text-amber-600">
                                Ban điều hành / Trưởng tràng
                            </label>
                        </div>

                        <p class="text-sm text-slate-500 font-medium">Bấm đúng vào ca mong muốn để hoàn tất đăng ký:</p>

                        <div class="grid grid-cols-1 gap-3">
                            <?php foreach ($shifts as $s): ?>
                                <?php 
                                    $count = count($registrationsByShift[$s['id']] ?? []);
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
                                <button type="button" onclick="submitRegister(<?php echo $s['id']; ?>)" class="btn-touch w-full <?php echo $btnColor; ?> font-bold rounded-xl shadow-md flex justify-between items-center px-5 transition-transform active:scale-95">
                                    <span><?php echo htmlspecialchars($s['shift_name']); ?> (<?php echo htmlspecialchars($s['shift_time']); ?>)</span>
                                    <span class="text-sm bg-white/20 px-3 py-1 rounded-full font-normal">Đã có <?php echo $count; ?> vị</span>
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
                            $max = $s['max_target'];
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
                                        🌸 Ca đã đủ chỉ tiêu <?php echo $max; ?> vị. Quý liên hữu tùy duyên đăng ký thêm nếu thuận tiện!
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
                
                <!-- CHỈNH SỬA / CẬP NHẬT ĐỢT TRỢ DUYÊN HIỆN TẠI (THAY ĐỔI LINH HOẠT) -->
                <?php if ($activeEvent): ?>
                    <div class="bg-white rounded-2xl p-5 shadow-sm border border-amber-300 bg-amber-50/20">
                        <h2 class="text-xl font-bold text-amber-900 mb-3 pb-2 border-b">✏️ Cập Nhật Đợt Đang Trợ Duyên (Thay Đổi Giờ / Ghi Chú)</h2>
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
                                <label class="block text-xs font-bold text-slate-700 mb-2">Đổi Giờ Các Ca Hiện Tại (Giữ Nguyên Danh Sách Đã Đăng Ký):</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <?php foreach ($shifts as $s): ?>
                                        <div>
                                            <label class="block text-xs text-slate-500 mb-1"><?php echo htmlspecialchars($s['shift_name']); ?>:</label>
                                            <input type="text" name="shift_times[<?php echo $s['id']; ?>]" value="<?php echo htmlspecialchars($s['shift_time']); ?>" class="w-full p-2 border rounded-lg text-center font-semibold">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <button type="submit" class="w-full py-2.5 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-xl mt-2 text-sm shadow">
                                💾 Cập Nhật Thông Tin & Lịch Trực
                            </button>
                        </form>
                    </div>

                    <!-- THÊM CA ĐỘT XUẤT -->
                    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
                        <h2 class="text-lg font-bold text-slate-900 mb-3">➕ Thêm Ca Trợ Duyên Đột Xuất (VD: Ca Sáng / Đột Xuất)</h2>
                        <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                            <input type="hidden" name="action" value="add_custom_shift">
                            <input type="hidden" name="event_id" value="<?php echo $activeEvent['id']; ?>">
                            <input type="text" name="shift_name" placeholder="Tên ca (VD: Ca Sáng)" required class="p-2 border rounded-lg text-sm">
                            <input type="text" name="shift_time" placeholder="Giờ ca (VD: 08h00')" required class="p-2 border rounded-lg text-sm">
                            <button type="submit" class="py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg text-sm">Thêm Ca Mới</button>
                        </form>
                    </div>
                <?php endif; ?>

                <!-- TẠO MẪU TIN NHẮN ZALO (1-CLICK COPY) -->
                <?php if ($activeEvent): ?>
                    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
                        <h2 class="text-xl font-bold text-slate-900 mb-2">📋 Mẫu Tin Nhắn Gửi Nhóm Zalo</h2>
                        <p class="text-xs text-slate-500 mb-3">Copy đoạn văn bản dưới đây để dán vào nhóm Zalo Đạo tràng:</p>

                        <div id="zaloTemplate" class="p-4 bg-slate-50 rounded-xl border text-slate-800 text-sm leading-relaxed whitespace-pre-line font-mono mb-3">
Nam Mô A Di Đà Phật

Kính thưa THẦY và liên hữu đồng tu: <?php echo htmlspecialchars($activeEvent['patient_name']); ?> yếu nên đạo tràng tổ chức trợ duyên cho bác ngày <?php echo count($shifts); ?> thời (<?php 
    $sInfo = [];
    foreach ($shifts as $s) {
        $sInfo[] = $s['shift_name'] . " " . $s['shift_time'];
    }
    echo implode(", ", $sInfo);
?>). Các bác đủ duyên thời nào mong các bác hoan hỷ. Vì nhà <?php echo htmlspecialchars($activeEvent['patient_name']); ?> chật nên mỗi ca khoảng tầm 10 người và còn gia đình cũng đông, kính mong quý liên hữu hoan hỷ cùng tham gia.

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

                <!-- TẠO SỰ KIỆN MỚI HOÀN TOÀN -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
                    <h2 class="text-xl font-bold text-slate-900 mb-4 pb-2 border-b">🆕 Khởi Tạo Đợt Trợ Duyên Mới Cho Người Khác</h2>
                    <form method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="create_event">
                        
                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Tên Người Được Trợ Duyên (Bác/Cụ):</label>
                            <input type="text" name="patient_name" required placeholder="Ví dụ: Bác Y, Cụ Z..." class="w-full p-2.5 border rounded-lg">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-slate-700 mb-1">Địa Chỉ / Ghi Chú:</label>
                            <input type="text" name="address" placeholder="Ví dụ: Thôn A, Xã B, Tỉnh C..." class="w-full p-2.5 border rounded-lg">
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Giờ Ca Chiều:</label>
                                <input type="text" name="afternoon_time" value="14h15'" class="w-full p-2 border rounded-lg text-center">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-600 mb-1">Giờ Ca Tối:</label>
                                <input type="text" name="evening_time" value="19h15'" class="w-full p-2 border rounded-lg text-center">
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
                        <h2 class="text-lg font-bold text-slate-900 mb-2">⚙️ Trạng Thái Trợ Duyên</h2>
                        <p class="text-sm text-slate-600 mb-3">Đang trợ duyên: <strong><?php echo htmlspecialchars($activeEvent['patient_name']); ?></strong></p>
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
                                    <th class="p-2.5 text-right">Tổng Lượt Tham Gia</th>
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
# PROJECT AGENT KIT - HỆ THỐNG TRỢ DUYÊN NIỆM PHẬT
Triết lý: High-Touch, Low-Tech | Stack: PHP + SQLite

1. CẤU TRÚC BẢNG SQLITE (`database.sqlite`):
- `events`: id, patient_name, address, note, status ('active'/'completed')
- `shifts`: id, event_id, shift_name, shift_time, max_target (mặc định 10)
- `registrations`: id, shift_id, fullname, phone, role_type, registered_at
- `logs`: id, action, details, created_at

2. TÍNH NĂNG HOÀN THIỆN 100%:
- Sửa đổi lịch trực linh hoạt không làm mất danh sách người đã đăng ký.
- Thêm ca đột xuất (Ca Sáng, Ca Chiều, Ca Tối).
- Thống kê dữ liệu lượt tham gia dài hạn cho Đạo tràng.
- Ghi nhật ký hệ thống `logs` cho mọi thao tác.
                </div>
            </div>
        <?php endif; ?>

    </main>

    <!-- Script tương tác JS đơn giản -->
    <script>
        function submitRegister(shiftId) {
            document.getElementById('selectedShiftId').value = shiftId;
            document.getElementById('regForm').submit();
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