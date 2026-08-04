# **📖 PROJECT AGENT KIT: HỆ THỐNG WEB SẮP LỊCH TRỢ DUYÊN NIỆM PHẬT (v4.0)**

**Môi trường:** Debian Server / PHP 8.x \+ SQLite PDO (WAL Mode) / Antigravity IDE & Aider CLI

**Triết lý cốt lõi:** High-Touch, Low-Tech | Multi-Role Access Control & Zalo Browser Guard

## **🏛️ 1\. Cấu Trúc Tổng Quan Dự Án**

Dự án sử dụng kiến trúc **Single-File Architecture** kết hợp Cơ sở dữ liệu **SQLite vĩnh viễn (database.sqlite)**.

/srv/SapLichDT/ (hoặc thư mục web)  
├── index.php                         \# Mã nguồn chính (Zalo Browser Guard, Onboarding, RBAC Guard, Admin Dashboard)  
├── database.sqlite                   \# Cơ sở dữ liệu SQLite tự động tạo (5 bảng \- Bật WAL Mode)  
├── phan\_tich\_rui\_ro\_va\_giai\_phap.md  \# Tài liệu quy trình phân tích rủi ro & giải pháp vận hành  
└── PROJECT\_AGENT\_KIT.md              \# Tài liệu cấu trúc & quản lý nhiệm vụ cho AI Agent

## **🗄️ 2\. Sơ Đồ Cơ Sở Dữ Liệu SQLite (database.sqlite)**

### **Bảng members (Danh Sách Thành Viên & Phân Quyền)**

* id: INTEGER PRIMARY KEY AUTOINCREMENT  
* zalo\_id: TEXT UNIQUE NOT NULL (Định danh Zalo)  
* fullname: TEXT NOT NULL (Họ tên Phật tử)  
* phone: TEXT  
* role: TEXT DEFAULT 'member' ('super\_admin' | 'admin' | 'support' | 'member')  
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

| **Nhiệm vụ** | **Loại** | **Trạng thái** | **Ghi chú** |
| :---- | :---- | :---- | :---- |
| **Tự động gán Super Admin cho người đăng ký đầu tiên** | Security | **COMPLETED** | First Registered Member \= Super Admin |
| **Phân quyền đa cấp: Super Admin, Admin, Support, Member** | Security | **COMPLETED** | Bảng quản lý phân quyền trong Màn hình Admin |
| **Chặn Thành viên thường truy cập Quản trị & Thống kê** | Security | **COMPLETED** | Rào cản phân quyền tự động |
| **Khóa truy cập ngoài Zalo Browser** | Security | **COMPLETED** | Bắt buộc mở trực tiếp từ Zalo App |
| **Màn hình Đăng ký Thành viên (members)** | Onboarding | **COMPLETED** | Nhận diện danh tính vĩnh viễn |
| **Cảnh báo trùng tên & Xác nhận đổi tên** | Validation | **COMPLETED** | Tránh trùng danh tính |
| **Chống đăng ký trùng 1 người / ca** | Validation | **COMPLETED** | 1 Person \= 1 Shift Guard |
| **Đăng ký hộ người khác** | Feature | **COMPLETED** | Nhập tên người nhờ đăng ký hộ |
| **Biểu tượng Badge Icon ⭐ \[Thành viên\]** | UI/UX | **COMPLETED** | Hiển thị trong danh sách ca |

## **🛠️ 4\. Chỉ Thị Dành Cho AI Agent / Antigravity IDE**

1. **Tuân thủ Cơ chế Phân Quyền (RBAC)**: Chỉ cho phép tài khoản có role thuộc super\_admin, admin, hoặc support thực hiện các thao tác sửa/xóa/tạo sự kiện trong Admin.  
2. **Khóa Zalo WebView**: Giữ vững logic nhận diện Zalo Browser qua Cookie & LocalStorage.  
3. **An toàn SQLite**: Mọi câu lệnh SQL phải dùng Prepared Statements.

Nam Mô A Di Đà Phật\!