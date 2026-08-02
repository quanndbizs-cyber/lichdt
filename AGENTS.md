# AGENTS

## Global Rules

### Quy ước Git theo Issue (hoặc Task thực hiện)

Áp dụng như global rule khi làm việc theo danh sách Issue:

- mỗi Issue phải có branch riêng
- mỗi Issue phải có commit riêng
- không gộp 2 Issue vào cùng 1 branch hoặc cùng 1 commit
- sau khi xong từng Issue, phải báo rõ:
  - branch đã tạo
  - commit hash
  - các file đã sửa
  - cách test đã chạy

### Quy tắc chọn base branch

- nếu user giao nhiều Issue liên tiếp và Issue sau có chủ đích kế thừa kết quả của Issue trước, branch mới phải được tạo từ branch Issue gần nhất vừa hoàn thành trong chuỗi đó
- nếu Issue mới độc lập, không có yêu cầu kế thừa, hoặc user chỉ giao 1 Issue riêng lẻ, branch mới phải được tạo từ branch gốc hiện hành đã được user/team dùng làm base cho đợt làm việc
- nếu worktree đang có thay đổi dở dang, conflict base branch, hoặc chưa rõ Issue mới có phụ thuộc Issue trước hay không, phải confirm lại trước khi tạo branch để tránh chồng sai nền

### Quy tắc đặt tên branch

- format bắt buộc: `codex/{IssueNo}_{IssueName}`
- chuẩn hóa `IssueName` theo thứ tự:
  - thay dấu cách, dấu `,` và dấu `:` bằng `_`
  - loại bỏ ký tự không hợp lệ khi đặt tên branch Git
  - nếu sau khi thay thế xuất hiện chuỗi `_-_` thì đổi thành `-`
  - giữ nguyên chữ có dấu nếu Git vẫn chấp nhận hợp lệ
- ví dụ:
  - Issue No: `129`
  - IssueName: `Màn xử lý thiếu: Loại bỏ các mục hiển thị trùng lặp`
  - Branch: `codex/129_Màn_xử_lý_thiếu_Loại_bỏ_các_mục_hiển_thị_trùng_lặp`

### Quy tắc thực thi

- trước khi sửa code cho một Issue, mặc định phải tạo đúng branch của Issue đó trước
- hoàn thành code và test cơ bản xong mới tạo commit cho đúng Issue tương ứng
- không amend hoặc nhét thêm thay đổi của Issue khác vào commit đã tạo, trừ khi user yêu cầu rõ
- nếu một Issue buộc phải phụ thuộc Issue trước để chạy đúng, cần nêu rõ branch kế thừa nào đã được dùng làm base khi báo cáo kết quả
- nếu chưa thể chạy đủ test, phải nói rõ đã chạy test nào, thiếu test nào, và lý do
- khi làm nhiều Issue trong một chuỗi, sau mỗi Issue phải dừng ở trạng thái branch/commit của chính Issue đó để có thể review hoặc tách tiếp nhánh kế thừa cho Issue sau
