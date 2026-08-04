# **📖 PROJECT AGENT KIT: HỆ THỐNG WEB SẮP LỊCH TRỢ DUYÊN NIỆM PHẬT (v3.0)**

**Môi trường:** Debian Server / PHP 8.x \+ SQLite PDO (WAL Mode) / Antigravity IDE & Aider CLI

**Triết lý cốt lõi:** High-Touch, Low-Tech | Zalo Enforcer & Member Verification System

## **🏛️ 1\. Cấu Trúc Tổng Quan Dự Án**

Dự án sử dụng kiến trúc **Single-File Architecture** kết hợp Cơ sở dữ liệu **SQLite vĩnh viễn (database.sqlite)**.

/srv/SapLichDT/ (hoặc thư mục web)  
├── index.php                         \# Mã nguồn chính (Zalo Browser Guard, Member Onboarding, Public View, Admin Dashboard)  
├── database.sqlite                   \# Cơ sở dữ liệu SQLite tự động tạo (5 bảng \- Bật WAL Mode)  
├── phan\_tich\_rui\_ro\_va\_giai\_phap.md  \# Tài liệu quy trình phân tích rủi ro & giải pháp vận hành  
└── PROJECT\_AGENT\_KIT.md              \# Tài liệu cấu trúc & quản lý nhiệm vụ cho AI Agent

## **🗄️ 2\. Sơ Đồ Cơ Sở Dữ Liệu SQLite (database.sqlite)**

### **Bảng members (Danh Sách Thành Viên Đạo Tràng)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* zalo\_id: TEXT UNIQUE NOT NULL (Định danh thiết bị/Zalo)  
* fullname: TEXT NOT NULL (Họ tên Phật tử)  
* phone: TEXT  
* created\_at: DATETIME

### **Bảng events (Đợt Trợ Duyên)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* patient\_name: TEXT (Tên bác/cụ được trợ duyên)  
* address: TEXT (Địa chỉ nhà riêng)  
* note: TEXT (Lời dặn Ban điều hành)  
* status: TEXT DEFAULT 'active' ('active' | 'completed')  
* created\_at: DATETIME

### **Bảng shifts (Các Ca/Thời Niệm Phật)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* event\_id: INTEGER (Khóa ngoại events.id)  
* shift\_name: TEXT ("Ca Chiều", "Ca Tối", "Ca Sáng")  
* shift\_time: TEXT ("14h15'", "19h15'")  
* max\_target: INTEGER DEFAULT 10 (Chỉ tiêu ưu tiên mềm)  
* shift\_date: DATE

### **Bảng registrations (Lượt Đăng Ký Của Phật Tử)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* shift\_id: INTEGER (Khóa ngoại shifts.id)  
* fullname: TEXT (Họ tên người đi trợ duyên)  
* role\_type: TEXT DEFAULT 'Thành viên'  
* zalo\_id: TEXT (Khóa ngoại tham chiếu members.zalo\_id)  
* is\_behalf: INTEGER DEFAULT 0 (1 \= Đăng ký hộ người khác)  
* registered\_by\_zalo\_id: TEXT (Zalo ID của người thao tác)  
* registered\_at: DATETIME

### **Bảng logs (Nhật Ký Hệ Thống / Audit Trail)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* action: TEXT  
* details: TEXT  
* created\_at: DATETIME

## **🎯 3\. Bảng Trạng Thái Nhiệm Vụ (Task Tracking Board)**

| Nhiệm vụ | Loại | Trạng thái | Ghi chú |
| :---- | :---- | :---- | :---- |
| **Khóa truy cập ngoài Zalo Browser** | Security | **COMPLETED** | Chỉ cho phép mở đăng ký từ Zalo App |
| **Màn hình Đăng ký Thành viên (members)** | Onboarding | **COMPLETED** | Đăng ký thông tin danh tính ban đầu |
| **Cảnh báo trùng tên & Xác nhận đổi tên** | Validation | **COMPLETED** | Ngăn ngừa trùng lặp danh tính |
| **Chống đăng ký trùng 1 người 2 lần / ca** | Validation | **COMPLETED** | Chặn 1 person \= 1 shift |
| **Tính năng Đăng ký hộ người khác** | Feature | **COMPLETED** | Tự do nhập tên người được đăng ký hộ |
| **Biểu tượng Badge Icon ⭐ \[Thành viên\]** | UI/UX | **COMPLETED** | Hiển thị cạnh tên trong danh sách ca |
| Điều chỉnh chỉ tiêu ưu tiên từng Ca & Đợt | Feature | **COMPLETED** | Tùy biến max\_target từng Ca |
| Tích hợp Automated Test Runner (?mode=test) | Test | **COMPLETED** | Kiểm tra 5 bảng SQLite |

## **🛠️ 4\. Chỉ Thị Dành Cho AI Agent / Antigravity IDE**

1. **Tuân thủ Zalo Browser Restriction**: Giữ vững logic chặn truy cập ngoài ứng dụng Zalo (trừ khi có ?bypass\_zalo=1 hoặc chế độ Admin).  
2. **Xác minh Thành viên**: Luôn ưu tiên tra cứu danh tính qua zalo\_id trong LocalStorage và bảng members.  
3. **An toàn SQLite**: Thực thi Prepared Statements chống SQL Injection.

Nam Mô A Di Đà Phật\!