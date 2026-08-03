# **📖 PROJECT AGENT KIT: HỆ THỐNG WEB SẮP LỊCH TRỢ DUYÊN NIỆM PHẬT**

**Môi trường:** Debian Server / PHP 8.x \+ SQLite PDO / Antigravity IDE & Aider CLI

**Triết lý cốt lõi:** High-Touch, Low-Tech (Càng đơn giản càng tốt, chữ to, 1-click, không mật khẩu).

## **🏛️ 1\. Cấu Trúc Tổng Quan Dự Án**

Dự án được thiết kế dưới dạng **Single-File Architecture** kết hợp Cơ sở dữ liệu **SQLite vĩnh viễn (database.sqlite)**.

/srv/SapLichDT/ (hoặc thư mục web)  
├── index.php             \# Mã nguồn chính (Public View, Admin Dashboard, Test Runner, Agent Kit)  
├── database.sqlite       \# Cơ sở dữ liệu SQLite (Tự động khởi tạo nếu chưa có)  
└── PROJECT\_AGENT\_KIT.md  \# Tài liệu định hướng cho AI Agent / Antigravity IDE

## **🗄️ 2\. Sơ Đồ Cơ Sở Dữ Liệu SQLite (database.sqlite)**

### **Bảng events (Đợt Trợ Duyên)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* patient\_name: TEXT (Tên bác/cụ được trợ duyên, ví dụ: "Bác X")  
* address: TEXT (Địa chỉ nhà riêng)  
* note: TEXT (Ghi chú dặn dò)  
* status: TEXT DEFAULT 'active' ('active' | 'completed')  
* created\_at: DATETIME

### **Bảng shifts (Các Ca/Thời Niệm Phật)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* event\_id: INTEGER (Khóa ngoại tham chiếu events.id)  
* shift\_name: TEXT ("Ca Chiều", "Ca Tối")  
* shift\_time: TEXT ("14h15'", "19h15'")  
* max\_target: INTEGER DEFAULT 10 (Chỉ tiêu khuyến nghị mềm)  
* shift\_date: DATE

### **Bảng registrations (Lượt Đăng Ký Của Phật Tử)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* shift\_id: INTEGER (Khóa ngoại tham chiếu shifts.id)  
* fullname: TEXT (Họ tên Phật tử)  
* phone: TEXT  
* role\_type: TEXT DEFAULT 'Thành viên'  
* registered\_at: DATETIME

## **🎯 3\. Bảng Trạng Thái Nhiệm Vụ (Task Tracking Board)**

| Nhiệm vụ | Loại | Trạng thái | Ghi chú |
| :---- | :---- | :---- | :---- |
| Khởi tạo CSDL SQLite tự động via PDO | System | **COMPLETED** | Không cần MySQL daemon |
| Giao diện Phật tử cao tuổi (Font 20px+, 1-click) | UI/UX | **COMPLETED** | Nút Cam (Chiều), Nút Xanh (Tối) |
| Đăng ký không mật khẩu & Nhận phản hồi instant | Core | **COMPLETED** | Lưu vĩnh viễn vào SQLite |
| Cảnh báo giới hạn mềm 10 người (Không chặn cứng) | Logic | **COMPLETED** | Hiện thông báo "Tùy duyên đăng ký thêm" |
| Trang Quản Trị Admin (?mode=admin) | Feature | **COMPLETED** | Tạo đợt mới, đóng đợt cũ |
| Công cụ 1-Click Copy Mẫu Tin Nhắn Zalo | Feature | **COMPLETED** | Chuẩn câu chữ đạo tràng |
| Tích hợp Unit Test tự động (?mode=test) | Test | **COMPLETED** | Kiểm tra CRUD SQLite |
| Tích hợp Zalo ZNS / Zalo Login | Expansion | **TODO** | Mở rộng tương lai khi đạo tràng yêu cầu |

## **🛠️ 4\. Chỉ Thị Dành Cho AI Agent / Antigravity IDE Khi Tiếp Nhuận**

Khi Antigravity IDE hoặc Aider thực hiện lệnh tiếp theo:

1. **Tuyệt đối duy trì triết lý High-Touch, Low-Tech**: Không thêm rào cản captcha, không bắt đăng nhập tài khoản phức tạp đối với Phật tử cao tuổi.  
2. **Một Link Duy Nhất (Single Source of Truth)**: Luôn giữ parameter ?event\_id=X để khi gửi link Zalo, người xem F5 luôn ra đúng dữ liệu mới nhất.  
3. **An toàn SQLite**: Tất cả thao tác CSDL phải dùng PDO Prepared Statements để chống SQL Injection.  
4. **Kiểm thử**: Luôn chạy php index.php hoặc truy cập ?mode=test sau mỗi lần refactor mã nguồn.

Nam Mô A Di Đà Phật\!