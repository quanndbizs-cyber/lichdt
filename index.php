<?php
// Tên file lưu dữ liệu đăng ký
$dataFile = 'dangky_bacX.json';

// Đọc dữ liệu hiện tại
$registrations = file_exists($dataFile) ? json_decode(file_get_contents($dataFile), true) : [];

$message = "";

// Xử lý khi Phật tử bấm nút Đăng ký
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fullname'])) {
    $name = trim($_POST['fullname']);
    $shift = $_POST['shift'];
    
    if (!empty($name)) {
        $registrations[] = [
            'name' => htmlspecialchars($name),
            'shift' => $shift,
            'time' => date('H:i - d/m/Y')
        ];
        file_put_contents($dataFile, json_encode($registrations, JSON_UNESCAPED_UNICODE));
        $message = "A Di Đà Phật! Đã ghi nhận Phật tử: <b>$name</b> đăng ký <b>$shift</b>.";
    } else {
        $message = "<span style='color:red;'>Vui lòng nhập tên/pháp danh trước khi chọn ca!</span>";
    }
}

// Đếm số người mỗi ca
$countChieu = 0;
$countToi = 0;
foreach ($registrations as $r) {
    if ($r['shift'] === 'Ca Chiều (14h15)') $countChieu++;
    if ($r['shift'] === 'Ca Tối (19h15)') $countToi++;
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký Trợ duyên Niệm Phật</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #fcf8ec; margin: 0; padding: 15px; font-size: 20px; color: #333; }
        .container { max-width: 600px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); border: 2px solid #e0a96d; }
        h2, h3 { text-align: center; color: #8b0000; margin-top: 5px; }
        .announcement { background-color: #fff8e7; padding: 15px; border-left: 5px solid #d4af37; font-size: 18px; line-height: 1.6; margin-bottom: 20px; border-radius: 4px; white-space: pre-line; }
        label { font-weight: bold; display: block; margin-bottom: 8px; color: #8b0000; }
        input[type="text"] { width: 100%; padding: 15px; font-size: 22px; border: 2px solid #ccc; border-radius: 8px; box-sizing: border-box; margin-bottom: 20px; }
        .btn { width: 100%; padding: 18px; font-size: 22px; font-weight: bold; color: #fff; border: none; border-radius: 10px; margin-bottom: 15px; cursor: pointer; text-align: center; }
        .btn-chieu { background-color: #d97706; }
        .btn-toi { background-color: #1e3a8a; }
        .btn:hover { opacity: 0.9; }
        .alert { background: #d1e7dd; color: #0f5132; padding: 15px; border-radius: 8px; text-align: center; margin-bottom: 20px; font-size: 20px; font-weight: bold; }
        .list-box { margin-top: 30px; border-top: 2px dashed #ccc; padding-top: 15px; }
        .badge { background: #8b0000; color: #fff; padding: 3px 10px; border-radius: 12px; font-size: 18px; }
        ul { padding-left: 20px; }
        li { margin-bottom: 10px; font-size: 20px; }
    </style>
</head>
<body>

<div class="container">
    <h2>Trợ Duyên Niệm Phật</h2>
    
    <div class="announcement">Nam Mô A Di Đà Phật

kính thưa THẦY và liên hữu đồng tu bác X yếu nên đạo tràng tổ chức trợ duyên cho bác ngày 2 thời chiều 2h15' tối 7h15'các bác đủ duyên thời nào mong các bác hoan hỷ vì nhà bác X chật nên mỗi ca khoảng tầm 10 người và còn gia đình cũng đông ,các bác hoan hỷ cùng tham gia ,con xin thành kính tri ân cđ của quí Thầy cùng liên hữu đồng tu ạ

Nam Mô A Di Đà Phật</div>

    <?php if (!empty($message)): ?>
        <div class="alert"><?= $message ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <label for="fullname">1. Nhập Họ Tên / Pháp Danh:</label>
        <input type="text" id="fullname" name="fullname" placeholder="Ví dụ: Đắc Lực..." required>

        <label>2. Bấm chọn ca muốn đi:</label>
        <button type="submit" name="shift" value="Ca Chiều (14h15)" class="btn btn-chieu">
            Đăng Ký CA CHIỀU (14h15) <br><small>(Đã có <?= $countChieu ?> người)</small>
        </button>
        
        <button type="submit" name="shift" value="Ca Tối (19h15)" class="btn btn-toi">
            Đăng Ký CA TỐI (19h15) <br><small>(Đã có <?= $countToi ?> người)</small>
        </button>
    </form>

    <div class="list-box">
        <h3>Danh Sách Đã Đăng Ký</h3>
        
        <p><b>☀️ Ca Chiều 2h15' <span class="badge"><?= $countChieu ?> người</span></b></p>
        <ol>
            <?php foreach ($registrations as $r): ?>
                <?php if ($r['shift'] === 'Ca Chiều (14h15)'): ?>
                    <li><b><?= $r['name'] ?></b></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ol>

        <p><b>🌙 Ca Tối 7h15' <span class="badge"><?= $countToi ?> người</span></b></p>
        <ol>
            <?php foreach ($registrations as $r): ?>
                <?php if ($r['shift'] === 'Ca Tối (19h15)'): ?>
                    <li><b><?= $r['name'] ?></b></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ol>
    </div>
</div>

</body>
</html>
