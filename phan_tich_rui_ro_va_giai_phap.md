# **🛡️ BẢN PHÂN TÍCH RỦI RO & GIẢI PHÁP VẬN HÀNH HỆ THỐNG TRỢ DUYÊN NIỆM PHẬT**

**Dự án:** Hệ thống Sắp Lịch Trợ Duyên Niệm Phật Online (High-Touch, Low-Tech)

**Môi trường:** Debian Server / PHP 8.x \+ SQLite PDO / Zalo Group

**Tác giả:** Đội ngũ Kỹ thuật & Trợ lý Ban Trợ Niệm

## **🧭 I. TRIẾT LÝ VẬN HÀNH CỐT LÕI**

Hệ thống được thiết kế theo phương châm **"High-Touch, Low-Tech"** (Tăng tương tác tình thân, giảm tối đa rào cản công nghệ). Do đặc thụ Phật tử trong Ban Trợ Niệm phần lớn là người cao tuổi, mục tiêu của trang web **không phải là kiểm soát cứng nhắc**, mà là **tạo sự tiện lợi, hoan hỷ và khuyến kích tinh thần tự nguyện**.

## **📊 II. BẢNG MÃ TRẬN RỦI RO (RISK MATRIX OVERVIEW)**

| Mã Rủi Ro | Phân Loại | Tên Rủi Ro | Mức Độ | Tần Suất | Giải Pháp Trọng Tâm |
| :---- | :---- | :---- | :---- | :---- | :---- |
| **R-01** | Tâm lý / Hành vi | Đăng ký ảo / Đăng ký rồi bỏ ca không báo | **Rất Cao** | Thường xuyên | Trưởng tràng chốt danh sách \+ Nhắc nhở tùy duyên |
| **R-02** | Tâm lý / Hành vi | Tâm lý e ngại ca quá vắng hoặc ca quá đông | **Trung bình** | Thường xuyên | Thông điệp linh hoạt "Tùy duyên đăng ký thêm" |
| **R-03** | Thao tác | Gõ sai tên / Nhờ đăng ký hộ gây trùng | **Trung bình** | Thường xuyên | Thành viên trẻ hỗ trợ \+ Tính năng xóa/sửa nhẹ |
| **R-04** | Kỹ thuật / DB | Khóa ghi CSDL (SQLite Write Lock) | **Thấp** | Hiếm gặp | Bật chế độ SQLite WAL (Write-Ahead Logging) |
| **R-05** | Kỹ thuật / Server | Mất quyền ghi thư mục database.sqlite trên Debian | **Cao** | Thi thoảng | Script cấp quyền www-data & Unit Test tự động |
| **R-06** | An ninh / Spam | Link bị phát tán / Người ngoài gõ phá | **Trung bình** | Thô sơ | Khóa xóa cứng (Chỉ Admin mới có quyền xóa hàng loạt) |
| **R-07** | Quy trình | Trôi tin nhắn Zalo / Quên đóng đợt cũ | **Cao** | Thi thoảng | 1-Click Copy Zalo chuẩn \+ Cảnh báo trên Admin |

## **🔍 III. PHÂN TÍCH CHI TIẾT 4 NHÓM RỦI RO & GIẢI PHÁP TƯƠNG ỨNG**

### **1\. Nhóm Rủi Ro Tâm Lý & Hành Vi Phật Tử Cao Tuổi**

#### **⚠️ R-01: Đăng ký ca nhưng bận đột xuất không tham gia (Hoặc quên không hủy trên web)**

* **Nguyên nhân:** Các cụ lớn tuổi thường bận việc gia đình, thời tiết xấu, sức khỏe thay đổi hoặc không quen quay lại web để bấm nút "Xóa".  
* **Hệ quả:** Ca trợ duyên hiển thị đủ 10 người nhưng thực tế đến nhà người bệnh chỉ có 5-6 người.  
* **Giải pháp ứng xử (High-Touch):**  
  1. **Quy trình "Trợ lý nhắc ca":** Trước giờ hành trì 1 tiếng (VD: 13h15 cho ca 14h15), một thành viên trẻ trong Ban điều hành chụp ảnh danh sách trên web dán vào nhóm Zalo kèm câu nhắn: *"A Di Đà Phật, kính mời 10 vị có tên trong ca Chiều chuẩn bị xuất phát ạ"*.  
  2. **Giải pháp kỹ thuật:** Giữ nút "Xóa" đơn giản ngay cạnh tên. Người đăng ký hộ hoặc Ban điều hành đều có thể bấm xóa giúp khi có thông báo bận.

#### **⚠️ R-02: Tâm lý "Chờ người khác đăng ký trước" hoặc "Ngại đăng ký khi thấy ca đã đông"**

* **Nguyên nhân:** Đạo tràng có tâm lý nhường nhịn hoặc ngại mình làm phiền gia đình người bệnh.  
* **Hệ quả:** Ca Chiều 0 người, Ca Tối 0 người do ai cũng chờ xem có ai đi trước không.  
* **Giải pháp ứng xử (High-Touch & UI/UX):**  
  1. **Hiển thị chỉ tiêu mềm:** Web hiển thị rõ dòng chữ nhẹ nhàng: *"Ca đã đủ chỉ tiêu 10 vị. Quý liên hữu hoan hỷ tùy duyên đăng ký thêm nếu thuận tiện\!"*.  
  2. **Tác động từ Ban Điều Hành:** Trưởng tràng, Phó tràng hoặc các thành viên nòng cốt luôn là những người tiên phong gõ tên đăng ký đầu tiên ngay khi phát động đợt trợ duyên mới.

### **2\. Nhóm Rủi Ro Thao Tác Người Dùng (User Experience Risks)**

#### **⚠️ R-03: Nhầm lẫn khi thao tác (Lỡ bấm xóa tên người khác hoặc gõ sai tên)**

* **Nguyên nhân:** Màn hình điện thoại nhỏ, mắt kém hoặc bấm nhầm nút "Xóa" bên cạnh tên liên hữu khác.  
* **Giải pháp kỹ thuật & Vận hành:**  
  1. **Xác nhận trước khi xóa:** Tích hợp đoạn mã JavaScript confirm('Xác nhận xóa lượt đăng ký này?') để ngăn việc chạm nhầm ngón tay.  
  2. **Lưu vết thao tác (logs):** Mọi hành động thêm/xóa đều lưu vào bảng logs trong SQLite. Nếu lỡ xóa nhầm, Ban điều hành có thể mở nhật ký hệ thống để kiểm tra và thêm lại.

### **3\. Nhóm Rủi Ro Hạ Tầng Kỹ Thuật (Debian Server & SQLite)**

#### **⚠️ R-04: Lỗi Phân Quyền File System trên Debian Server (Permission Denied)**

* **Nguyên nhân:** Khi khởi động lại server hoặc cập nhật code via Git/Aider dưới user root, tệp database.sqlite có thể bị đổi quyền sở hữu, khiến Web Server (Apache/Nginx run dưới www-data) không thể ghi dữ liệu.  
* **Giải pháp kỹ thuật khắc phục triệt để:**  
  1. **Tự động cấp quyền nâng cao:** Đảm bảo thư mục dự án luôn được cấp quyền cho www-data:  
     sudo chown \-R www-data:www-data /srv/SapLichDT  
     sudo chmod \-R 775 /srv/SapLichDT

  2. **Công cụ tự động kiểm thử (?mode=test):** Trước mỗi đợt trợ duyên lớn, Trưởng ban chỉ cần bấm nút *"🧪 Chạy Automated Test"* trên trang Admin. Nếu hệ thống báo PASS ✅ nghĩa là CSDL hoàn toàn sẵn sàng ghi/đọc.

#### **⚠️ R-05: Trùng lặp truy cập đồng thời (SQLite Concurrency Lock)**

* **Nguyên nhân:** Khi gửi link vào nhóm Zalo, 20-30 Phật tử cùng mở web và nhấn nút đăng ký trong cùng một giây.  
* **Giải pháp kỹ thuật:**  
  Kích hoạt chế độ **WAL Mode (Write-Ahead Logging)** của SQLite ngay trong kết nối PDO PHP để tăng khả năng xử lý ghi đồng thời:  
  $pdo-\>exec("PRAGMA journal\_mode \= WAL;");

### **4\. Nhóm Rủi Ro Quy Trình Truyền Thông (Zalo & Single Source of Truth)**

#### **⚠️ R-06: Thông tin ca trực bị trôi trong tin nhắn nhóm Zalo**

* **Nguyên nhân:** Nhóm Zalo trò chuyện nhiều làm trôi link đăng ký, dẫn đến Phật tử gõ tin nhắn rải rác thay vì bấm vào link.  
* **Giải pháp vận hành:**  
  1. **Ghim tin nhắn duy nhất:** Trưởng tràng chỉ ghim 1 tin nhắn duy nhất chứa Link Web (https://domain/lichdt/?event\_id=X).  
  2. **1-Click Zalo Template:** Trang Admin cung cấp nút *"📲 Sao chép tin nhắn Zalo"*. Khi cần thông báo, Ban điều hành chỉ cần bấm 1 nút và dán đè vào tin nhắn ghim trên Zalo.

## **🚑 IV. QUY TRÌNH XỬ LÝ SỰ CỐ KHẨN CẤP (SOP \- STANDARD OPERATING PROCEDURE)**

Sơ đồ xử lý nhanh khi gặp sự cố trong lúc đang trợ duyên:

\[Phát sinh sự cố\]  
       │  
       ├─► Lỗi Web không lưu / Báo lỗi 500  
       │     └─► BƯỚC 1: Mở Admin \-\> Bấm "🧪 Chạy Automated Test"  
       │     └─► BƯỚC 2: Mở SSH Terminal Debian \-\> Run: \`sudo chown \-R www-data:www-data /srv/SapLichDT\`  
       │  
       ├─► Lịch thay đổi gấp do Gia đình/Thời tiết  
       │     └─► BƯỚC 1: Vào trang Admin (?mode=admin)  
       │     └─► BƯỚC 2: Sửa trực tiếp Giờ Ca / Ghi Chú \-\> Bấm "💾 Cập Nhật"  
       │     └─► BƯỚC 3: Bấm "📲 Sao chép tin nhắn Zalo" \-\> Dán vào nhóm Zalo  
       │  
       └─► Quá nhiều người lớn tuổi không đăng ký được qua web  
             └─► Ban điều hành / Thành viên trẻ gõ tên đăng ký hộ trên điện thoại của mình.

## **📋 V. KẾ HOẠCH BẢO TRÌ VÀ DỰ PHÒNG DÀI HẠN**

1. **Sao lưu Cơ sở dữ liệu tự động (Daily SQLite Backup):**  
   Cài đặt Cronjob trên Debian Server để tự động sao lưu tệp database.sqlite mỗi ngày vào lúc 00:00:  
   0 0 \* \* \* cp /srv/SapLichDT/database.sqlite /srv/SapLichDT/backups/sqlite\_$(date \+\\%Y\\%m\\%d).db

2. **Duy trì Tài liệu PROJECT\_AGENT\_KIT.md:**  
   Mọi thay đổi tính năng sau này qua AI Agent (Aider / Antigravity IDE) đều phải được cập nhật lại vào PROJECT\_AGENT\_KIT.md để đảm bảo hệ thống duy trì tính kế thừa lâu dài.

Nam Mô A Di Đà Phật\!