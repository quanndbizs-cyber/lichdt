# **BỘ KIT PHÁT TRIỂN & CHỈ THỊ DÀNH CHO AI AGENT (AIDER / GEMINI)**

> **Tên dự án:** Hệ Thống Sắp Lịch & Đăng Ký Trợ Duyên Niệm Phật Online

> **Mục tiêu:** Giúp đạo tràng (\~30 người) đăng ký ca trợ duyên niệm Phật cho người bệnh (Bác X), tránh tập trung quá đông ở không gian hẹp.

> **Phương châm cốt lõi:** Đơn giản \- Dễ thao tác cho người cao tuổi \- Không cần đăng nhập \- Tốc độ cực nhanh.

## **📋 1\. SÁCH LƯỢC CỐT LÕI & YÊU CẦU UX/UI (NON-NEGOTIABLE)**

1. **Giao diện dành riêng cho Phật tử cao tuổi:**  
   * **Chữ siêu to:** Kích thước font cơ bản từ 20px đến 24px.  
   * **Nút bấm siêu to & tương phản cao:** Màu da cam cho Ca Chiều (14h15), Màu xanh thẫm cho Ca Tối (19h15).  
   * **Khoảng cách thoáng:** Nút bấm và ô nhập liệu rộng rãi, dễ bấm trên màn hình điện thoại cảm ứng (chống bấm nhầm).  
2. **Luồng thao tác "Zero-Login" (Không bắt đăng nhập):**  
   * Người dùng nhận link qua Zalo \-\> Mở trang web \-\> Nhập Họ tên/Pháp danh \-\> Bấm nút chọn Ca \-\> Hoàn tất.  
3. **Chính sách Linh hoạt Mềm (Soft Limits):**  
   * Ưu tiên mỗi ca khoảng **10 người** (do nhà người bệnh chật).  
   * **KHÔNG khóa cứng nút bấm khi đủ 10 người**: Người thứ 11, 12... vẫn đăng ký được bình thường nếu gia đình và Phật tử hoan hỷ. Hiển thị rõ số lượng người đã đăng ký ngay trên nút bấm.  
4. **Hạ tầng nhẹ nhàng:**  
   * Dùng mã nguồn PHP thuần (1 file index.php), lưu dữ liệu dạng file JSON (dangky\_bacX.json).  
   * Không dùng cơ sở dữ liệu MySQL phức tạp để tối ưu tốc độ và dễ bảo trì.

## **📜 2\. NỘI DUNG THÔNG BÁO CHUẨN CỦA ĐẠO TRÀNG**

Nội dung hiển thị trên trang web phải tuân thủ chính xác văn bản thông báo chính thức sau:

Thông báo cho các Phật tử (liên hữu đồng tu) đi trợ duyên niệm Phật cho người bệnh.

Nam Mô A Di Đà Phật

Kính thưa THẦY và liên hữu đồng tu: Bác X yếu nên đạo tràng tổ chức trợ duyên cho bác ngày 2 thời (Chiều 14h15', Tối 19h15'). Các bác đủ duyên thời nào mong các bác hoan hỷ. 

Vì nhà bác X chật nên mỗi ca khoảng tầm 10 người và còn gia đình cũng đông, kính mong các bác hoan hỷ cùng tham gia. Con xin thành kính tri ân công đức của quý Thầy cùng liên hữu đồng tu ạ.

Nam Mô A Di Đà Phật

## **🛠️ 3\. CẤU HÌNH MÔI TRƯỜNG & THÔNG TIN SERVER (DEBIAN LINUX)**

* **Thư mục dự án:** /srv/SapLichDT hoặc /var/www/html/lichdt  
* **Môi trường hệ thống:** Debian Linux (Python 3.13, Node.js v20+, Web server www-data).  
* **Phân quyền ghi file JSON (Bắt buộc):**  
  sudo chown \-R www-data:www-data .  
  sudo chmod \-R 775 .

### **⚠️ Lưu ý kỹ thuật cho Aider Agent khi chạy trên Python 3.13 & Debian:**

1. **Thiếu module audioop (Lỗi Python 3.13):** Bắt buộc phải cài audioop-lts trong venv Python.  
   source \~/aider-env/bin/activate  
   pip install audioop-lts

2. **Xử lý lỗi git diff \--cached trên Debian:**  
   Aider cần kho Git hợp lệ để kiểm tra diff. Khi khởi chạy Aider, chạy lệnh sau hoặc thêm cờ \--no-git:  
   \# Cách 1: Khởi tạo Git cho thư mục (Khuyên dùng)  
   git init && git add . && git commit \-m "Init repo"

   \# Cách 2: Chạy Aider không dùng Git  
   aider \--model gemini/gemini-2.5-flash \--no-gui \--no-git index.php

## **💻 4\. MÃ NGUỒN CƠ SỞ (BASELINE CODE \- index.php)**

Mã nguồn chuẩn hiện tại cần đảm bảo toàn bộ tính năng và giao diện cho đạo tràng:

\<?php  
// Tên file JSON lưu trữ dữ liệu  
$dataFile \= 'dangky\_bacX.json';

// Lấy danh sách hiện tại  
$registrations \= file\_exists($dataFile) ? json\_decode(file\_get\_contents($dataFile), true) : \[\];  
if (\!is\_array($registrations)) {  
    $registrations \= \[\];  
}

$message \= "";

// Xử lý khi Phật tử bấm đăng ký  
if ($\_SERVER\['REQUEST\_METHOD'\] \=== 'POST' && isset($\_POST\['fullname'\])) {  
    $name \= trim($\_POST\['fullname'\]);  
    $shift \= isset($\_POST\['shift'\]) ? $\_POST\['shift'\] : '';  
      
    if (\!empty($name) && \!empty($shift)) {  
        $registrations\[\] \= \[  
            'id' \=\> uniqid(),  
            'name' \=\> htmlspecialchars($name),  
            'shift' \=\> $shift,  
            'time' \=\> date('H:i \- d/m/Y')  
        \];  
        file\_put\_contents($dataFile, json\_encode($registrations, JSON\_UNESCAPED\_UNICODE | JSON\_PRETTY\_PRINT));  
        $message \= "A Di Đà Phật\! Đã ghi nhận Phật tử: \<b\>$name\</b\> đăng ký \<b\>$shift\</b\>.";  
    } else {  
        $message \= "\<span style='color:red;'\>Vui lòng nhập tên / pháp danh trước khi chọn ca\!\</span\>";  
    }  
}

// Đếm số lượng đăng ký mỗi ca  
$countChieu \= 0;  
$countToi \= 0;  
foreach ($registrations as $r) {  
    if ($r\['shift'\] \=== 'Ca Chiều (14h15)') $countChieu++;  
    if ($r\['shift'\] \=== 'Ca Tối (19h15)') $countToi++;  
}  
?\>

\<\!DOCTYPE html\>  
\<html lang="vi"\>  
\<head\>  
    \<meta charset="UTF-8"\>  
    \<meta name="viewport" content="width=device-width, initial-scale=1.0"\>  
    \<title\>Trợ Duyên Niệm Phật \- Đạo Tràng\</title\>  
    \<style\>  
        body {   
            font-family: Arial, sans-serif;   
            background-color: \#fcf8ec;   
            margin: 0;   
            padding: 12px;   
            font-size: 20px;   
            color: \#2c2c2c;   
            line-height: 1.5;  
        }  
        .container {   
            max-width: 650px;   
            margin: 0 auto;   
            background: \#ffffff;   
            padding: 20px;   
            border-radius: 14px;   
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);   
            border: 2px solid \#d4af37;   
        }  
        h2 { text-align: center; color: \#8b0000; margin-top: 0; font-size: 26px; }  
        h3 { color: \#8b0000; font-size: 22px; border-bottom: 2px solid \#f0e0c0; padding-bottom: 8px; }  
        .announcement {   
            background-color: \#fffdf5;   
            padding: 16px;   
            border-left: 6px solid \#d4af37;   
            font-size: 19px;   
            margin-bottom: 20px;   
            border-radius: 6px;   
            border-top: 1px solid \#f2e3c6;  
            border-right: 1px solid \#f2e3c6;  
            border-bottom: 1px solid \#f2e3c6;  
        }  
        .announcement p { margin: 8px 0; }  
        label { font-weight: bold; display: block; margin-bottom: 8px; color: \#8b0000; font-size: 21px; }  
        input\[type="text"\] {   
            width: 100%;   
            padding: 16px;   
            font-size: 22px;   
            border: 2px solid \#c8a882;   
            border-radius: 10px;   
            box-sizing: border-box;   
            margin-bottom: 20px;   
            background-color: \#fffdfa;  
        }  
        input\[type="text"\]:focus {  
            outline: none;  
            border-color: \#8b0000;  
            background-color: \#ffffff;  
        }  
        .btn {   
            width: 100%;   
            padding: 18px;   
            font-size: 22px;   
            font-weight: bold;   
            color: \#ffffff;   
            border: none;   
            border-radius: 12px;   
            margin-bottom: 15px;   
            cursor: pointer;   
            text-align: center;   
            box-shadow: 0 4px 6px rgba(0,0,0,0.15);  
        }  
        .btn-chieu { background-color: \#d97706; }  
        .btn-toi { background-color: \#1e3a8a; }  
        .btn:active { transform: translateY(2px); }  
        .alert {   
            background: \#d1e7dd;   
            color: \#0f5132;   
            padding: 16px;   
            border-radius: 10px;   
            text-align: center;   
            margin-bottom: 20px;   
            font-size: 21px;   
            font-weight: bold;   
            border: 1px solid \#badbcc;  
        }  
        .list-box { margin-top: 25px; }  
        .badge { background: \#8b0000; color: \#fff; padding: 4px 12px; border-radius: 15px; font-size: 18px; }  
        ol { padding-left: 25px; margin-top: 8px; }  
        li { margin-bottom: 10px; font-size: 20px; font-weight: 500; }  
        .footer-nammo { text-align: center; font-weight: bold; color: \#8b0000; font-size: 22px; margin-top: 15px; }  
    \</style\>  
\</head\>  
\<body\>

\<div class="container"\>  
    \<h2\>Lịch Trợ Duyên Niệm Phật\</h2\>  
      
    \<\!-- Khối Thông Báo Chuẩn Đạo Tràng \--\>  
    \<div class="announcement"\>  
        \<p class="footer-nammo"\>Nam Mô A Di Đà Phật\</p\>  
        \<p\>Kính thưa THẦY và liên hữu đồng tu: Bác X yếu nên đạo tràng tổ chức trợ duyên cho bác ngày 2 thời (Chiều 14h15', Tối 19h15'). Các bác đủ duyên thời nào mong các bác hoan hỷ.\</p\>  
        \<p\>Vì nhà bác X chật nên mỗi ca khoảng tầm 10 người và còn gia đình cũng đông, kính mong các bác hoan hỷ cùng tham gia. Con xin thành kính tri ân công đức của quý Thầy cùng liên hữu đồng tu ạ.\</p\>  
        \<p class="footer-nammo"\>Nam Mô A Di Đà Phật\</p\>  
    \</div\>

    \<\!-- Thông báo kết quả đăng ký \--\>  
    \<?php if (\!empty($message)): ?\>  
        \<div class="alert"\>\<?= $message ?\>\</div\>  
    \<?php endif; ?\>

    \<\!-- Form Đăng ký \--\>  
    \<form method="POST" action=""\>  
        \<label for="fullname"\>1. Nhập Họ Tên / Pháp Danh:\</label\>  
        \<input type="text" id="fullname" name="fullname" placeholder="Ví dụ: Đắc Lực..." required autocomplete="off"\>

        \<label\>2. Bấm chọn ca đăng ký:\</label\>  
        \<button type="submit" name="shift" value="Ca Chiều (14h15)" class="btn btn-chieu"\>  
            ☀️ Đăng Ký CA CHIỀU (14h15') \<br\>\<small\>(Đã có \<?= $countChieu ?\> người đăng ký)\</small\>  
        \</button\>  
          
        \<button type="submit" name="shift" value="Ca Tối (19h15)" class="btn btn-toi"\>  
            🌙 Đăng Ký CA TỐI (19h15') \<br\>\<small\>(Đã có \<?= $countToi ?\> người đăng ký)\</small\>  
        \</button\>  
    \</form\>

    \<\!-- Danh sách công khai đã đăng ký \--\>  
    \<div class="list-box"\>  
        \<h3\>Danh Sách Phật Tử Đăng Ký\</h3\>  
          
        \<p\>\<b\>☀️ Ca Chiều 2h15' \<span class="badge"\>\<?= $countChieu ?\> người\</span\>\</b\>\</p\>  
        \<ol\>  
            \<?php   
            $hasChieu \= false;  
            foreach ($registrations as $r):   
                if ($r\['shift'\] \=== 'Ca Chiều (14h15)'):   
                    $hasChieu \= true;  
            ?\>  
                    \<li\>\<b\>\<?= $r\['name'\] ?\>\</b\> \<small style="color:\#777;"\>(\<?= $r\['time'\] ?\>)\</small\>\</li\>  
            \<?php   
                endif;   
            endforeach;   
            if (\!$hasChieu) echo "\<p style='color:\#777; font-size:18px;'\>Chưa có người đăng ký ca này.\</p\>";  
            ?\>  
        \</ol\>

        \<p\>\<b\>🌙 Ca Tối 7h15' \<span class="badge"\>\<?= $countToi ?\> người\</span\>\</b\>\</p\>  
        \<ol\>  
            \<?php   
            $hasToi \= false;  
            foreach ($registrations as $r):   
                if ($r\['shift'\] \=== 'Ca Tối (19h15)'):   
                    $hasToi \= true;  
            ?\>  
                    \<li\>\<b\>\<?= $r\['name'\] ?\>\</b\> \<small style="color:\#777;"\>(\<?= $r\['time'\] ?\>)\</small\>\</li\>  
            \<?php   
                endif;   
            endforeach;   
            if (\!$hasToi) echo "\<p style='color:\#777; font-size:18px;'\>Chưa có người đăng ký ca này.\</p\>";  
            ?\>  
        \</ol\>  
    \</div\>  
\</div\>

\</body\>  
\</html\>

## **🎯 5\. LỘ TRÌNH MỞ RỘNG TIẾP THEO (AGENT DEVELOPMENT ROADMAP)**

Khi có yêu cầu phát triển mới, Agent hãy tham khảo định hướng dưới đây để nâng cấp mã nguồn mà **vẫn giữ nguyên tiêu chí đơn giản cho người cao tuổi**:

1. **Chức năng Xóa/Hủy Đăng Ký (Nếu gõ nhầm tên):**  
   * Cho phép bấm nút "Xóa" cạnh tên người vừa đăng ký (kiểm tra đơn giản theo phiên duyệt/cookie hoặc xác nhận tên).  
2. **Trang Quản Trị Trưởng Ban Đạo Tràng (admin.php):**  
   * Đăng nhập mật khẩu đơn giản cho Trưởng ban.  
   * Xem/Xuất danh sách đăng ký dạng văn bản để copy thẳng vào nhóm Zalo.  
   * Nút bấm "Xóa tất cả danh sách" để chuẩn bị cho đợt trợ duyên ngày tiếp theo.  
3. **Quản Lý Nhiều Sự Kiện/Người Bệnh (event\_id):**  
   * Cho phép tạo nhiều link khác nhau (Ví dụ: lichdt/?event=bacX, lichdt/?event=bacY).  
4. **Nút "Gửi Lịch Sang Nhóm Zalo":**  
   * Tích hợp nút copy nhanh danh sách đã định dạng văn bản đẹp mắt để gửi vào Zalo đạo tràng.

## **🤖 6\. HƯỚNG DẪN CHO AGENT KHI NHẬN LỆNH MỚI**

Mỗi khi người dùng đưa ra câu lệnh phát triển tiếp theo, AI Agent cần:

1. Đọc file PROJECT\_AGENT\_KIT.md này để nắm toàn bộ bối cảnh và quy tắc UX/UI.  
2. Kiểm tra file index.php trong thư mục làm việc hiện tại.  
3. Thực hiện sửa đổi trực tiếp vào code, luôn test tính tương thích với di động và font chữ to cho người cao tuổi.  
4. Giữ cho cấu trúc mã gọn gàng, không cài đặt thêm framework rườm rà nếu không có yêu cầu đặc biệt.