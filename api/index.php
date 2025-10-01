<?php

$notes_directory = '/tmp';
$absolute_notes_directory = realpath($notes_directory);

if (!$absolute_notes_directory || !is_dir($absolute_notes_directory)) {
    die('Invalid save path');
}

header('Cache-Control: no-store');

// 直接定义禁止的后缀名
$prohibited_suffixes = ["text", "tg", "py"];


// API 处理逻辑
// 1. 新建随机地址文本，/?new&text=xxxx，返回新建文本的URL
if (isset($_GET['new']) && isset($_GET['text'])) {
    // 生成随机名称更更加唯一
    $random_note_name = uniqid(bin2hex(random_bytes(3)), true);
    $note_file_path = $absolute_notes_directory . DIRECTORY_SEPARATOR . $random_note_name;

    // 保存文本内容
    $note_content = $_GET['text'];
    if (strlen($note_content) > 1024 * 100) {
        die('Content too large');
    }

    try {
        if (file_put_contents($note_file_path, $note_content) === false) {
            throw new Exception("Failed to write to file."); // 使用异常处理
        }
        chmod($note_file_path, 0600); // 限制文件权限
        // 返回新建文本的 URL
        header('Content-Type: application/json');
        echo json_encode([
            'url' => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/' . $random_note_name
        ]);
        exit;
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());  // 记录错误
        header('HTTP/1.0 500 Internal Server Error');
        echo "Failed to save note.";
        exit;
    }
}

// 获取笔记的名称（通过URL参数传递）
$note_name = isset($_GET['note']) ? trim($_GET['note']) : null;

// API 处理逻辑
// 2. 新建或修改指定名称的笔记本，/name?text=xxxx，返回保存状态
if ($note_name && isset($_GET['text'])) {
    // 验证笔记名称的合法性
    if (strlen($note_name) > 64 || !preg_match('/^[a-zA-Z0-9_-]+$/', $note_name) || is_prohibited_suffix($note_name)) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid note name or prohibited suffix.'
        ]);
        exit;
    }

    // 生成唯一的文件名
    $random_string = uniqid(bin2hex(random_bytes(3)), true);  //更加严格的避免冲突
    $unique_note_name = $note_name . '_' . str_replace('.', '', $random_string);

    // 处理笔记文件路径
    $sanitized_note_name = basename($unique_note_name);  //unique_note_name
    $note_file_path = $absolute_notes_directory . DIRECTORY_SEPARATOR . $sanitized_note_name;


    // 保存或更新文本内容
    $note_content = $_GET['text'];
    if (strlen($note_content) > 1024 * 100) {
        die('Content too large');
    }

    try {
        if (file_put_contents($note_file_path, $note_content) === false) {
            throw new Exception("Failed to write to file."); // 使用异常处理
        }
        chmod($note_file_path, 0600); // 限制文件权限
        // 返回保存状态
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'message' => 'Note saved successfully.'
        ]);
        exit;
    } catch (Exception $e) {
        error_log("Error: " . $e->getMessage());  // 记录错误
        header('HTTP/1.0 500 Internal Server Error');
        echo "Failed to save note.";
        exit;
    }
}

// 验证笔记名称的合法性（为空、长度超出限制或包含非法字符）
if (!$note_name || strlen($note_name) > 64 || !preg_match('/^[a-zA-Z0-9_-]+$/', $note_name)) {
    // 如果笔记名称不合法，生成一个随机名称（6个字节），并重定向到该新名称的页面
    $random_note_name = bin2hex(random_bytes(3));
    header("Location: ./" . $random_note_name); // 重定向到 /jtb/ 路径下的新URL
    exit;
}

// 使用basename函数确保笔记名称不包含路径信息，防止目录遍历攻击
$sanitized_note_name = basename($note_name);
$note_file_path = $absolute_notes_directory . DIRECTORY_SEPARATOR . $sanitized_note_name;

// 验证文件路径是否位于笔记保存目录下，防止恶意路径注入
if (strpos($note_file_path, $absolute_notes_directory) !== 0) {
    die('Invalid note path');
}

// API 处理逻辑
// 3. 获取笔记内容，/name?raw，返回文本内容
if (isset($_GET['raw'])) {
    if (is_file($note_file_path)) {
        header('Content-Type: text/plain');
        readfile($note_file_path);
    } else {
        header('HTTP/1.0 404 Not Found');
        echo 'Note not found';
    }
    exit;
}

// 定义笔记的最大允许大小（100KB），防止上传大文件造成服务器压力
$MAX_NOTE_SIZE = 1024 * 100; // 100KB

// 处理POST请求，用户可以通过POST请求创建或更新笔记内容
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $note_content = isset($_POST['text']) ? $_POST['text'] : file_get_contents("php://input");

    // 检查内容大小是否超出限制
    if (strlen($note_content) > $MAX_NOTE_SIZE) {
        die('Content too large');
    }

    // 检查文件名后缀
    if (is_prohibited_suffix($sanitized_note_name)) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid note name or prohibited suffix.'
        ]);
        exit;
    }

    try {
        // 使用文件锁防止并发写入问题
        $file_handle = fopen($note_file_path, 'c');
        if (flock($file_handle, LOCK_EX)) {
            // 如果有内容，则写入文件；如果为空，则删除文件
            if (strlen($note_content)) {
                if (file_put_contents($note_file_path, $note_content) === false) {
                    throw new Exception("Failed to write to file.");
                }
                chmod($note_file_path, 0600); // 限制文件权限  确保在写入后设置
            } elseif (is_file($note_file_path) && !unlink($note_file_path)) {
                error_log("Failed to delete the note at path: $note_file_path");
                die('Failed to delete the note');
            }
            flock($file_handle, LOCK_UN);
        }
        fclose($file_handle);
    } catch (Exception $e) {
        error_log('Error: ' . $e->getMessage());
        die('An error occurred: ' . $e->getMessage());
    }
    exit;
}
// ... (剩余代码不变) ...
?>
