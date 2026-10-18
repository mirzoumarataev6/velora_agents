<?php

/**
 * Проверяет, что правка файла изменила только комментарии, а не код.
 *
 * Использование:
 *   php scripts/verify-comments-only.php <путь-к-файлу> [--base=<git-ref>]
 *
 * Сравнивает текущее содержимое файла с его версией в указанной git-ссылке
 * (по умолчанию main). Для PHP токенизирует оба варианта через token_get_all,
 * отбрасывает T_COMMENT/T_DOC_COMMENT и "пробельные" токены (T_WHITESPACE,
 * T_OPEN_TAG с хвостовым переводом строки) и сравнивает оставшиеся токены.
 * Для Blade удаляет блоки {{-- ... --}} и <!-- ... --> и схлопывает пробелы.
 * Для JS/CSS вырезает блочные и строчные комментарии (с учётом строк/регулярок)
 * и схлопывает пробелы.
 *
 * Код выхода 0 — совпадает (только комментарии изменились), 1 — есть различия
 * в исполняемом коде, 2 — ошибка использования/чтения файлов.
 */

function fail(string $msg): void
{
    fwrite(STDERR, $msg . "\n");
    exit(2);
}

$argv0 = array_shift($argv);
$base = 'main';
$path = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--base=')) {
        $base = substr($arg, strlen('--base='));
    } else {
        $path = $arg;
    }
}

if ($path === null) {
    fail("Usage: php scripts/verify-comments-only.php <file> [--base=<ref>]");
}

if (!file_exists($path)) {
    fail("File not found: $path");
}

$newContent = file_get_contents($path);
if ($newContent === false) {
    fail("Could not read: $path");
}

$oldContent = shell_exec('git show ' . escapeshellarg($base . ':' . $path) . ' 2>/dev/null');
if ($oldContent === null || $oldContent === '') {
    // Файл новый (не существовал в base) — считаем проверку пройденной.
    echo "OK (new file, nothing to compare): $path\n";
    exit(0);
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

function stripPhpToCodeTokens(string $src): array
{
    $tokens = @token_get_all($src);
    $out = [];
    foreach ($tokens as $t) {
        if (is_array($t)) {
            [$id, $text] = $t;
            if ($id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_WHITESPACE) {
                continue;
            }
            $out[] = $text;
        } else {
            $out[] = $t;
        }
    }
    return $out;
}

function stripBladeComments(string $src): string
{
    $src = preg_replace('/\{\{--.*?--\}\}/s', '', $src);
    $src = preg_replace('/<!--.*?-->/s', '', $src);
    $src = preg_replace('/\s+/', ' ', $src);
    return trim($src);
}

function stripJsCssComments(string $src): string
{
    $len = strlen($src);
    $out = '';
    $i = 0;
    $inStr = null; // кавычка строки или null
    while ($i < $len) {
        $c = $src[$i];
        $next = $i + 1 < $len ? $src[$i + 1] : '';
        if ($inStr !== null) {
            $out .= $c;
            if ($c === '\\' && $i + 1 < $len) {
                $out .= $next;
                $i += 2;
                continue;
            }
            if ($c === $inStr) {
                $inStr = null;
            }
            $i++;
            continue;
        }
        if ($c === '"' || $c === "'" || $c === '`') {
            $inStr = $c;
            $out .= $c;
            $i++;
            continue;
        }
        if ($c === '/' && $next === '/') {
            while ($i < $len && $src[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($c === '/' && $next === '*') {
            $i += 2;
            while ($i + 1 < $len && !($src[$i] === '*' && $src[$i + 1] === '/')) {
                $i++;
            }
            $i += 2;
            continue;
        }
        $out .= $c;
        $i++;
    }
    $out = preg_replace('/\s+/', ' ', $out);
    return trim($out);
}

$phpExts = ['php'];
$bladeExts = ['blade'];
$jsCssExts = ['js', 'css', 'mjs', 'cjs'];

$isBlade = str_ends_with($path, '.blade.php');

if ($isBlade) {
    $oldNorm = stripBladeComments($oldContent);
    $newNorm = stripBladeComments($newContent);
    if ($oldNorm === $newNorm) {
        echo "OK (blade, comments-only diff): $path\n";
        exit(0);
    }
    fail("MISMATCH (blade, code changed beyond comments): $path");
} elseif (in_array($ext, $phpExts, true)) {
    $oldTokens = stripPhpToCodeTokens($oldContent);
    $newTokens = stripPhpToCodeTokens($newContent);
    if ($oldTokens === $newTokens) {
        echo "OK (php, comments-only diff): $path\n";
        exit(0);
    }
    fail("MISMATCH (php, code changed beyond comments): $path");
} elseif (in_array($ext, $jsCssExts, true)) {
    $oldNorm = stripJsCssComments($oldContent);
    $newNorm = stripJsCssComments($newContent);
    if ($oldNorm === $newNorm) {
        echo "OK (js/css, comments-only diff): $path\n";
        exit(0);
    }
    fail("MISMATCH (js/css, code changed beyond comments): $path");
} else {
    fail("Unsupported file type for verification: $path");
}
