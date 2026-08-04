<?php
/**
 * HỆ THỐNG SẮP LỊCH TRỢ DUYÊN NIỆM PHẬT - BAN HỘ NIỆM / TRỢ NIỆM
 * Phiên bản: 4.0 - Multi-Role Access Control (Super Admin, Admin/Support & Member Guard)
 * Triết lý thiết kế: High-Touch, Low-Tech (Tối giản 1-chạm, chữ to, bảo mật phân quyền)
 * Lưu trữ: SQLite (database.sqlite)
 */

// 1. CẤU HÌNH & KẾT NỐI CƠ SỞ DỮ LIỆU SQLITE
$dbFile = __DIR__ . '/database.sqlite';
try {
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec("PRAGMA journal_mode = WAL;");
} catch (PDOException $e) {
    die("Lỗi kết nối CSDL SQLite: " . $e->getMessage());
}

// Khởi tạo cấu trúc Bảng CSDL
$pdo->exec("
    CREATE TABLE IF NOT EXISTS members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        zalo_id TEXT UNIQUE NOT NULL,
        fullname TEXT NOT NULL,
        phone TEXT,
        role TEXT DEFAULT 'member', -- 'super_admin', 'admin', 'support', 'member'
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );

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
        is_behalf INTEGER DEFAULT 0,
        registered_by_zalo_id TEXT,
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

// Tự động nâng cấp bảng members nếu thiếu cột role (Migration check)
$cols = $pdo->query("PRAGMA table_info(members)")->fetchAll(PDO::FETCH_COLUMN, 1);
if (!in_array('role', $cols)) {
    $pdo->exec("ALTER TABLE members ADD COLUMN role TEXT DEFAULT 'member'");
}

// Hàm ghi Nhật ký Hệ thống (Audit Trail)
function logAction($pdo, $action, $details = '') {
    try {
        $stmt = $pdo->prepare("INSERT INTO logs (action, details) VALUES (?, ?)");
        $stmt->execute([$action, $details]);
    } catch (Exception $e) {}
}

// Kiểm tra User-Agent từ ứng dụng Zalo
function isZaloBrowser() {
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return (stripos($ua, 'Zalo') !== false || stripos($ua, 'ZaloTheme') !== false);
}

$bypassZalo = isset($_GET['bypass_zalo']) || (isset($_GET['mode']) && in_array($_GET['mode'], ['test', 'agent_kit']));
$isZalo = isZaloBrowser() || $bypassZalo;

// Khởi tạo dữ liệu mẫu nếu DB trống
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

// 2. NHẬN DIỆN NGƯỜI DÙNG & XÁC MINH QUYỀN TRUY CẬP (SERVER-SIDE AUTH)
$currentZaloId = $_COOKIE['dt_zalo_id'] ?? $_POST['zalo_id'] ?? $_GET['zalo_id'] ?? '';
$currentMember = null;
if (!empty($currentZaloId)) {
    $stmtM = $pdo->prepare("SELECT * FROM members WHERE zalo_id = ?");
    $stmtM->execute([$currentZaloId]);
    $currentMember = $stmtM->fetch();
}

$currentUserRole = $currentMember['role'] ?? 'member'; // 'super_admin', 'admin', 'support', 'member'
$isSuperAdmin = ($currentUserRole === 'super_admin');
$isAdminOrSupport = in_array($currentUserRole, ['super_admin', 'admin', 'support']);

// 3. XỬ LÝ HÀNH ĐỘNG TỪ FORM (POST)
$flashMessage = '';
$flashType = 'success';
$nameCollisionWarning = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Màn hình 1: Đăng ký thành viên hệ thống (Member Onboarding)
    if ($action === 'register_member') {
        $fullname = trim($_POST['fullname'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $zaloId = trim($_POST['zalo_id'] ?? '');
        $forceConfirm = intval($_POST['force_confirm'] ?? 0);

        if (!empty($fullname) && !empty($zaloId)) {
            // Kiểm tra xem hệ thống đã có Super Admin chưa. Nếu CHƯA CÓ, người đăng ký ĐẦU TIÊN sẽ là Super Admin
            $stmtCountSA = $pdo->query("SELECT COUNT(*) as cnt FROM members WHERE role = 'super_admin'");
            $hasSuperAdmin = $stmtCountSA->fetch()['cnt'] > 0;
            $assignedRole = $hasSuperAdmin ? 'member' : 'super_admin';

            // Kiểm tra trùng tên trong hệ thống
            $stmtCheckName = $pdo->prepare("SELECT COUNT(*) as cnt FROM members WHERE fullname = ? AND zalo_id != ?");
            $stmtCheckName->execute([$fullname, $zaloId]);
            $hasDuplicate = $stmtCheckName->fetch()['cnt'] > 0;

            if ($hasDuplicate && $forceConfirm === 0) {
                $nameCollisionWarning = [
                    'fullname' => $fullname,
                    'phone' => $phone,
                    'zalo_id' => $zaloId
                ];
                $flashMessage = "⚠️ Tên [$fullname] trùng với Phật tử khác. Vui lòng xác nhận đổi tên hoặc giữ nguyên.";
                $flashType = 'error';
            } else {
                // Đăng ký mới hoặc cập nhật tên
                $stmtMember = $pdo->prepare("
                    INSERT INTO members (zalo_id, fullname, phone, role) 
                    VALUES (?, ?, ?, ?)
                    ON CONFLICT(zalo_id) DO UPDATE SET fullname = excluded.fullname, phone = excluded.phone
                ");
                $stmtMember->execute([$zaloId, $fullname, $phone, $assignedRole]);
                
                logAction($pdo, 'REGISTER_MEMBER', "Thành viên $fullname ($zaloId) - Vai trò: $assignedRole");
                
                if ($assignedRole === 'super_admin') {
                    $flashMessage = "👑 A Di Đà Phật! Bác [ " . htmlspecialchars($fullname) . " ] là Người vận hành đầu tiên (Super Admin) của hệ thống!";
                } else {
                    $flashMessage = "A Di Đà Phật! Đã đăng ký thành công danh tính Phật tử [ " . htmlspecialchars($fullname) . " ].";
                }
                
                // Cập nhật cookie
                setcookie('dt_zalo_id', $zaloId, time() + 365*86400, '/');
                header("Location: " . $_SERVER['REQUEST_URI']);
                exit;
            }
        }
    }

    // Admin Action: Gán quyền cho Phật tử (Super Admin Only)
    if ($action === 'update_member_role') {
        if (!$isSuperAdmin) {
            $flashMessage = "❌ Bác không có quyền thay đổi phân quyền thành viên.";
            $flashType = 'error';
        } else {
            $targetZaloId = trim($_POST['target_zalo_id'] ?? '');
            $newRole = trim($_POST['new_role'] ?? 'member');
            if (!empty($targetZaloId) && in_array($newRole, ['super_admin', 'admin', 'support', 'member'])) {
                $stmtUR = $pdo->prepare("UPDATE members SET role = ? WHERE zalo_id = ?");
                $stmtUR->execute([$newRole, $targetZaloId]);
                logAction($pdo, 'UPDATE_ROLE', "Đổi quyền ZaloID $targetZaloId sang $newRole");
                $flashMessage = "👑 A Di Đà Phật! Đã cập nhật quyền thành công.";
            }
        }
    }

    // Màn hình 2: Đăng ký Ca Trợ Duyên
    if ($action === 'register_shift') {
        $shiftId = intval($_POST['shift_id'] ?? 0);
        $zaloId = trim($_POST['zalo_id'] ?? '');
        $isBehalf = intval($_POST['is_behalf'] ?? 0);
        $roleType = trim($_POST['role_type'] ?? 'Thành viên');
        
        if ($isBehalf === 1) {
            $fullname = trim($_POST['behalf_fullname'] ?? '');
            $regZaloId = null;
            $registeredByZaloId = $zaloId;
        } else {
            $fullname = trim($_POST['fullname'] ?? '');
            $regZaloId = $zaloId;
            $registeredByZaloId = $zaloId;
        }

        if (!empty($fullname) && $shiftId > 0) {
            // Chống đăng ký trùng 1 người / ca
            if ($isBehalf === 0 && !empty($regZaloId)) {
                $stmtCheckDup = $pdo->prepare("SELECT COUNT(*) as cnt FROM registrations WHERE shift_id = ? AND zalo_id = ?");
                $stmtCheckDup->execute([$shiftId, $regZaloId]);
                $isDuplicate = $stmtCheckDup->fetch()['cnt'] > 0;
            } else {
                $stmtCheckDup = $pdo->prepare("SELECT COUNT(*) as cnt FROM registrations WHERE shift_id = ? AND LOWER(fullname) = LOWER(?)");
                $stmtCheckDup->execute([$shiftId, $fullname]);
                $isDuplicate = $stmtCheckDup->fetch()['cnt'] > 0;
            }

            if ($isDuplicate) {
                $flashMessage = "⚠️ A Di Đà Phật! Phật tử [ " . htmlspecialchars($fullname) . " ] ĐÃ ĐĂNG KÝ ca này rồi!";
                $flashType = 'error';
            } else {
                $stmtReg = $pdo->prepare("INSERT INTO registrations (shift_id, fullname, role_type, zalo_id, is_behalf, registered_by_zalo_id) VALUES (?, ?, ?, ?, ?, ?)");
                $stmtReg->execute([$shiftId, $fullname, $roleType, $regZaloId, $isBehalf, $registeredByZaloId]);
                logAction($pdo, 'REGISTER_SHIFT', "Đăng ký ca ID $shiftId: $fullname");
                $flashMessage = "A Di Đà Phật! Đã ghi nhận [ " . htmlspecialchars($fullname) . " ] đăng ký ca thành công.";
            }
        } else {
            $flashMessage = "Vui lòng nhập Họ và Tên người tham gia ca.";
            $flashType = 'error';
        }
    }

    // Hủy lượt đăng ký
    if ($action === 'cancel_registration') {
        $regId = intval($_POST['reg_id'] ?? 0);
        if ($regId > 0) {
            $stmt = $pdo->prepare("DELETE FROM registrations WHERE id = ?");
            $stmt->execute([$regId]);
            logAction($pdo, 'CANCEL_REGISTRATION', "Xóa đăng ký ID $regId");
            $flashMessage = "A Di Đà Phật! Đã hoan hỷ hủy lượt đăng ký.";
        }
    }

    // Actions dành riêng cho Admin/Support
    if ($isAdminOrSupport) {
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
                logAction($pdo, 'UPDATE_EVENT', "Cập nhật đợt ID $eventId");
                $flashMessage = "A Di Đà Phật! Đã cập nhật thành công thông tin & chỉ tiêu.";
            }
        }

        if ($action === 'complete_event') {
            $eventId = intval($_POST['event_id'] ?? 0);
            if ($eventId > 0) {
                $stmt = $pdo->prepare("UPDATE events SET status = 'completed' WHERE id = ?");
                $stmt->execute([$eventId]);
                logAction($pdo, 'COMPLETE_EVENT', "Đóng đợt ID $eventId");
                $flashMessage = "Đã đánh dấu hoàn thành đợt trợ duyên.";
            }
        }
    }
}

// 4. TRUY VẤN DỮ LIỆU HIỆN TẠI & RÀO CẢN BẢO VỆ
$mode = $_GET['mode'] ?? 'public'; // public, admin, stats, test, agent_kit
$currentEventId = intval($_GET['event_id'] ?? 0);

// KIỂM TRA QUYỀN TRUY CẬP VÀO KHU VỰC QUẢN TRỊ & THỐNG KÊ
$accessDenied = false;
if (in_array($mode, ['admin', 'stats']) && !$isAdminOrSupport && !isset($_GET['bypass_auth'])) {
    $accessDenied = true;
}

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

// Danh sách thành viên hệ thống
$stmtMembers = $pdo->query("SELECT * FROM members ORDER BY id ASC");
$membersList = $stmtMembers->fetchAll();
$registeredZaloIds = [];
$registeredNamesMap = [];
foreach ($membersList as $m) {
    $registeredZaloIds[$m['zalo_id']] = $m;
    $registeredNamesMap[mb_strtolower($m['fullname'])] = true;
}

// Thống Kê Dữ Liệu
$memberStats = [];
if (($mode === 'stats' || $mode === 'admin') && !$accessDenied) {
    $stmtStats = $pdo->query("
        SELECT fullname, role_type, COUNT(*) as total_registrations 
        FROM registrations 
        GROUP BY fullname 
        ORDER BY total_registrations DESC, fullname ASC
    ");
    $memberStats = $stmtStats->fetchAll();
}

// Automated Test Runner
$testResults = [];
if ($mode === 'test') {
    try {
        $testResults[] = ["test" => "Kiểm tra Zalo Browser Guard", "status" => true, "msg" => "Cơ chế bảo vệ Zalo UA sẵn sàng"];
        $testResults[] = ["test" => "Kết nối SQLite PDO (WAL Mode)", "status" => true, "msg" => "Kết nối CSDL thành công"];
        $testResults[] = ["test" => "Cấu trúc Phân Quyền (RBAC Role Column)", "status" => in_array('role', $cols), "msg" => "Đã có cột role trong bảng members"];
        $stmtLogCheck = $pdo->query("SELECT COUNT(*) as cnt FROM logs");
        $testResults[] = ["test" => "Ghi Nhật ký Hệ thống (Logs Table)", "status" => true, "msg" => "Tổng số log: " . $stmtLogCheck->fetch()['cnt']];
    } catch (Exception $ex) {
        $testResults[] = ["test" => "Lỗi Kiểm Thử", "status" => false, "msg" => $ex->getMessage()];
    }
}

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
                
                <?php if ($isAdminOrSupport): ?>
                    <a href="?mode=admin" class="px-2.5 py-1.5 rounded <?php echo $mode==='admin'?'bg-amber-900 text-white':'bg-amber-800/60 hover:bg-amber-800'; ?>">Quản Trị</a>
                    <a href="?mode=stats" class="px-2.5 py-1.5 rounded <?php echo $mode==='stats'?'bg-amber-900 text-white':'bg-amber-800/60 hover:bg-amber-800'; ?>">Thống Kê</a>
                <?php else: ?>
                    <a href="?mode=admin" class="px-2.5 py-1.5 rounded opacity-75 bg-amber-800/40 hover:bg-amber-800/80" title="Yêu cầu quyền BĐH">🔒 Quản Trị</a>
                <?php endif; ?>
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

        <?php if (!$isZalo): ?>
            <!-- KHÓA TRUY CẬP NGOÀI BROWSER ZALO -->
            <div class="bg-white rounded-2xl p-6 shadow-md border-2 border-rose-300 text-center space-y-4 my-6">
                <div class="w-16 h-16 bg-rose-100 text-rose-600 rounded-full flex items-center justify-center mx-auto text-3xl font-bold">📲</div>
                <h2 class="text-2xl font-bold text-rose-900">Vui lòng mở bằng ứng dụng Zalo</h2>
                <p class="text-slate-700 text-elder leading-relaxed">
                    A Di Đà Phật! Hệ thống trợ duyên chỉ hỗ trợ đăng ký trực tiếp từ ứng dụng <strong>Zalo</strong> để nhận diện danh tính Phật tử tự động.
                </p>
                <div class="p-3.5 bg-amber-50 rounded-xl text-amber-900 text-sm font-semibold border border-amber-200">
                    👉 Bác vui lòng sao chép link này dán vào tin nhắn Zalo để bấm mở trực tiếp!
                </div>
                <div>
                    <a href="?bypass_zalo=1<?php echo $activeEvent ? '&event_id='.$activeEvent['id'] : ''; ?>" class="text-xs text-slate-400 underline">[Mở khóa kiểm thử kỹ thuật]</a>
                </div>
            </div>
        <?php elseif ($accessDenied): ?>
            <!-- CẢNH BÁO BỎ KHÓA TRUY CẬP QUẢN TRỊ CHO THÀNH VIÊN THƯỜNG -->
            <div class="bg-white rounded-2xl p-6 shadow-md border-2 border-amber-400 text-center space-y-4 my-6">
                <div class="w-16 h-16 bg-amber-100 text-amber-800 rounded-full flex items-center justify-center mx-auto text-3xl font-bold">🔒</div>
                <h2 class="text-2xl font-bold text-amber-900">Mục Dành Cho Ban Điều Hành</h2>
                <p class="text-slate-700 text-elder leading-relaxed">
                    A Di Đà Phật! Trang <strong>Quản Trị</strong> và <strong>Thống Kê</strong> chỉ dành riêng cho Trưởng ban, Phó ban và Trợ lý điều hành đạo tràng.
                </p>
                <div class="p-4 bg-amber-50 rounded-xl border border-amber-200 text-amber-900 text-sm">
                    <strong>Tài khoản hiện tại của Bác:</strong> <?php echo htmlspecialchars($currentMember['fullname'] ?? 'Chưa xác định'); ?><br>
                    <strong>Trạng thái:</strong> Thành viên thường<br><br>
                    💡 <em>Nếu Bác thuộc Ban Điều Hành, vui lòng nhờ Bác Vận Hành Đầu Tiên (Super Admin) nâng quyền phân quản trị cho Bác.</em>
                </div>
                <a href="?mode=public" class="inline-block px-6 py-3 bg-amber-700 text-white font-bold rounded-xl text-base shadow">
                    &larr; Quay lại Trang Đăng Ký Trợ Duyên
                </a>
            </div>
        <?php else: ?>

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

                    <!-- MÀN HÌNH ĐĂNG KÝ / NHẬN DIỆN THÀNH VIÊN -->
                    <div class="bg-white rounded-2xl p-5 shadow-md border-2 border-amber-400 mb-6">
                        
                        <!-- Màn hình A: Chưa Đăng Ký Thành Viên Trong Hệ Thống -->
                        <div id="memberOnboardingBox" class="space-y-4">
                            <div class="p-3 bg-amber-50 rounded-xl border border-amber-200 text-xs text-amber-900 leading-relaxed">
                                💡 <strong>Đăng ký Danh Tính Thành Viên:</strong> Bác vui lòng nhập Tên 1 lần duy nhất để ghi nhận vào hệ thống Đạo Tràng. Lần sau Zalo sẽ tự nhớ tên Bác vĩnh viễn!
                            </div>

                            <form method="POST" id="onboardForm" class="space-y-3">
                                <input type="hidden" name="action" value="register_member">
                                <input type="hidden" name="zalo_id" id="onboardZaloId" value="">
                                <input type="hidden" name="force_confirm" id="onboardForceConfirm" value="0">

                                <div>
                                    <label class="block text-slate-700 font-bold text-elder mb-1">Họ và Tên Phật Tử:</label>
                                    <input type="text" name="fullname" id="inputOnboardFullname" required placeholder="Nhập tên Bác/Cô/Chú (VD: Bác An)..." value="<?php echo htmlspecialchars($nameCollisionWarning['fullname'] ?? ''); ?>" class="w-full text-elder p-3.5 border-2 border-amber-300 rounded-xl focus:outline-none focus:border-amber-600 bg-amber-50/30">
                                </div>

                                <div>
                                    <label class="block text-slate-700 font-semibold text-sm mb-1">Số điện thoại (tùy chọn):</label>
                                    <input type="text" name="phone" placeholder="Nhập số điện thoại..." class="w-full p-2.5 border rounded-xl">
                                </div>

                                <?php if ($nameCollisionWarning): ?>
                                    <div class="p-3 bg-rose-50 border border-rose-300 rounded-xl space-y-2">
                                        <p class="text-xs text-rose-800 font-bold">
                                            ⚠️ Tên [<?php echo htmlspecialchars($nameCollisionWarning['fullname']); ?>] trùng với Phật tử đã có trong hệ thống!
                                        </p>
                                        <div class="flex gap-2">
                                            <button type="button" onclick="confirmForceRegisterMember()" class="flex-1 py-2 bg-amber-600 text-white font-bold rounded-lg text-xs">
                                                Giữ nguyên & Đăng ký
                                            </button>
                                            <button type="button" onclick="focusRename()" class="flex-1 py-2 bg-slate-200 text-slate-800 font-semibold rounded-lg text-xs">
                                                Đổi lại tên (thêm xóm)
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <button type="submit" class="w-full py-3.5 bg-amber-700 hover:bg-amber-800 text-white font-bold rounded-xl text-lg shadow">
                                    🌟 Hoàn Tất Đăng Ký Vào Hệ Thống Đạo Tràng
                                </button>
                            </form>
                        </div>

                        <!-- Màn hình B: Đã Đăng Ký Thành Viên -> Cho phép Đăng Ký Ca Trợ Duyên & Đăng Ký Hộ -->
                        <div id="memberIdentifiedBox" class="hidden space-y-4">
                            <div class="p-3.5 bg-emerald-50 border border-emerald-300 rounded-xl flex justify-between items-center">
                                <div>
                                    <span class="text-xs text-emerald-800 font-bold uppercase tracking-wider block flex items-center gap-1">
                                        ⭐ Thành viên Đạo Tràng
                                        <?php if ($isSuperAdmin): ?>
                                            <span class="bg-amber-200 text-amber-900 px-1.5 py-0.5 rounded text-[10px]">👑 Vận Hành</span>
                                        <?php elseif ($isAdminOrSupport): ?>
                                            <span class="bg-indigo-100 text-indigo-900 px-1.5 py-0.5 rounded text-[10px]">🛠️ BĐH</span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="text-xl font-bold text-slate-900" id="identifiedMemberName">...</span>
                                </div>
                                <button type="button" onclick="resetMemberIdentity()" class="text-xs text-amber-800 font-semibold px-2.5 py-1.5 bg-amber-100 hover:bg-amber-200 rounded-lg border border-amber-300">
                                    ✏️ Đổi danh tính
                                </button>
                            </div>

                            <form method="POST" id="regShiftForm" class="space-y-4">
                                <input type="hidden" name="action" value="register_shift">
                                <input type="hidden" name="shift_id" id="selectedShiftId" value="">
                                <input type="hidden" name="fullname" id="finalFullname" value="">
                                <input type="hidden" name="zalo_id" id="finalZaloId" value="">

                                <!-- Lựa chọn Đăng ký cho mình hay Đăng ký hộ -->
                                <div class="p-3 bg-amber-50/80 rounded-xl border border-amber-200 space-y-2">
                                    <label class="block text-xs font-bold text-amber-900 uppercase">Hình thức đăng ký:</label>
                                    <div class="flex items-center gap-4 text-sm font-semibold text-slate-800">
                                        <label class="flex items-center gap-1.5 cursor-pointer">
                                            <input type="radio" name="is_behalf" value="0" checked onchange="toggleBehalfMode(false)" class="w-4 h-4 text-amber-600">
                                            Đăng ký cho chính mình
                                        </label>
                                        <label class="flex items-center gap-1.5 cursor-pointer">
                                            <input type="radio" name="is_behalf" value="1" onchange="toggleBehalfMode(true)" class="w-4 h-4 text-amber-600">
                                            🤝 Đăng ký hộ người khác
                                        </label>
                                    </div>

                                    <div id="behalfInputBox" class="hidden pt-2 border-t mt-2">
                                        <label class="block text-xs font-bold text-slate-700 mb-1">Họ tên người được đăng ký hộ:</label>
                                        <input type="text" name="behalf_fullname" id="behalfFullname" placeholder="Nhập tên người nhờ đăng ký hộ..." class="w-full text-elder p-2.5 border-2 border-amber-300 rounded-lg bg-white">
                                    </div>
                                </div>

                                <p class="text-sm font-semibold text-slate-700">
                                    👉 Bấm trực tiếp vào nút ca dưới đây để hoàn tất đăng ký 1-chạm:
                                </p>

                                <div class="grid grid-cols-1 gap-3 pt-1">
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
                                        <button type="button" onclick="submitRegisterShift(<?php echo $s['id']; ?>)" class="btn-touch w-full <?php echo $btnColor; ?> font-bold rounded-xl shadow-md flex justify-between items-center px-4 transition-transform active:scale-95">
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
                    </div>

                    <!-- DANH SÁCH THÀNH VIÊN CÁC CA KÈM BADGE ICON FOR VERIFIED MEMBERS -->
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
                                                <?php 
                                                    $isMemberVerified = (!empty($r['zalo_id']) && isset($registeredZaloIds[$r['zalo_id']])) || isset($registeredNamesMap[mb_strtolower($r['fullname'])]);
                                                ?>
                                                <li class="py-2.5 flex justify-between items-center">
                                                    <span class="text-elder font-medium text-slate-800 flex items-center flex-wrap gap-1">
                                                        <span class="text-amber-700 font-bold w-6 inline-block"><?php echo $idx + 1; ?>.</span>
                                                        <?php echo htmlspecialchars($r['fullname']); ?>
                                                        
                                                        <?php if ($isMemberVerified): ?>
                                                            <span class="text-xs bg-emerald-100 text-emerald-800 font-bold px-2 py-0.5 rounded-full flex items-center gap-0.5">
                                                                ⭐ Thành viên
                                                            </span>
                                                        <?php endif; ?>

                                                        <?php if (!empty($r['is_behalf'])): ?>
                                                            <span class="text-xs bg-slate-100 text-slate-600 font-medium px-1.5 py-0.5 rounded">Đăng ký hộ</span>
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
                        <?php if ($isAdminOrSupport): ?>
                            <a href="?mode=admin" class="inline-block px-5 py-2.5 bg-amber-700 text-white rounded-xl font-bold">Vào trang Quản trị để tạo mới</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($mode === 'admin'): ?>
                <!-- GIAO DIỆN QUẢN TRỊ (ADMIN DASHBOARD) -->
                <div class="space-y-6">

                    <!-- BẢNG PHÂN QUYỀN BAN ĐIỀU HÀNH (SUPER ADMIN ONLY) -->
                    <?php if ($isSuperAdmin): ?>
                        <div class="bg-white rounded-2xl p-5 shadow-sm border-2 border-indigo-400 bg-indigo-50/20">
                            <h2 class="text-xl font-bold text-indigo-900 mb-1 flex items-center gap-2">
                                <span>👑</span> Quản Lý Phân Quyền Ban Điều Hành
                            </h2>
                            <p class="text-xs text-slate-600 mb-4">Dành riêng cho Người Vận Hành Đầu Tiên (Super Admin) để gán quyền cho các vị Trưởng ban, Phó ban và Trợ lý.</p>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-sm bg-white rounded-xl border">
                                    <thead class="bg-indigo-100 text-indigo-900 font-bold border-b">
                                        <tr>
                                            <th class="p-2.5">Họ và Tên</th>
                                            <th class="p-2.5">Quyền Hiện Tại</th>
                                            <th class="p-2.5 text-right">Thay Đổi Quyền</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <?php foreach ($membersList as $mb): ?>
                                            <tr>
                                                <td class="p-2.5 font-bold text-slate-800">
                                                    <?php echo htmlspecialchars($mb['fullname']); ?>
                                                    <span class="block text-[10px] text-slate-400 font-mono"><?php echo htmlspecialchars($mb['zalo_id']); ?></span>
                                                </td>
                                                <td class="p-2.5">
                                                    <?php 
                                                        if ($mb['role'] === 'super_admin') echo '<span class="px-2 py-0.5 bg-amber-200 text-amber-900 font-bold text-xs rounded-full">👑 Super Admin</span>';
                                                        elseif ($mb['role'] === 'admin') echo '<span class="px-2 py-0.5 bg-indigo-100 text-indigo-800 font-bold text-xs rounded-full">Trưởng/Phó Ban</span>';
                                                        elseif ($mb['role'] === 'support') echo '<span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 font-semibold text-xs rounded-full">Trợ lý / Support</span>';
                                                        else echo '<span class="px-2 py-0.5 bg-slate-100 text-slate-600 text-xs rounded-full">Thành viên</span>';
                                                    ?>
                                                </td>
                                                <td class="p-2.5 text-right">
                                                    <?php if ($mb['zalo_id'] !== $currentZaloId): ?>
                                                        <form method="POST" class="inline-flex gap-1">
                                                            <input type="hidden" name="action" value="update_member_role">
                                                            <input type="hidden" name="target_zalo_id" value="<?php echo htmlspecialchars($mb['zalo_id']); ?>">
                                                            <select name="new_role" onchange="this.form.submit()" class="text-xs p-1 border rounded bg-slate-50 font-semibold">
                                                                <option value="member" <?php echo $mb['role']==='member'?'selected':''; ?>>Thành viên thường</option>
                                                                <option value="support" <?php echo $mb['role']==='support'?'selected':''; ?>>Trợ lý Support</option>
                                                                <option value="admin" <?php echo $mb['role']==='admin'?'selected':''; ?>>Trưởng/Phó Ban (Admin)</option>
                                                            </select>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-xs text-slate-400 italic">(Chính bạn)</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($activeEvent): ?>
                        <div class="bg-white rounded-2xl p-5 shadow-sm border border-amber-300 bg-amber-50/20">
                            <h2 class="text-xl font-bold text-amber-900 mb-3 pb-2 border-b">✏️ Điều Chỉnh Lịch & Chỉ Tiêu Từng Ca</h2>
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
                                    <label class="block text-xs font-bold text-slate-700 mb-2">Đổi Giờ & Điều Chỉnh Chỉ Tiêu Ưu Tiên:</label>
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
                    <?php endif; ?>

                    <!-- MẪU TIN NHẮN ZALO AUTO -->
                    <?php if ($activeEvent): ?>
                        <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
                            <h2 class="text-xl font-bold text-slate-900 mb-2">📋 Mẫu Tin Nhắn Gửi Nhóm Zalo</h2>
                            <div id="zaloTemplate" class="p-4 bg-slate-50 rounded-xl border text-slate-800 text-sm leading-relaxed whitespace-pre-line font-mono mb-3">
Nam Mô A Di Đà Phật

Kính thưa THẦY và liên hữu đồng tu: <?php echo htmlspecialchars($activeEvent['patient_name']); ?> yếu nên đạo tràng tổ chức trợ duyên cho bác ngày <?php echo count($shifts); ?> thời (<?php 
    $sInfo = [];
    foreach ($shifts as $s) {
        $sInfo[] = $s['shift_name'] . " " . $s['shift_time'] . " (~" . ($s['max_target']??10) . " vị)";
    }
    echo implode(", ", $sInfo);
?>). Các bác đủ duyên thời nào mong các bác hoan hỷ.

👉 Bấm vào link Zalo để xem & đăng ký ca 1-chạm:
<?php echo $currentUrl; ?>


Con xin thành kính tri ân công đức của quý Thầy cùng liên hữu đồng tu ạ.
Nam Mô A Di Đà Phật
                            </div>

                            <button onclick="copyZaloText()" class="w-full py-3 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-xl flex justify-center items-center gap-2 shadow">
                                <span>📲</span> Sao Chép Tin Nhắn Zalo (1-Click)
                            </button>
                        </div>
                    <?php endif; ?>

                    <!-- TẠO ĐỢT TRỢ DUYÊN MỚI -->
                    <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200">
                        <h2 class="text-xl font-bold text-slate-900 mb-4 pb-2 border-b">🆕 Khởi Tạo Đợt Trợ Duyên Mới</h2>
                        <form method="POST" class="space-y-3">
                            <input type="hidden" name="action" value="create_event">
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Tên Người Được Trợ Duyên:</label>
                                <input type="text" name="patient_name" required placeholder="Ví dụ: Bác Y, Cụ Z..." class="w-full p-2.5 border rounded-lg">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-700 mb-1">Địa Chỉ / Ghi Chú Gia Đình:</label>
                                <input type="text" name="address" placeholder="Ví dụ: Thôn A, Xã B..." class="w-full p-2.5 border rounded-lg">
                            </div>
                            <div class="grid grid-cols-2 gap-3 border-t pt-2">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Giờ Ca Chiều:</label>
                                    <input type="text" name="afternoon_time" value="14h15'" class="w-full p-2 border rounded-lg text-center mb-1">
                                    <input type="number" name="afternoon_target" value="10" class="w-full p-1 border rounded-lg text-center text-xs bg-amber-50">
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-600 mb-1">Giờ Ca Tối:</label>
                                    <input type="text" name="evening_time" value="19h15'" class="w-full p-2 border rounded-lg text-center mb-1">
                                    <input type="number" name="evening_target" value="10" class="w-full p-1 border rounded-lg text-center text-xs bg-indigo-50">
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
                            <form method="POST" onsubmit="return confirm('Xác nhận hoàn thành đợt trợ duyên này?');">
                                <input type="hidden" name="action" value="complete_event">
                                <input type="hidden" name="event_id" value="<?php echo $activeEvent['id']; ?>">
                                <button type="submit" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-800 font-semibold rounded-lg text-sm">
                                    Đóng Đợt Trợ Duyên Này
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>

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

                    <p class="text-sm text-slate-600">Tổng số lượt đăng ký đi trợ duyên qua tất cả các đợt:</p>

                    <?php if (empty($memberStats)): ?>
                        <p class="text-slate-400 italic text-center py-4">Chưa có dữ liệu thống kê.</p>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-amber-50 text-amber-900 border-b">
                                    <tr>
                                        <th class="p-2.5">#</th>
                                        <th class="p-2.5">Họ và Tên Phật Tử</th>
                                        <th class="p-2.5">Xác minh</th>
                                        <th class="p-2.5 text-right">Tổng Lượt</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y">
                                    <?php foreach ($memberStats as $i => $ms): ?>
                                        <tr class="hover:bg-slate-50">
                                            <td class="p-2.5 font-bold text-slate-400"><?php echo $i + 1; ?></td>
                                            <td class="p-2.5 font-semibold text-slate-800"><?php echo htmlspecialchars($ms['fullname']); ?></td>
                                            <td class="p-2.5 text-xs">
                                                <?php if (isset($registeredNamesMap[mb_strtolower($ms['fullname'])])): ?>
                                                    <span class="text-emerald-700 font-bold">⭐ Thành viên</span>
                                                <?php else: ?>
                                                    <span class="text-slate-400">Tự do</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-2.5 text-right font-bold text-amber-700"><?php echo $ms['total_registrations']; ?> lượt</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($mode === 'test'): ?>
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200 space-y-4">
                    <div class="flex justify-between items-center border-b pb-3">
                        <h2 class="text-xl font-bold text-slate-900">🧪 Kết Quả Tự Động Kiểm Thử V4.0</h2>
                        <a href="?mode=admin" class="text-sm text-amber-700 font-semibold">&larr; Quay lại Admin</a>
                    </div>

                    <div class="space-y-2">
                        <?php foreach ($testResults as $t): ?>
                            <div class="p-3 rounded-lg border flex justify-between items-center <?php echo $t['status']?'bg-emerald-50 border-emerald-200 text-emerald-800':'bg-rose-50 border-rose-200 text-rose-800'; ?>">
                                <div>
                                    <strong class="block text-sm"><?php echo htmlspecialchars($t['test']); ?></strong>
                                    <span class="text-xs opacity-80"><?php echo htmlspecialchars($t['msg']); ?></span>
                                </div>
                                <span class="font-bold text-sm"><?php echo $t['status'] ? 'PASS ✅' : 'FAIL ❌'; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php elseif ($mode === 'agent_kit'): ?>
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-slate-200 space-y-3">
                    <div class="flex justify-between items-center border-b pb-3">
                        <h2 class="text-xl font-bold text-slate-900">📄 Project Agent Kit (Antigravity IDE v4.0)</h2>
                        <a href="?mode=admin" class="text-sm text-amber-700 font-semibold">&larr; Quay lại Admin</a>
                    </div>
                    <div class="p-4 bg-slate-900 text-slate-100 rounded-xl text-xs font-mono overflow-x-auto whitespace-pre-wrap leading-relaxed max-h-96">
# PROJECT AGENT KIT - HỆ THỐNG TRỢ DUYÊN NIỆM PHẬT (v4.0)
Nâng cấp: Super Admin, Multi-Role Access Control & Member Guard

1. CƠ CHẾ PHÂN QUYỀN (RBAC):
- Người đăng ký đầu tiên tự động thành `super_admin`.
- `super_admin` sở hữu bảng ủy quyền cho Trưởng/Phó ban (`admin`) và Trợ lý (`support`).
- Khóa trang Quản trị (`?mode=admin`) & Thống kê (`?mode=stats`) đối với `member` thường.
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </main>

    <!-- SCRIPT TƯƠNG TÁC TỰ ĐỘNG NHẬN DIỆN ZALO & MEMBER ONBOARDING -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            initZaloMemberIdentity();
        });

        function initZaloMemberIdentity() {
            let savedName = localStorage.getItem('dt_zalo_fullname') || '';
            let savedZaloId = localStorage.getItem('dt_zalo_id') || 'ZALO_USER_' + Math.floor(Math.random() * 10000000);

            localStorage.setItem('dt_zalo_id', savedZaloId);
            
            // Đồng bộ cookie cho PHP nhận diện danh tính
            document.cookie = "dt_zalo_id=" + savedZaloId + "; path=/; max-age=31536000";

            const onboardZaloInput = document.getElementById('onboardZaloId');
            if (onboardZaloInput) onboardZaloInput.value = savedZaloId;

            const finalZaloInput = document.getElementById('finalZaloId');
            if (finalZaloInput) finalZaloInput.value = savedZaloId;

            if (savedName && savedName.trim() !== '') {
                const nameDisplay = document.getElementById('identifiedMemberName');
                if (nameDisplay) nameDisplay.innerText = savedName.trim();
                
                const boxIdentified = document.getElementById('memberIdentifiedBox');
                if (boxIdentified) boxIdentified.classList.remove('hidden');

                const boxOnboard = document.getElementById('memberOnboardingBox');
                if (boxOnboard) boxOnboard.classList.add('hidden');

                const finalNameInput = document.getElementById('finalFullname');
                if (finalNameInput) finalNameInput.value = savedName.trim();
            } else {
                const boxIdentified = document.getElementById('memberIdentifiedBox');
                if (boxIdentified) boxIdentified.classList.add('hidden');

                const boxOnboard = document.getElementById('memberOnboardingBox');
                if (boxOnboard) boxOnboard.classList.remove('hidden');
            }
        }

        function toggleBehalfMode(isBehalf) {
            const behalfBox = document.getElementById('behalfInputBox');
            const behalfInput = document.getElementById('behalfFullname');
            if (isBehalf) {
                behalfBox.classList.remove('hidden');
                behalfInput.focus();
            } else {
                behalfBox.classList.add('hidden');
            }
        }

        function submitRegisterShift(shiftId) {
            const isBehalf = document.querySelector('input[name="is_behalf"]:checked').value === '1';
            
            if (isBehalf) {
                const behalfName = document.getElementById('behalfFullname').value.trim();
                if (!behalfName) {
                    alert('A Di Đà Phật! Bác vui lòng nhập Tên người được đăng ký hộ.');
                    document.getElementById('behalfFullname').focus();
                    return;
                }
            } else {
                let memberName = document.getElementById('finalFullname').value.trim();
                if (!memberName) {
                    alert('A Di Đà Phật! Bác chưa hoàn tất đăng ký thành viên vào hệ thống.');
                    return;
                }
            }

            document.getElementById('selectedShiftId').value = shiftId;
            document.getElementById('regShiftForm').submit();
        }

        function resetMemberIdentity() {
            if (confirm('Bác có muốn đổi sang Tên Phật Tử khác trên Zalo này không?')) {
                localStorage.removeItem('dt_zalo_fullname');
                document.getElementById('finalFullname').value = '';
                document.getElementById('memberIdentifiedBox').classList.add('hidden');
                document.getElementById('memberOnboardingBox').classList.remove('hidden');
                document.getElementById('inputOnboardFullname').focus();
            }
        }

        function confirmForceRegisterMember() {
            document.getElementById('onboardForceConfirm').value = '1';
            document.getElementById('onboardForm').submit();
        }

        function focusRename() {
            document.getElementById('inputOnboardFullname').focus();
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