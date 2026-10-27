<?php
declare(strict_types=1);
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: https:; font-src 'self' https://fonts.gstatic.com; connect-src 'self'; frame-src 'self' about:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Панель управления — Спецпартс49</title><link rel="icon" href="../assets/favicon.svg"><link rel="stylesheet" href="admin.css?v=<?= (int)filemtime(__DIR__.'/admin.css') ?>"><script src="admin.js?v=<?= (int)filemtime(__DIR__.'/admin.js') ?>" defer></script></head><body><div id="app"><div class="boot"><img src="../assets/specparts49-emblem.png" alt=""><p>Загрузка панели управления…</p></div></div><div id="notice" role="status" aria-live="polite"></div><dialog id="modal"></dialog></body></html>
