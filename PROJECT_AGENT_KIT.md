# **📖 PROJECT AGENT KIT: HỆ THỐNG WEB SẮP LỊCH TRỢ DUYÊN NIỆM PHẬT (v2.1)**

**Môi trường:** Debian Server / PHP 8.x \+ SQLite PDO (WAL Mode) / Antigravity IDE & Aider CLI

**Triết lý cốt lõi:** High-Touch, Low-Tech (Càng đơn giản càng tốt, chữ to, 1-click, không mật khẩu).

## **🏛️ 1\. Cấu Trúc Tổng Quan Dự Án**

Dự án sử dụng kiến trúc **Single-File Architecture** kết hợp Cơ sở dữ liệu **SQLite vĩnh viễn (database.sqlite)**.

/srv/SapLichDT/ (hoặc thư mục web)  
├── index.php                         \# Mã nguồn chính (Public View, Admin Dashboard, Stats, Test Runner, Agent Kit)  
├── database.sqlite                   \# Cơ sở dữ liệu SQLite tự động tạo (Bật WAL Mode)  
├── phan\_tich\_rui\_ro\_va\_giai\_phap.md  \# Tài liệu quy trình phân tích rủi ro & giải pháp vận hành  
└── PROJECT\_AGENT\_KIT.md              \# Tài liệu cấu trúc & quản lý nhiệm vụ cho AI Agent

## **🗄️ 2\. Sơ Đồ Cơ Sở Dữ Liệu SQLite (database.sqlite)**

### **Bảng events (Đợt Trợ Duyên)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* patient\_name: TEXT (Tên bác/cụ được trợ duyên)  
* address: TEXT (Địa chỉ nhà riêng)  
* note: TEXT (Lời dặn Ban điều hành)  
* status: TEXT DEFAULT 'active' ('active' | 'completed')  
* created\_at: DATETIME

### **Bảng shifts (Các Ca/Thời Niệm Phật)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* event\_id: INTEGER (Khóa ngoại tham chiếu events.id)  
* shift\_name: TEXT ("Ca Chiều", "Ca Tối", "Ca Sáng")  
* shift\_time: TEXT ("14h15'", "19h15'")  
* max\_target: INTEGER DEFAULT 10 (Số lượng người ưu tiên tùy chỉnh cho từng ca)  
* shift\_date: DATE

### **Bảng registrations (Lượt Đăng Ký Của Phật Tử)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* shift\_id: INTEGER (Khóa ngoại tham chiếu shifts.id)  
* fullname: TEXT (Họ tên Phật tử)  
* phone: TEXT  
* role\_type: TEXT DEFAULT 'Thành viên' ('Thành viên' | 'Ban điều hành')  
* registered\_at: DATETIME

### **Bảng logs (Nhật Ký Hệ Thống / Audit Trail)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* action: TEXT (Hành động thực hiện)  
* details: TEXT (Mô tả chi tiết)  
* created\_at: DATETIME

## **🎯 3\. Bảng Trạng Thái Nhiệm Vụ (Task Tracking Board)**

| Nhiệm vụ | Loại | Trạng thái | Ghi chú |
| :---- | :---- | :---- | :---- |
| Khởi tạo CSDL SQLite tự động via PDO (4 bảng) | System | **COMPLETED** | Bật chế độ PRAGMA journal\_mode \= WAL |
| Giao diện Phật tử cao tuổi (Font 20px+, 1-click) | UI/UX | **COMPLETED** | Nút Cam (Chiều), Nút Xanh (Tối) |
| Đăng ký không mật khẩu & Nhận phản hồi instant | Core | **COMPLETED** | Lưu vĩnh viễn vào SQLite |
| Cảnh báo giới hạn mềm (Tùy duyên đăng ký thêm) | Logic | **COMPLETED** | Không chặn cứng lượt đăng ký |
| **Điều chỉnh chỉ tiêu số người ưu tiên từng Ca & Đợt** | Feature | **COMPLETED** | Tùy biến max\_target từng Ca độc lập |
| Cập nhật giờ ca/ghi chú linh hoạt cho đợt đang chạy | Feature | **COMPLETED** | Không mất danh sách đã đăng ký |
| Thêm ca đột xuất linh hoạt (Ca Sáng, Ca Đột Xuất) | Feature | **COMPLETED** | Nhập tên ca, giờ ca và chỉ tiêu riêng |
| Công cụ 1-Click Copy Mẫu Tin Nhắn Zalo | Feature | **COMPLETED** | Tự tạo mẫu tin nhắn hiển thị chỉ tiêu từng ca |
| Thống kê dữ liệu dài hạn (?mode=stats) | Feature | **COMPLETED** | Xếp hạng số lượt đi trợ duyên của Phật tử |
| Ghi nhật ký thao tác hệ thống (logs) | Audit | **COMPLETED** | Hàm logAction() tự động ghi vết |
| Tích hợp Automated Test (?mode=test) | Test | **COMPLETED** | Kiểm tra CRUD & CSDL SQLite |

## **🛠️ 4\. Chỉ Thị Dành Cho AI Agent / Antigravity IDE Khi Tiếp Nhuận**

1. **Thực thi nguyên tắc High-Touch, Low-Tech**: Giữ nguyên tính năng đăng ký 1-click không cần đăng nhập cho Phật tử cao tuổi.  
2. **Quản lý chỉ tiêu mềm**: max\_target chỉ mang tính chất hướng dẫn ưu tiên số lượng phù hợp với không gian nhà bệnh nhân, tuyệt đối không dùng cờ khóa đăng ký khi vọt qua chỉ tiêu.  
3. **Giữ an toàn SQLite**: Mọi thao tác truy vấn đều phải qua PDO Prepared Statements.  
4. **Kiểm thử thường xuyên**: Kiểm tra hệ thống bằng php index.php hoặc URL ?mode=test.

Nam Mô A Di Đà Phật\!