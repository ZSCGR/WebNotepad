<?php

// 设置笔记保存的目录路径，建议使用绝对路径，并确保该路径位于文档根目录之外以提高安全性。
$notes_directory = '/tmp';

// 获取保存笔记的真实路径
$absolute_notes_directory = realpath($notes_directory);

// 验证保存路径是否有效且是一个目录
if (!$absolute_notes_directory || !is_dir($absolute_notes_directory)) {
    die('Invalid save path');
}

// 禁用缓存，以确保每次访问都获取最新的笔记内容
header('Cache-Control: no-store');

// 加载禁止的后缀
$prohibited_suffixes = json_decode(file_get_contents('./prohibited_suffixes.json'), true)['prohibited_suffixes'];

// 检查文件名后缀
function is_prohibited_suffix($filename) {
    global $prohibited_suffixes;
    foreach ($prohibited_suffixes as $suffix) {
        if (substr($filename, -strlen($suffix)) === $suffix) {
            return true;
        }
    }
    return false;
}

// API 处理逻辑
// 1. 新建随机地址文本，/?new&text=xxxx，返回新建文本的URL
if (isset($_GET['new']) && isset($_GET['text'])) {
    // 生成随机名称
    $random_note_name = bin2hex(random_bytes(3));
    $note_file_path = $absolute_notes_directory . DIRECTORY_SEPARATOR . $random_note_name;

    // 保存文本内容
    $note_content = $_GET['text'];
    if (strlen($note_content) > 1024 * 100) {
        die('Content too large');
    }
    file_put_contents($note_file_path, $note_content);

    // 返回新建文本的 URL
    header('Content-Type: application/json');
    echo json_encode([
        'url' => $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/' . $random_note_name
    ]);
    exit;
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

    // 处理笔记文件路径
    $sanitized_note_name = basename($note_name);
    $note_file_path = $absolute_notes_directory . DIRECTORY_SEPARATOR . $sanitized_note_name;

    // 保存或更新文本内容
    $note_content = $_GET['text'];
    if (strlen($note_content) > 1024 * 100) {
        die('Content too large');
    }

    // 写入文件
    file_put_contents($note_file_path, $note_content);

    // 返回保存状态
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'message' => 'Note saved successfully.'
    ]);
    exit;
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
                    error_log("Failed to save note to path: $note_file_path");
                    die('Failed to save the note');
                }
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

// 检查是否请求原始笔记内容（通常通过curl或wget等命令行工具请求）
if (isset($_GET['raw']) || strpos($_SERVER['HTTP_USER_AGENT'], 'curl') === 0 || strpos($_SERVER['HTTP_USER_AGENT'], 'Wget') === 0) {
    if (is_file($note_file_path)) {
        header('Content-type: text/plain');
        readfile($note_file_path);
    } else {
        header('HTTP/1.0 404 Not Found');
    }
    exit;
}

// 判断笔记文件是否存在，以确定是否是新笔记
$is_new_note = !is_file($note_file_path);

// 读取现有笔记内容并转义特殊字符，防止XSS攻击
$note_content_escaped = is_file($note_file_path) ? htmlspecialchars(file_get_contents($note_file_path), ENT_QUOTES, 'UTF-8') : '';
$note_name_escaped = htmlspecialchars($sanitized_note_name, ENT_QUOTES, 'UTF-8');

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $note_name_escaped; ?></title>
    <link rel="icon" href="./img/ico.png" sizes="any">
    <link rel="icon" href="./img/ico.png" type="image/svg+xml">
    <style>
        body {
            margin: 0;
            background: #f5f4f1;
        }
        .container {
            display: flex;
            flex-direction: column;
            position: absolute;
            top: 0px;
            right: 0px;
            bottom: 0px;
            left: 0px;
            max-width: 1000px;
            margin: 0 auto;
            padding: 20px;
        }
        .content-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            max-height: 100%;
        }
        #content, #readonly-content {
            margin: 0;
            padding: 20px;
            overflow-y: auto;
            resize: none;
            width: 100%;
            height: 100%;
            box-sizing: border-box;
            border: 2px solid #ffffff;
            outline: none;
            background-color: #202425;
            color: #fff;
        }
        #readonly-content {
            background: #f5f4f1;
            border: 2px solid #000000;
            color: #000000;
            background-image: url('./img/Yande.re 629088.png'); 
            background-size: 260px;
            background-repeat: no-repeat;
            background-position: right bottom;
        }
        .printable {
            display: none;
        }
        .copy-success {
            position: fixed;
            bottom: 120px;
            left: 50%;
            transform: translateX(-50%);
            background: #ffffff;
            color: #000000;
            padding: 10px 20px;
            display: none;
            z-index: 1000;
            font-size: 14px;
            border: 2px solid #000000;
        }
        #qr-code-container {
            margin-top: 20px;
            display: flex;
            padding: 10px;
            border-radius: 4px;
            border: none;
            justify-content: center;
        }
        #qr-code-container img {
            background-color: white; 
            padding: 8px;    
            box-sizing: content-box; 
            border-radius: 10px; 
        }
        .encryption-container {
            margin: 20px 0;
            text-align: center;
            font-size: 16px;
            width: 100%;
            max-width: 285px;
        }


        .encryption-container button {
            padding: 10px 10px;
            border: none;
            cursor: pointer;
            transition: background-color 0.3s;
            font-weight: bold;
            border: 2px solid #202425;
        }
        .encryption-container button:hover {
            border: 2px solid #ffffff;
            background: #202425;
            color: #FFFFFF;

        }
        @media (min-width: 768px) {
            .container {
        flex-direction: row;
            }
            .content-area {
        flex: 1;
        margin-right: 20px;
        overflow-y: auto;

            }
            .encryption-container {
        flex-direction: column;
        align-self: flex-start;
        margin-top: 0;
            }
        }
        @media (prefers-color-scheme: dark) {
            body {
        background: #333b4d;
            }
            #content, #readonly-content {
        background: #24262b;
        color: #fff;
        border-color: #495265;

            }
        }
        @media (max-width: 767px) {
            .container {
        flex-direction: column;
            }
            .content-area {
        margin-right: 0;

            }
            .encryption-container {
        margin-top: 20px;
        max-width: 100%;
            }
            #qr-code-container {
        display: none;
            }
            .button-icon{
        display: none !important;
            }
            
        }

        @media print {
            .container {
        display: none;
            }
            .printable {
        display: block;
        white-space: pre-wrap;
        word-break: break-word;
            }
        }

        .button-icon {
        display: flex;
        border: 3px #fff solid;
        width: fit-content;
        height: fit-content;
        cursor: pointer;
        width: 100%;
        }

        .icon {
        background-color: #fff;
        padding: 10px 10px 5px 10px;
        }

        .icon svg {
        width: 25px;
        height: 25px;
        }

        .cube {
        transition: all 0.4s;
        transform-style: preserve-3d;
        width: 200px;
        height: 20px;
        }

        .button-icon:hover .cube {
        transform: rotateX(90deg);
        }

        .side {
        position: absolute;
        height: 45px;
        width: 240px;
        display: flex;
        font-size: 0.8em;
        justify-content: center;
        align-items: center;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: bold;
        }

        .top {
        background: #222229;
        color: #fff;
        transform: rotateX(-90deg) translate3d(0, 13.5px, 2em);
        }

        .front {
        background: #222229;
        color: #fff;
        transform: translate3d(0, 0, 1em);
        }
        .side.top a {
            color: white;
            text-decoration: none;
        }

        .card {
        background-color: rgba(217, 217, 217, 0.18);
        backdrop-filter: blur(8px);
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        width: 100%;
        }

        .terminal-header {
        background-color: #202425;
        padding: 10px 15px;
        display: flex;
        align-items: center;
        }

        .terminal-title {
        color: #ffffff;
        font-size: 14px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
        }

        .terminal-icon {
        color: #006adc;
        }

        .terminal-body {
        background-color: #202425;
        color: #ffffff;
        padding: 15px;
        font-family: "Courier New", Courier, monospace;
        }

        .command-line {
        display: flex;
        align-items: center;
        }

        .prompt {
        color: #ffffff;
        margin-right: 10px;
        }

        .input-wrapper {
        position: relative;
        flex-grow: 1;
        }

        .input-field {
        background-color: transparent;
        border: none;
        color: #006adc;
        font-family: inherit;
        font-size: 14px;
        outline: none;
        width: 100%;
        padding-right: 10px;
        }

        .input-field::placeholder {
        color: rgba(255, 255, 255, 0.5);
        }

        .input-wrapper::after {
        content: "";
        position: absolute;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 8px;
        height: 15px;
        background-color: #ffffff;
        animation: blink 1s step-end infinite;
        }

        @keyframes blink {
        0%,
        100% {
            opacity: 1;
        }
        50% {
            opacity: 0;
        }
        }

        .fancy {
        background-color: transparent;
        border: 2px solid #000;
        border-radius: 0;
        box-sizing: border-box;
        color: #fff;
        cursor: pointer;
        display: inline-block;
        float: right;
        font-weight: 700;
        letter-spacing: 1.05em;
        margin: 0;
        outline: none;
        overflow: visible;
        padding: 1.25em 2em;
        position: relative;
        text-align: center;
        text-decoration: none;
        text-transform: none;
        transition: all 0.3s ease-in-out;
        user-select: none;
        font-size: 12.5px;
        width: 50%;
        }

        .fancy::before {
        content: " ";
        width: 1.5625rem;
        height: 2px;
        background: black;
        top: 50%;
        left: 1.5em;
        position: absolute;
        transform: translateY(-50%);
        transform-origin: center;
        transition: background 0.3s linear, width 0.3s linear;
        }

        .fancy .text {
        font-size: 1.125em;
        line-height: 1.33333em;
        padding-left: 2em;
        display: block;
        text-align: left;
        transition: all 0.3s ease-in-out;
        text-transform: uppercase;
        text-decoration: none;
        color: black;
        }

        .fancy .top-key {
        height: 2px;
        width: 1.5625rem;
        top: -2px;
        left: 0.625rem;
        position: absolute;
        background: #e8e8e8;
        transition: width 0.5s ease-out, left 0.3s ease-out;
        }

        .fancy .bottom-key-1 {
        height: 2px;
        width: 1.5625rem;
        right: 1.875rem;
        bottom: -2px;
        position: absolute;
        background: #e8e8e8;
        transition: width 0.5s ease-out, right 0.3s ease-out;
        }

        .fancy .bottom-key-2 {
        height: 2px;
        width: 0.625rem;
        right: 0.625rem;
        bottom: -2px;
        position: absolute;
        background: #e8e8e8;
        transition: width 0.5s ease-out, right 0.3s ease-out;
        }

        .fancy:hover {
        color: white;
        background: black;
        }

        .fancy:hover::before {
        width: 0.9375rem;
        background: white;
        }

        .fancy:hover .text {
        color: white;
        padding-left: 1.5em;
        }

        .fancy:hover .top-key {
        left: -2px;
        width: 0px;
        }

        .fancy:hover .bottom-key-1,
        .fancy:hover .bottom-key-2 {
        right: 0;
        width: 0;
        }
        .my-textarea {
            background-image: url('./img/yande.re 926273.webp'); 
            background-size: 260px;
            background-repeat: no-repeat;
            background-position: right bottom;
        }
        @media (max-width: 500px) {
            .my-textarea {
                background-image: url(#);
                background-size: 260px;
                background-repeat: no-repeat;
                background-position: right bottom;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="content-area">
            <textarea id="content" style="display: none;"  class="my-textarea"><?php echo $note_content_escaped; ?></textarea>
            <pre id="readonly-content" contenteditable="false" style="display: none;"><?php echo $note_content_escaped; ?></pre>
        </div>
        <div class="encryption-container">

            <div class="encryption-container">
            <button id="toggle-mode">切换到编辑模式</button>
            <button id="copy-button">复制内容</button>
            <button id="copy-link-button">复制链接</button>
            </div>

            <!-- 加密解密输入框和按钮 -->
            <div class="encryption-container">


                <div class="card">
                    <div class="terminal">
                        <div class="terminal-header">
                        <span class="terminal-title">
                            <svg
                            class="terminal-icon"
                            width="16"
                            height="16"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            >
                            <path d="M4 17l6-6-6-6M12 19h8"></path>
                            </svg>
                            Password
                        </span>
                        </div>
                        <div class="terminal-body">
                        <div class="command-line">
                            <span class="prompt">password:</span>
                            <div class="input-wrapper">
                            <input
                                type="password"
                                class="input-field"
                                placeholder="Enter password"
                                id="encryption-key"
                            />
                            </div>
                        </div>
                        </div>
                    </div>
                    </div>
                    <br>
                <a class="fancy" href="#" id="encrypt-link">
                <span class="top-key"></span>
                <span class="text">加密</span>
                <span class="bottom-key-1"></span>
                <span class="bottom-key-2"></span>
                </a>

                <a class="fancy" href="#" id="decrypt-link">
                <span class="top-key"></span>
                <span class="text">解密</span>
                <span class="bottom-key-1"></span>
                <span class="bottom-key-2"></span>
                </a>

                <button id="encrypt-button" style="display: none;">加密</button>
                <button id="decrypt-button" style="display: none;">解密</button>
            </div>
<br>
            <div id="qr-code-container"></div>
            

            <div class="button-icon">
                <div class="icon">
                    <svg viewBox="0 0 24 24">
                        <path fill="#222229" d="M12 0.296997C5.37 0.296997 0 5.67 0 12.297C0 17.6 3.438 22.097 8.205 23.682C8.805 23.795 9.025 23.424 9.025 23.105C9.025 22.82 9.015 22.065 9.01 21.065C5.672 21.789 4.968 19.455 4.968 19.455C4.422 18.07 3.633 17.7 3.633 17.7C2.546 16.956 3.717 16.971 3.717 16.971C4.922 17.055 5.555 18.207 5.555 18.207C6.625 20.042 8.364 19.512 9.05 19.205C9.158 18.429 9.467 17.9 9.81 17.6C7.145 17.3 4.344 16.268 4.344 11.67C4.344 10.36 4.809 9.29 5.579 8.45C5.444 8.147 5.039 6.927 5.684 5.274C5.684 5.274 6.689 4.952 8.984 6.504C9.944 6.237 10.964 6.105 11.984 6.099C13.004 6.105 14.024 6.237 14.984 6.504C17.264 4.952 18.269 5.274 18.269 5.274C18.914 6.927 18.509 8.147 18.389 8.45C19.154 9.29 19.619 10.36 19.619 11.67C19.619 16.28 16.814 17.295 14.144 17.59C14.564 17.95 14.954 18.686 14.954 19.81C14.954 21.416 14.939 22.706 14.939 23.096C14.939 23.411 15.149 23.786 15.764 23.666C20.565 22.092 24 17.592 24 12.297C24 5.67 18.627 0.296997 12 0.296997Z"></path>
                    </svg>
                </div>
                <div class="cube">
                    <span class="side front">get source code</span>
                    <span class="side top"><a href="https://github.com/IIIStudio/WebNotepad" target="_blank">Web Notepad</a></span>
                </div>
            </div>


        </div>
    </div>
    <pre class="printable"></pre>
    <div id="copy-success" class="copy-success">内容已复制到剪贴板</div>
    <div id="copy-link-success" class="copy-success">链接已复制到剪贴板</div>

    <!-- CryptoJS库，用于加密解密 -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const textarea = document.getElementById('content');
            const readonlyContent = document.getElementById('readonly-content');
            const toggleButton = document.getElementById('toggle-mode');
            const copyButton = document.getElementById('copy-button');
            const copyLinkButton = document.getElementById('copy-link-button');
            const encryptionKeyInput = document.getElementById('encryption-key');
            const encryptButton = document.getElementById('encrypt-button');
            const decryptButton = document.getElementById('decrypt-button');
            const copySuccess = document.getElementById('copy-success');
            const copyLinkSuccess = document.getElementById('copy-link-success');
            const qrCodeContainer = document.getElementById('qr-code-container');

            let isEditMode = <?php echo $is_new_note ? 'true' : 'false'; ?>;

            if (isEditMode) {
                textarea.style.display = 'block';
                toggleButton.textContent = '切换到只读模式';
                textarea.focus();
            } else {
                readonlyContent.style.display = 'block';
            }

            toggleButton.addEventListener('click', function() {
                isEditMode = !isEditMode;
                if (isEditMode) {
                    readonlyContent.style.display = 'none';
                    textarea.style.display = 'block';
                    toggleButton.textContent = '切换到只读模式';
                    textarea.focus();
                } else {
                    readonlyContent.textContent = textarea.value;
                    readonlyContent.style.display = 'block';
                    textarea.style.display = 'none';
                    toggleButton.textContent = '切换到编辑模式';
                }
            });

            copyButton.addEventListener('click', function() {
                const textToCopy = isEditMode ? textarea.value : readonlyContent.textContent;
                copyTextToClipboard(textToCopy, copySuccess);
            });

            copyLinkButton.addEventListener('click', function() {
                const linkToCopy = window.location.href;
                copyTextToClipboard(linkToCopy, copyLinkSuccess);
            });

            // 封装的复制功能，使用 Clipboard API 并提供备用方案
            function copyTextToClipboard(text, successElement) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text)
                        .then(() => showCopySuccess(successElement))
                        .catch(() => fallbackCopyTextToClipboard(text, successElement));
                } else {
                    fallbackCopyTextToClipboard(text, successElement);
                }
            }

            // 备用复制功能，适用于不支持 Clipboard API 的情况
            function fallbackCopyTextToClipboard(text, successElement) {
                const textArea = document.createElement("textarea");
                textArea.value = text;
                textArea.style.position = "fixed"; // 避免页面滚动
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();
                try {
                    document.execCommand('copy');
                    showCopySuccess(successElement);
                } catch (err) {
                    console.error('复制失败:', err);
                }
                document.body.removeChild(textArea);
            }

            function showCopySuccess(successElement) {
                successElement.style.display = 'block';
                setTimeout(() => {
                    successElement.style.display = 'none';
                }, 2000);
            }


            // 加密和解密功能
           encryptButton.addEventListener('click', function() {
                const key = encryptionKeyInput.value;
                if (!key) {
                    alert('请输入加密密钥');
                    return;
                }
                const content = textarea.value;
                const encrypted = CryptoJS.AES.encrypt(content, key).toString();
                textarea.value = encrypted;
                if (!isEditMode) readonlyContent.textContent = encrypted;

                // 自动保存加密后的内容
                saveNoteContent(encrypted);
            });

            // 定义一个用于保存内容的函数
            function saveNoteContent(noteContent) {
                fetch(location.pathname, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'text=' + encodeURIComponent(noteContent),
                });
            }

            decryptButton.addEventListener('click', function() {
                const key = encryptionKeyInput.value;
                if (!key) {
                    alert('请输入解密密钥');
                    return;
                }
                try {
                    const encryptedContent = textarea.value;
                    const decrypted = CryptoJS.AES.decrypt(encryptedContent, key).toString(CryptoJS.enc.Utf8);
                    if (!decrypted) throw new Error();
                    textarea.value = decrypted;
                    if (!isEditMode) readonlyContent.textContent = decrypted;
                } catch (error) {
                    alert('解密失败，密钥可能不正确');
                }
            });

            new QRCode(qrCodeContainer, {
                text: window.location.href,
                width: 128,
                height: 128,
            });

            let saveTimeout = null;
            textarea.addEventListener('input', function() {
                clearTimeout(saveTimeout);
                saveTimeout = setTimeout(function() {
                    const noteContent = textarea.value;
                    fetch(location.pathname, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'text=' + encodeURIComponent(noteContent),
                    });
                }, 1000);
            });
        });
    </script>
    <script>
    document.getElementById('encrypt-link').addEventListener('click', function(event) {
        event.preventDefault(); // 阻止默认链接行为
        document.getElementById('encrypt-button').click(); // 触发加密按钮点击事件
    });

    document.getElementById('decrypt-link').addEventListener('click', function(event) {
        event.preventDefault(); // 阻止默认链接行为
        document.getElementById('decrypt-button').click(); // 触发解密按钮点击事件
    });
    </script>
</body>
</html>
